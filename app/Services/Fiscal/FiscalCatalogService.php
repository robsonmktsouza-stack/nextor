<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\FiscalCatalogEntry;
use App\Models\FiscalCatalogRelease;
use App\Support\InstanceIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stores reviewed reference snapshots. Imports never mutate products, approved
 * rules, company settings, documents or the original snapshots of prior releases.
 * An official source URL describes provenance, not approval of a tax treatment.
 */
final class FiscalCatalogService
{
    public const SCHEMA_VERSION = 1;
    public const MAX_BYTES = 33554432;
    public const KINDS = ['cfop', 'icms_cst', 'csosn', 'pis_cofins_cst', 'ipi_cst', 'origin', 'crt',
        'ncm', 'cest', 'ibs_cbs_cst', 'ibs_cbs_class', 'benefit', 'operation_nature', 'tax_group', 'tax_parameter'];
    private const UFS = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB',
        'PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

    public function bundledPath(): string
    {
        return resource_path('fiscal/catalog/2026.10.26.json');
    }

    public function installBundled(): FiscalCatalogRelease
    {
        return $this->importFile($this->bundledPath());
    }

    /** The checksum is of the distributed file bytes; the release hash is canonical JSON. */
    public function importFile(string $path, ?string $expectedSha256 = null): FiscalCatalogRelease
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) > self::MAX_BYTES) {
            $this->invalid('Arquivo do catálogo ausente, ilegível ou maior que 32 MiB.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) > self::MAX_BYTES) {
            $this->invalid('Não foi possível ler o catálogo fiscal.');
        }
        if ($expectedSha256 !== null && (!preg_match('/^[a-f0-9]{64}$/i', $expectedSha256)
                || !hash_equals(strtolower($expectedSha256), hash('sha256', $bytes)))) {
            $this->invalid('O SHA-256 não corresponde ao arquivo aprovado.');
        }
        try {
            $manifest = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->invalid('JSON do catálogo inválido.');
        }
        if (!is_array($manifest)) $this->invalid('O catálogo deve conter um objeto JSON.');
        return $this->import($manifest);
    }

    public function current(): ?FiscalCatalogRelease
    {
        // Snapshot dates determine precedence; imports reject older revisions.
        return FiscalCatalogRelease::query()->orderByDesc('consulted_at')->orderByDesc('id')->first();
    }

    public function entries(string $kind, ?string $date = null, ?string $uf = null): Builder
    {
        $day = $date ?? now()->toDateString();
        if (!$this->date($day)) $this->invalid('Data da consulta fiscal inválida.');
        $query = FiscalCatalogEntry::query()->where('release_id', $this->current()?->id ?? 0)
            ->where('kind', $kind)->where('status', 'reference')
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day));
        if ($uf !== null) {
            $uf = strtoupper($uf);
            if (!in_array($uf, self::UFS, true)) $this->invalid('UF do catálogo inválida.');
            $query->where(fn (Builder $q) => $q->whereNull('uf')->orWhere('uf', $uf));
        }
        return $query;
    }

    public function import(array $manifest): FiscalCatalogRelease
    {
        [$sources, $rows] = $this->validate($manifest);
        $digest = hash('sha256', json_encode($this->canonical($manifest), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        try {
            return DB::transaction(function () use ($manifest, $sources, $rows, $digest): FiscalCatalogRelease {
                // Per-database mutex also serializes the very first import.
                // Do not borrow or change a company's configuration for locking.
                if (!DB::table('fiscal_catalog_locks')->where('id', 1)->lockForUpdate()->first()) {
                    $this->invalid('Bloqueio do catálogo ausente. Confira as migrações da instalação.');
                }
                $existing = FiscalCatalogRelease::query()->where('version', $manifest['version'])->lockForUpdate()->first();
                if ($existing) return $this->sameRelease($existing, $digest);
                // Locking reads see the committed predecessor even inside an
                // outer MySQL REPEATABLE READ transaction with an older snapshot.
                $current = FiscalCatalogRelease::query()->orderByDesc('consulted_at')->orderByDesc('id')->lockForUpdate()->first();
                if ($current && $manifest['consulted_at'] < $current->consulted_at->format('Y-m-d')) {
                    $this->invalid('Uma atualização não pode substituir o catálogo por uma data de consulta anterior.');
                }
                $this->assertSnapshot($rows, $current);
                $release = FiscalCatalogRelease::query()->create([
                    'version' => $manifest['version'], 'schema_version' => self::SCHEMA_VERSION,
                    'sha256' => $digest, 'consulted_at' => $manifest['consulted_at'],
                    'sources' => array_values($sources), 'coverage' => $manifest['coverage'],
                    'entry_count' => count($rows), 'installed_at' => now(),
                ]);
                $timestamp = now()->toDateTimeString();
                foreach (array_chunk($rows, 300) as $chunk) {
                    $insert = [];
                    foreach ($chunk as $row) {
                        $insert[] = [...$row, 'release_id' => $release->id,
                            'source' => json_encode($row['source'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                            'conditions' => json_encode($row['conditions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                            'parameters' => json_encode($row['parameters'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                            'created_at' => $timestamp, 'updated_at' => $timestamp];
                    }
                    FiscalCatalogEntry::query()->insert($insert);
                }
                return $release;
            }, 3);
        } catch (QueryException $exception) {
            // Concurrent idempotent imports converge at the unique release key.
            $existing = FiscalCatalogRelease::query()->where('version', $manifest['version'])->first();
            if ($existing) return $this->sameRelease($existing, $digest);
            throw $exception;
        }
    }

    private function sameRelease(FiscalCatalogRelease $release, string $digest): FiscalCatalogRelease
    {
        if (!hash_equals($release->sha256, $digest)) {
            $this->invalid('Esta versão já está instalada com conteúdo diferente. Publique uma nova versão; o histórico não será sobrescrito.');
        }
        return $release;
    }

    private function assertSnapshot(array $rows, ?FiscalCatalogRelease $current): void
    {
        if (!$current) return;
        $codes = [];
        foreach ($rows as $row) {
            $codes[json_encode([$row['kind'], $row['code'], $row['uf'], $row['regime']], JSON_THROW_ON_ERROR)] = true;
        }
        foreach ($current->entries()->select(['kind', 'code', 'uf', 'regime'])->lockForUpdate()->get() as $entry) {
            $key = json_encode([$entry->kind, $entry->code, $entry->uf, $entry->regime], JSON_THROW_ON_ERROR);
            if (!isset($codes[$key])) {
                $this->invalid('A atualização deve conter o catálogo completo. Preserve códigos anteriores ou informe sua revogação; ausente: '.$entry->kind.' '.$entry->code.'.');
            }
        }
    }

    private function validate(array $manifest): array
    {
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !is_string($manifest['version'] ?? null)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $manifest['version'])) {
            $this->invalid('Formato ou versão de catálogo incompatível.');
        }
        if (!$this->date($manifest['consulted_at'] ?? null) || $manifest['consulted_at'] > now('UTC')->toDateString()) {
            $this->invalid('Informe a data de consulta real do catálogo, sem data futura.');
        }
        if (array_key_exists('target_cnpj', $manifest)) {
            if (!is_string($manifest['target_cnpj'])) $this->invalid('CNPJ de destino do catálogo inválido.');
            $target = preg_replace('/\D/', '', (string) $manifest['target_cnpj']);
            $company = preg_replace('/\D/', '', (string) CompanySetting::query()->value('document'));
            if (strlen($target) !== 14 || !InstanceIdentity::matches($target)
                || $company === '' || !hash_equals($company, $target)) {
                $this->invalid('O catálogo específico pertence a outra empresa ou a instalação não possui CNPJ compatível.');
            }
        }
        if (!is_array($manifest['sources'] ?? null) || !$manifest['sources'] || count($manifest['sources']) > 500
            || !is_array($manifest['coverage'] ?? null) || !$manifest['coverage']
            || !is_array($manifest['entries'] ?? null) || !array_is_list($manifest['entries'])
            || !$manifest['entries'] || count($manifest['entries']) > 100000) {
            $this->invalid('Fontes, cobertura ou registros do catálogo inválidos.');
        }
        $sources = [];
        foreach ($manifest['sources'] as $source) {
            if (!is_array($source) || !is_string($source['id'] ?? null)
                || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $source['id'])
                || isset($sources[$source['id']]) || !$this->officialUrl($source['url'] ?? null)
                || !$this->text($source['reference'] ?? null, 2000)
                || !$this->date($source['consulted_at'] ?? null)
                || $source['consulted_at'] > $manifest['consulted_at']) {
                $this->invalid('Cada fonte deve informar identificação única, URL oficial HTTPS, norma e data de consulta.');
            }
            if (isset($source['version']) && !$this->text($source['version'], 500)) {
                $this->invalid('Versão da fonte fiscal inválida.');
            }
            $sources[$source['id']] = $source;
        }
        foreach ($manifest['coverage'] as $kind => $coverage) {
            if (!in_array($kind, self::KINDS, true)) $this->invalid('Tipo desconhecido na cobertura do catálogo.');
            $status = is_array($coverage) ? ($coverage['status'] ?? null) : $coverage;
            if (!in_array($status, ['complete', 'partial', 'not_bundled'], true)) {
                $this->invalid('Cobertura deve informar complete, partial ou not_bundled.');
            }
        }
        $rows = []; $identities = []; $ranges = [];
        foreach ($manifest['entries'] as $index => $entry) {
            if (!is_array($entry) || !in_array($entry['kind'] ?? null, self::KINDS, true)
                || !$this->text($entry['code'] ?? null, 64) || !$this->text($entry['description'] ?? null, 10000)
                || !is_string($entry['source_id'] ?? null) || !isset($sources[$entry['source_id']])
                || !array_key_exists($entry['kind'], $manifest['coverage'])) {
                $this->invalid('Registro '.($index + 1).' sem tipo, código, descrição, fonte ou cobertura válida.');
            }
            $coverage = $manifest['coverage'][$entry['kind']];
            if ((is_array($coverage) ? $coverage['status'] : $coverage) === 'not_bundled') {
                $this->invalid('Registro '.($index + 1).' contradiz a cobertura declarada como não incluída.');
            }
            $digits = ['cfop'=>4, 'icms_cst'=>2, 'csosn'=>3, 'pis_cofins_cst'=>2, 'ipi_cst'=>2,
                'origin'=>1, 'crt'=>1, 'ncm'=>8, 'cest'=>7, 'ibs_cbs_cst'=>3, 'ibs_cbs_class'=>6];
            if (isset($digits[$entry['kind']]) && !preg_match('/^\d{'.$digits[$entry['kind']].'}$/D', $entry['code'])) {
                $this->invalid('Formato do código '.$entry['kind'].' inválido no registro '.($index + 1).'.');
            }
            $from = $entry['valid_from'] ?? null; $until = $entry['valid_until'] ?? null;
            if (($from !== null && !$this->date($from)) || ($until !== null && !$this->date($until))
                || ($from !== null && $until !== null && $until < $from)) {
                $this->invalid('Vigência inválida no registro '.($index + 1).'.');
            }
            $uf = $entry['uf'] ?? null; $regime = $entry['regime'] ?? null;
            if (($uf !== null && !in_array($uf, self::UFS, true))
                || ($regime !== null && !$this->text($regime, 32))
                || !in_array($entry['status'] ?? 'reference', ['reference', 'template', 'revoked'], true)
                || !is_array($entry['conditions'] ?? []) || !is_array($entry['parameters'] ?? [])) {
                $this->invalid('Escopo, situação ou parâmetros inválidos no registro '.($index + 1).'.');
            }
            foreach (($entry['conditions'] ?? []) as $condition) {
                if (!$this->text($condition, 3000)) $this->invalid('Condição de aplicação inválida.');
            }
            $this->assertNoFloat($entry['parameters'] ?? []);
            $scope = json_encode([$entry['kind'], $entry['code'], $uf, $regime], JSON_THROW_ON_ERROR);
            $identity = hash('sha256', $scope.'|'.($from ?? ''));
            if (isset($identities[$identity])) $this->invalid('Código e vigência duplicados no mesmo escopo fiscal.');
            foreach ($ranges[$scope] ?? [] as [$otherFrom, $otherUntil]) {
                if (($until ?? '9999-12-31') >= ($otherFrom ?? '0001-01-01')
                    && ($otherUntil ?? '9999-12-31') >= ($from ?? '0001-01-01')) {
                    $this->invalid('Vigências sobrepostas para o mesmo código fiscal.');
                }
            }
            $ranges[$scope][] = [$from, $until]; $identities[$identity] = true;
            $rows[] = [
                'kind' => $entry['kind'], 'code' => $entry['code'], 'description' => $entry['description'],
                'valid_from' => $from, 'valid_until' => $until, 'uf' => $uf, 'regime' => $regime,
                'status' => $entry['status'] ?? 'reference', 'source' => $sources[$entry['source_id']],
                'conditions' => $entry['conditions'] ?? [], 'parameters' => $entry['parameters'] ?? [], 'entry_key' => $identity,
            ];
        }
        return [$sources, $rows];
    }

    private function officialUrl(mixed $url): bool
    {
        if (!is_string($url) || strlen($url) > 4000 || filter_var($url, FILTER_VALIDATE_URL) === false) return false;
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) return false;
        $host = strtolower($parts['host'] ?? '');
        return str_ends_with($host, '.gov.br') || str_ends_with($host, '.leg.br')
            || $host === 'acbr.sourceforge.io';
    }

    private function date(mixed $date): bool
    {
        if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) return false;
        return (int) $parts[1] >= 1900 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function text(mixed $value, int $max): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= $max
            && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value);
    }

    private function assertNoFloat(array $parameters, int $depth = 0): void
    {
        if ($depth > 20) $this->invalid('Parâmetros do catálogo muito profundos.');
        foreach ($parameters as $value) {
            if (is_float($value)) $this->invalid('Valores decimais do catálogo devem ser strings, sem ponto flutuante.');
            if (is_array($value)) $this->assertNoFloat($value, $depth + 1);
            elseif (!is_scalar($value) && $value !== null) $this->invalid('Parâmetro fiscal inválido.');
        }
    }

    private function canonical(array $value): array
    {
        if (!array_is_list($value)) ksort($value);
        foreach ($value as &$item) if (is_array($item)) $item = $this->canonical($item);
        return $value;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['catalog' => $message]);
    }
}
