<?php

namespace App\Services\Fiscal;

use App\Models\FiscalCatalogEntry;
use App\Models\FiscalCatalogRelease;
use App\Models\FiscalTaxGroup;
use App\Models\Product;
use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Code validation never determines an item's legal tax treatment. */
final class FiscalProductClassificationService
{
    public function __construct(private readonly FiscalCatalogService $catalog) {}

    /** Preserve unchanged legacy classifications during ordinary product maintenance. */
    public function validate(array $data, ?Product $existing = null, ?string $date = null): array
    {
        $errors = [];
        foreach (['ncm' => 8, 'cest' => 7] as $field => $length) {
            if (!array_key_exists($field, $data)) continue;
            if ($data[$field] !== null && !is_string($data[$field]) && !is_int($data[$field])) {
                $errors[$field] = strtoupper($field).' deve ser um código textual.';
                continue;
            }
            $raw = trim((string) ($data[$field] ?? ''));
            if ($existing && $raw === trim((string) $existing->{$field})) continue;
            if ($raw === '') {
                $data[$field] = null;
                continue;
            }
            if (!preg_match('/^[0-9.\s-]+$/', $raw)
                || strlen($digits = preg_replace('/\D/', '', $raw)) !== $length) {
                $errors[$field] = strtoupper($field).' deve conter '.$length.' dígitos.';
                continue;
            }
            $data[$field] = $digits;
        }
        if ($errors) throw ValidationException::withMessages($errors);

        $changed = !$existing || $this->changed($data, $existing, 'ncm') || $this->changed($data, $existing, 'cest');
        if ($changed) $this->assertCodes(array_replace($existing?->only(['ncm','cest']) ?? [], $data), $date, false);

        $groupId = (int) ($data['fiscal_tax_group_id'] ?? 0);
        if ($groupId && (!$existing || $groupId !== (int) $existing->fiscal_tax_group_id)) {
            $group = FiscalTaxGroup::query()->find($groupId);
            if ($group && $group->catalog_version
                && (!$group->is_active || $group->catalog_status !== 'reviewed' || !$group->fiscal_reviewed_at)) {
                throw ValidationException::withMessages([
                    'fiscal_tax_group_id' => 'Revise e habilite o modelo do catálogo antes de associá-lo ao produto.',
                ]);
            }
        }

        return $data;
    }

    /** Called by the existing NF-e determination service for catalog-managed items only. */
    public function assertForEmission(array $item, ?string $date = null, ?string $uf = null): void
    {
        try {
            foreach (['ncm' => 8, 'cest' => 7] as $field => $length) {
                $raw = trim((string) ($item[$field] ?? ''));
                if ($field === 'cest' && $raw === '') continue;
                if (!preg_match('/^[0-9.\s-]+$/', $raw)
                    || strlen(preg_replace('/\D/', '', $raw)) !== $length) {
                    throw new RuntimeException(strtoupper($field).' deve conter '.$length.' dígitos.');
                }
            }
            $this->assertCodes($item, $date, true, $uf);
        } catch (ValidationException $e) {
            throw new RuntimeException(implode(' ', array_merge(...array_values($e->errors()))), 0, $e);
        }
    }

    /** Immutable classification evidence copied into the item's existing fiscal trace. */
    public function trace(array $item, ?string $date = null, ?string $uf = null): array
    {
        $release = $this->catalog->current();
        if (!$release) throw new RuntimeException('Instale o catálogo fiscal antes de registrar as classificações.');
        $entries = [];
        foreach (['ncm','cest'] as $kind) {
            $code = preg_replace('/\D/', '', (string) ($item[$kind] ?? ''));
            if ($code === '') continue;
            $entry = $this->query($release, $kind, $date, $uf)->where('code', $code)->first();
            $entry?->setRelation('release', $release);
            $entries[$kind] = ['code'=>$code, 'reference'=>$entry ? [
                ...$entry->trace(), 'parameters'=>$entry->parameters,
            ] : null];
        }
        return ['version'=>$release->version, 'sha256'=>$release->sha256,
            'uf'=>$this->scopeUf($uf), 'entries'=>$entries];
    }

    /** Small search result for the existing product form; no NCM-to-tax inference. */
    public function search(string $kind, string $term, ?string $ncm = null, ?string $date = null): array
    {
        if (!in_array($kind, ['ncm', 'cest'], true) || mb_strlen(trim($term)) < 2) return [];
        $release = $this->catalog->current();
        if (!$release) return [];
        $term = mb_substr(trim($term), 0, 80);
        $digits = preg_replace('/\D/', '', $term);
        $query = $this->query($release, $kind, $date)->where(function (Builder $q) use ($digits, $term) {
            if ($digits !== '' && preg_match('/^[0-9.\s-]+$/', $term)) {
                $q->where('code', 'like', $digits.'%');
            } else {
                $q->where('description', 'like', '%'.$term.'%');
            }
        })->orderBy('code');

        $ncm = preg_replace('/\D/', '', (string) $ncm);
        return $query->limit(500)->get()->filter(function (FiscalCatalogEntry $entry) use ($kind, $ncm) {
            return $kind !== 'cest' || strlen($ncm) !== 8 || $this->matchesNcm($entry, $ncm);
        })->take(30)->map(fn (FiscalCatalogEntry $entry) => [
            'code' => $entry->code,
            'description' => $entry->description,
            'version' => $release->version,
        ])->values()->all();
    }

    private function changed(array $data, Product $existing, string $field): bool
    {
        return array_key_exists($field, $data)
            && trim((string) $data[$field]) !== trim((string) $existing->{$field});
    }

    private function assertCodes(array $data, ?string $date, bool $emission, ?string $uf = null): void
    {
        $release = $this->catalog->current();
        if (!$release) {
            if ($emission) throw new RuntimeException('Instale o catálogo fiscal antes de aplicar as regras do catálogo.');
            return;
        }
        $errors = [];
        $ncm = preg_replace('/\D/', '', (string) ($data['ncm'] ?? ''));
        $cest = preg_replace('/\D/', '', (string) ($data['cest'] ?? ''));
        foreach (['ncm' => $ncm, 'cest' => $cest] as $kind => $code) {
            if ($code === '') continue;
            $entry = $this->query($release, $kind, $date, $uf)->where('code', $code)->first();
            $known = !$entry && $this->scopedQuery($release, $kind, $uf)->where('code', $code)->exists();
            if (!$entry && ($emission || $known || $this->complete($release, $kind, $date, $uf))) {
                $errors[$kind] = $emission && !$known
                    ? strtoupper($kind).' sem referência oficial vigente para aplicação automática no catálogo '.$release->version.'.'
                    : strtoupper($kind).' não consta como vigente no catálogo '.$release->version.'.';
            }
            if ($kind === 'cest' && $entry && $ncm !== '' && !$this->matchesNcm($entry, $ncm)) {
                $errors['cest'] = 'O CEST informado não corresponde ao NCM nos vínculos oficiais do catálogo. Confira também a descrição legal da mercadoria.';
            }
            if ($kind === 'cest' && $entry && $emission && ($entry->parameters['requires_manual_ncm_review'] ?? false) === true) {
                $errors['cest'] = 'O vínculo NCM deste CEST não está comprovado no catálogo. A descrição legal exige revisão fiscal e uma correspondência validada antes da emissão automática.';
            }
        }
        if ($errors) throw ValidationException::withMessages($errors);
    }

    private function query(FiscalCatalogRelease $release, string $kind, ?string $date, ?string $uf = null): Builder
    {
        $day = $date ?? now()->toDateString();
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $day) : false;
        if (!$parsed || $parsed->format('Y-m-d') !== $day) {
            throw ValidationException::withMessages(['ncm'=>'Data da classificação fiscal inválida.']);
        }
        return $this->scopedQuery($release, $kind, $uf)->where('status', 'reference')
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $day));
    }

    private function scopedQuery(FiscalCatalogRelease $release, string $kind, ?string $uf = null): Builder
    {
        $scope = $this->scopeUf($uf);
        $query = FiscalCatalogEntry::query()->where('release_id', $release->id)->where('kind', $kind);
        $nationalDomain = $kind === 'cest' && FiscalCatalogCoverage::national($release, $kind);
        $query->where(function (Builder $q) use ($scope, $kind, $release, $nationalDomain): void {
            $q->whereNull('uf')->orWhere(function (Builder $state) use ($scope, $kind, $release, $nationalDomain): void {
                $state->where('uf', $scope);
                if ($kind === 'cest') {
                    $state->where(function (Builder $allowed) use ($release, $scope, $nationalDomain): void {
                        // Only an explicit territorial exception can supplement
                        // a complete national domain. Retained ST schedules do not.
                        $allowed->whereExists(function ($national) use ($release, $scope): void {
                            $national->selectRaw('1')->from('fiscal_catalog_entries as national_cest_exception')
                                ->where('national_cest_exception.release_id', $release->id)->where('national_cest_exception.kind', 'cest')
                                ->whereNull('national_cest_exception.uf')->whereColumn('national_cest_exception.code', 'fiscal_catalog_entries.code')
                                ->whereJsonContains('national_cest_exception.parameters->excluded_ufs', $scope);
                        });
                        if (!$nationalDomain) {
                            // A partial state table cannot revive a code already
                            // represented (including revoked) in a national source.
                            $allowed->orWhereNotExists(function ($national) use ($release): void {
                                $national->selectRaw('1')->from('fiscal_catalog_entries as national_cest')
                                    ->where('national_cest.release_id', $release->id)->where('national_cest.kind', 'cest')
                                    ->whereNull('national_cest.uf')->whereColumn('national_cest.code', 'fiscal_catalog_entries.code');
                            });
                        }
                    });
                }
            });
        });
        return FiscalCatalogService::constrainExcludedUfs($query, $scope)
            ->orderByRaw('CASE WHEN uf IS NULL THEN 0 ELSE 1 END');
    }

    private function scopeUf(?string $uf): string
    {
        return strtoupper(trim($uf ?? (string) CompanySetting::query()->value('state')));
    }

    private function complete(FiscalCatalogRelease $release, string $kind, ?string $date = null, ?string $uf = null): bool
    {
        return FiscalCatalogCoverage::scope($release, $kind, $this->scopeUf($uf), $date)['complete'];
    }

    private function matchesNcm(FiscalCatalogEntry $entry, string $ncm): bool
    {
        if (($entry->parameters['ncm_link_validation'] ?? '') === 'manual') return true;
        $prefixes = $entry->parameters['ncm_prefixes'] ?? [];
        // Absent correspondence is not proof of incompatibility, nor proof of ST incidence.
        if (!$prefixes) return true;
        foreach ($prefixes as $prefix) {
            if (is_string($prefix) && preg_match('/^\d{2,8}$/', $prefix) && str_starts_with($ncm, $prefix)) return true;
        }
        return false;
    }
}
