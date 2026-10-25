<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Support\InstanceIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Recovers only the archived, definitive 486. Never contacts SEFAZ. */
final class Nfe55RejectionRecoveryService
{
    public function __construct(private readonly NFCeSefazResponseParser $responses) {}

    public function recover486(NfeDraft $draft): NfeDraft
    {
        return $this->locked($draft, false);
    }

    /** Explicit correction preserves the original fiscal identity and evidence. */
    public function prepareCorrection486(NfeDraft $draft): NfeDraft
    {
        return $this->locked($draft, true);
    }

    private function locked(NfeDraft $draft, bool $prepare): NfeDraft
    {
        $lock = Cache::lock('nfe-55:'.$draft->id, 300);
        if (!$lock->get()) $this->refuse();
        try {
            return DB::transaction(function () use ($draft, $prepare) {
                $note = NfeDraft::query()->lockForUpdate()->findOrFail($draft->id);
                $company = CompanySetting::query()->lockForUpdate()->findOrFail(CompanySetting::current()->id);
                $base = 'fiscal/nfe55/'.$note->id;
                $disk = Storage::disk('local');
                if (!in_array($note->status, ['pending', 'rejected'], true)
                    || !$note->transmission_attempted_at || $note->protocol !== null
                    || $note->authorized_at !== null || $note->cancelled_at !== null
                    || $note->cancellation_status !== null
                    || !InstanceIdentity::matches($company->document)
                    || !$note->document_number || !$note->numeric_code
                    || $note->xml_path !== $base.'/signed.xml'
                    || $note->response_path !== $base.'/sefaz-response.ini'
                    || !$disk->exists($note->xml_path) || !$disk->exists($note->response_path)
                    || $disk->exists($base.'/authorized.xml')) $this->refuse();
                if ($disk->exists($base.'/consult-response.ini')
                    && !$this->consultationDoesNotConflict($disk->get($base.'/consult-response.ini'), $note)) $this->refuse();
                $raw = $disk->get($note->response_path);
                if (!$this->responses->definitiveNfe55Rejection486($raw, (string) $note->access_key, (string) $note->environment)) $this->refuse();
                $signed = $disk->get($note->xml_path);
                try {
                    $key = app(Nfe55EmissionService::class)->verifySignedXml($signed, $note, $company);
                } catch (\Throwable) {
                    $this->refuse();
                }
                if (!hash_equals((string) $note->access_key, $key)) $this->refuse();
                $fingerprint = (string) data_get($note->custom_fields, '_reserved_fiscal_fingerprint', '');
                if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)
                    || !hash_equals($fingerprint, app(Nfe55DocumentSnapshot::class)->materialFingerprint($note, $company))) $this->refuse();
                // Content-addressed copies are immutable across retries and are
                // committed before any send eligibility can be changed.
                $archive = $base.'/attempts/rejection-486-'.hash('sha256', $signed."\0".$raw);
                $this->preserve($archive.'/signed.xml', $signed);
                $this->preserve($archive.'/sefaz-response.ini', $raw);
                if ($disk->exists($base.'/consult-response.ini')) {
                    $this->preserve($archive.'/consult-response.ini', $disk->get($base.'/consult-response.ini'));
                }
                $this->preserve($archive.'/attempt.json', json_encode([
                    'draft_id' => $note->id, 'key' => $key, 'environment' => $note->environment,
                    'number' => $note->document_number, 'numeric_code' => $note->numeric_code,
                    'transmission_attempted_at' => $note->transmission_attempted_at->toIso8601String(),
                    'reserved_fiscal_fingerprint' => $fingerprint,
                ], JSON_THROW_ON_ERROR));
                if ($prepare) {
                    // The builder must validate the newly configured autXML.
                    // No tax, item, recipient or issuer mutation is accepted.
                    app(Nfe55AuthorizedXmlService::class)->assertRequired($note, $company);
                    app(Nfe55IniBuilder::class)->build($note, $company, (int) $note->document_number, (int) $note->numeric_code);
                    $note->update(['status' => 'prepared', 'transmission_attempted_at' => null, 'error_message' => null]);
                } else {
                    $note->update(['status' => 'rejected', 'error_message' => 'Retorno da SEFAZ: 486 - '.$this->responses->parse($raw)['reason']]);
                }
                return $note->refresh();
            }, 3);
        } finally {
            $lock->release();
        }
    }

    private function preserve(string $path, string $content): void
    {
        $disk = Storage::disk('local');
        if ($disk->exists($path)) {
            if (!hash_equals(hash('sha256', $content), hash('sha256', $disk->get($path)))) $this->refuse();
        } elseif (!$disk->put($path, $content)) $this->refuse();
    }

    /** A later 217 is supporting evidence only; it never replaces the send rejection. */
    private function consultationDoesNotConflict(string $raw, NfeDraft $note): bool
    {
        if (strlen($raw) > 8388608 || preg_match('/<!DOCTYPE|<!ENTITY/i', $raw)) return false;
        $sections = @parse_ini_string($raw, true, INI_SCANNER_RAW);
        if (!is_array($sections) || count($sections) !== 1) return false;
        if (preg_match_all('/^\s*\[[^\]\r\n]+\]\s*$/m', $raw) !== 1
            || preg_match_all('/^\s*CStat\s*=/im', $raw) !== 1) return false;
        $seen = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
            if (!preg_match('/^\s*([A-Za-z][A-Za-z0-9_]*)\s*=/', $line, $match)) continue;
            $field = strtolower($match[1]);
            if (isset($seen[$field])) return false;
            $seen[$field] = true;
        }
        $name = array_key_first($sections);
        if (strtoupper((string) $name) !== 'CONSULTA' || !is_array($sections[$name])) return false;
        $values = array_change_key_case($sections[$name], CASE_LOWER);
        if ((string) ($values['cstat'] ?? '') !== '217') return false;
        foreach (['chdfe', 'chnfe'] as $field) {
            if (isset($values[$field]) && !hash_equals((string) $note->access_key, (string) $values[$field])) return false;
        }
        if (isset($values['tpamb']) && (string) $values['tpamb'] !== ($note->environment === 'homologation' ? '2' : '1')) return false;
        foreach ($values as $field => $value) {
            if (in_array($field, ['nprot', 'nrec'], true) && (string) $value !== '') return false;
            if ((str_contains($field, 'xml') || str_contains($field, 'evento')) && (string) $value !== '') return false;
        }
        return true;
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['nfe' => 'Não foi possível comprovar a rejeição 486 desta NF-e. Preserve a nota e consulte a situação na SEFAZ antes de prosseguir.']);
    }
}
