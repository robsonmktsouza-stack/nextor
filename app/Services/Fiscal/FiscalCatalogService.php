<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\FiscalCatalogEntry;
use App\Models\FiscalCatalogRelease;
use App\Support\InstanceIdentity;
use App\Support\FiscalCodeCatalog;
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
    private ?array $benefitDomains = null;

    public function bundledPath(): string
    {
        $definition = $this->bundledDefinition();
        return resource_path('fiscal/catalog/'.$definition['file']);
    }

    public function installBundled(): FiscalCatalogRelease
    {
        $definition = $this->bundledDefinition();
        return $this->importFile($this->bundledPath(), $definition['sha256'] ?? null, $definition['version']);
    }

    private function bundledDefinition(): array
    {
        try {
            $json = file_get_contents(resource_path('fiscal/catalog/current.json'));
            $definition = json_decode($json === false ? '' : $json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->invalid('Identificação do catálogo distribuído inválida.');
        }
        if (!is_array($definition) || !is_string($definition['version'] ?? null)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $definition['version'])
            || ($definition['file'] ?? null) !== $definition['version'].'.json'
            || (isset($definition['sha256']) && (!is_string($definition['sha256']) || !preg_match('/^[a-f0-9]{64}$/', $definition['sha256'])))) {
            $this->invalid('Identificação do catálogo distribuído inválida.');
        }
        return $definition;
    }

    /** The checksum is of the distributed file bytes; the release hash is canonical JSON. */
    public function importFile(string $path, ?string $expectedSha256 = null, ?string $expectedVersion = null): FiscalCatalogRelease
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
        unset($bytes);
        if (!is_array($manifest)) $this->invalid('O catálogo deve conter um objeto JSON.');
        if ($expectedVersion !== null && ($manifest['version'] ?? null) !== $expectedVersion) {
            $this->invalid('A versão do arquivo não corresponde à identificação do catálogo distribuído.');
        }
        return $this->import($manifest);
    }

    public function current(): ?FiscalCatalogRelease
    {
        // Snapshot dates determine precedence; imports reject older revisions.
        return FiscalCatalogRelease::query()->orderByDesc('consulted_at')->orderByDesc('id')->first();
    }

    public function coverage(string $kind, ?string $uf = null, ?string $date = null): array
    {
        $release = $this->current();
        return $release ? FiscalCatalogCoverage::scope($release, $kind, $uf, $date)
            : ['status' => 'not_bundled', 'complete' => false, 'uf' => $uf, 'reason' => 'catalog_not_installed'];
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
        } else {
            $query->whereNull('uf');
        }
        return self::constrainExcludedUfs($query, $uf);
    }

    /** Unknown UF never proves applicability of a territorially excluded record. */
    public static function constrainExcludedUfs(Builder $query, ?string $uf): Builder
    {
        $scope = $uf === null ? null : strtoupper(trim($uf));
        return $query->where(function (Builder $q) use ($scope): void {
            $q->whereNull('parameters->excluded_ufs');
            if ($scope !== null && in_array($scope, self::UFS, true)) {
                $q->orWhereJsonDoesntContain('parameters->excluded_ufs', $scope);
            } else {
                $q->orWhereJsonLength('parameters->excluded_ufs', 0);
            }
        });
    }

    public function import(array $manifest): FiscalCatalogRelease
    {
        [$sources, $rows] = $this->validate($manifest);
        $digest = $this->canonicalDigest($manifest);
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
        foreach ($current->entries()->select(['kind', 'code', 'uf', 'regime'])->lockForUpdate()->cursor() as $entry) {
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
            $this->validateCoverage($coverage, $sources, $manifest['consulted_at']);
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
            $this->validateParameters($entry, $index + 1);
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

    private function validateCoverage(mixed $coverage, array $sources, string $consultedAt, bool $state = false): void
    {
        $status = is_array($coverage) ? ($coverage['status'] ?? null) : $coverage;
        if (!in_array($status, $state ? ['complete', 'partial', 'not_bundled', 'unavailable'] : ['complete', 'partial', 'not_bundled'], true)) {
            $this->invalid('Cobertura deve informar complete, partial ou not_bundled.');
        }
        if (!is_array($coverage)) return;
        if (isset($coverage['scope']) && !in_array($coverage['scope'], ['BR', 'UF'], true)) {
            $this->invalid('Escopo da cobertura deve ser BR ou UF.');
        }
        if (isset($coverage['uf']) && !in_array($coverage['uf'], self::UFS, true)) $this->invalid('UF da cobertura inválida.');
        if (($coverage['scope'] ?? null) === 'BR' && isset($coverage['uf'])) {
            $this->invalid('Cobertura nacional não pode ser restrita a uma única UF.');
        }
        if (isset($coverage['snapshot_date']) && (!$this->date($coverage['snapshot_date']) || $coverage['snapshot_date'] > $consultedAt)) {
            $this->invalid('Data de referência da cobertura inválida ou posterior à consulta.');
        }
        if (isset($coverage['count']) && (!is_int($coverage['count']) || $coverage['count'] < 0)) {
            $this->invalid('Quantidade de registros na cobertura inválida.');
        }
        if (isset($coverage['source_ids'])) {
            if (!is_array($coverage['source_ids']) || !array_is_list($coverage['source_ids'])) $this->invalid('Fontes da cobertura inválidas.');
            foreach ($coverage['source_ids'] as $source) {
                if (!is_string($source) || !isset($sources[$source])) $this->invalid('Fonte da cobertura não encontrada.');
            }
        }
        if (array_key_exists('by_uf', $coverage)) {
            if ($state || !is_array($coverage['by_uf']) || array_is_list($coverage['by_uf']) || !$coverage['by_uf']) {
                $this->invalid('Cobertura por UF deve conter estados identificados.');
            }
            foreach ($coverage['by_uf'] as $uf => $stateCoverage) {
                if (!in_array($uf, self::UFS, true)) $this->invalid('UF desconhecida na cobertura do catálogo.');
                $this->validateCoverage($stateCoverage, $sources, $consultedAt, true);
            }
            if (($coverage['scope'] ?? null) === 'BR' && ($coverage['status'] ?? null) === 'complete') {
                foreach (self::UFS as $uf) {
                    $stateCoverage = $coverage['by_uf'][$uf] ?? null;
                    if ($stateCoverage !== null && (is_array($stateCoverage) ? ($stateCoverage['status'] ?? null) : $stateCoverage) !== 'complete') {
                        $this->invalid('Cobertura nacional completa contradiz a cobertura parcial de uma UF.');
                    }
                }
            }
        }
    }

    private function validateParameters(array $entry, int $index): void
    {
        $parameters = $entry['parameters'] ?? [];
        if (array_key_exists('requires_manual_ncm_review', $parameters) && !is_bool($parameters['requires_manual_ncm_review'])) {
            $this->invalid('Indicador de revisão dos vínculos NCM inválido no registro '.$index.'.');
        }
        if (array_key_exists('excluded_ufs', $parameters)) {
            $this->codeList($parameters['excluded_ufs'], '/^[A-Z]{2}$/D', 'UFs excluídas', $index);
            foreach ($parameters['excluded_ufs'] as $uf) {
                if (!in_array($uf, self::UFS, true)) $this->invalid('UF excluída desconhecida no registro '.$index.'.');
            }
        }
        if (array_key_exists('ncm_prefixes', $parameters)) {
            $this->codeList($parameters['ncm_prefixes'], '/^\d{2,8}$/D', 'vínculos NCM', $index);
        }
        if (isset($parameters['ncm_link_validation']) && !in_array($parameters['ncm_link_validation'], ['manual', 'prefix'], true)) {
            $this->invalid('Validação dos vínculos NCM inválida no registro '.$index.'.');
        }
        if ($entry['kind'] === 'benefit') {
            if (!in_array($entry['uf'] ?? null, self::UFS, true)) $this->invalid('Código de benefício exige UF explícita no registro '.$index.'.');
            foreach (['icms_csts'=>['/^\d{2}$/D', 'icms_cst'], 'csosns'=>['/^\d{3}$/D', 'csosn'], 'crts'=>['/^[1-4]$/D', 'crt']] as $key => [$pattern, $domain]) {
                if (!array_key_exists($key, $parameters)) continue;
                $this->codeList($parameters[$key], $pattern, $key, $index);
                if ($this->benefitDomains === null) {
                    $codes = FiscalCodeCatalog::all();
                    $this->benefitDomains = [];
                    foreach (['icms_cst','csosn','crt'] as $name) $this->benefitDomains[$name] = array_map('strval', array_keys($codes[$name]));
                }
                $known = $this->benefitDomains[$domain];
                foreach ($parameters[$key] as $code) {
                    if (!in_array($code, $known, true)) $this->invalid('Código '.$key.' desconhecido no benefício fiscal '.$index.'.');
                }
            }
        }
        if ($entry['kind'] === 'cfop') {
            foreach (['ind_nfe', 'indNFe'] as $flag) {
                if (array_key_exists($flag, $parameters) && !in_array($parameters[$flag], [true, false, 0, 1, '0', '1'], true)) {
                    $this->invalid('Indicador NF-e do CFOP inválido no registro '.$index.'.');
                }
            }
            if (array_key_exists('ind_nfe', $parameters) && array_key_exists('indNFe', $parameters)
                && (bool) $parameters['ind_nfe'] !== (bool) $parameters['indNFe']) {
                $this->invalid('Indicadores NF-e contraditórios no CFOP '.$entry['code'].'.');
            }
        }
    }

    private function codeList(mixed $codes, string $pattern, string $label, int $index): void
    {
        if (!is_array($codes) || !array_is_list($codes) || count($codes) !== count(array_unique($codes, SORT_REGULAR))) {
            $this->invalid('Lista de '.$label.' inválida no registro '.$index.'.');
        }
        foreach ($codes as $code) {
            if (!is_string($code) || !preg_match($pattern, $code)) $this->invalid('Código em '.$label.' inválido no registro '.$index.'.');
        }
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

    /** Preserve the original canonical JSON hash without copying the entire catalog. */
    private function canonicalDigest(array $manifest): string
    {
        $context = hash_init('sha256');
        $this->writeCanonical($context, $manifest);
        return hash_final($context);
    }

    private function writeCanonical(\HashContext $context, mixed $value, int $depth = 0): void
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (!is_array($value)) {
            hash_update($context, json_encode($value, $flags, max(1, 512 - $depth)));
            return;
        }
        if ($depth >= 512) throw new \JsonException('Maximum stack depth exceeded', JSON_ERROR_DEPTH);
        if (array_is_list($value)) {
            hash_update($context, '[');
            $first = true;
            foreach ($value as $item) {
                if (!$first) hash_update($context, ',');
                $this->writeCanonical($context, $item, $depth + 1);
                $first = false;
            }
            hash_update($context, ']');
            return;
        }
        // Sorting keys mirrors ksort(SORT_REGULAR) while leaving values shared.
        // A non-list can become a list after sorting (for example [1=>b,0=>a]).
        $keys = array_keys($value);
        sort($keys, SORT_REGULAR);
        $list = true;
        foreach ($keys as $index => $key) if ($key !== $index) { $list = false; break; }
        hash_update($context, $list ? '[' : '{');
        $first = true;
        foreach ($keys as $key) {
            if (!$first) hash_update($context, ',');
            if (!$list) {
                hash_update($context, json_encode((string) $key, $flags));
                hash_update($context, ':');
            }
            $this->writeCanonical($context, $value[$key], $depth + 1);
            $first = false;
        }
        hash_update($context, $list ? ']' : '}');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['catalog' => $message]);
    }
}
