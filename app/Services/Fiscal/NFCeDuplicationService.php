<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Support\InstanceIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Copies the commercial snapshot of a *definitively rejected* NFC-e 539 into
 * a new fiscal record with a fresh number. No sale, stock or financial write.
 *
 * Not a resend: the rejected original and all signed/SEFAZ files remain intact.
 */
final class NFCeDuplicationService
{
    public function __construct(
        private readonly NFCeSefazResponseParser $responses,
        private readonly NFCeProtocolXmlService $xml,
        private readonly FiscalDocumentSettings $settings,
    ) {}

    public function eligible(FiscalDocumentJob $document): bool
    {
        if ($document->document_type !== 'nfce'
            || !in_array($document->status, ['pending', 'rejected'], true)
            || $document->emission_mode !== 'normal'
            || !in_array($document->environment, ['homologation', 'production'], true)
            || !$document->sale_id
            || $document->protocol || $document->authorized_at
            || $document->cancelled_at || $document->cancellation_status
            || preg_match('/^\d{44}$/', (string) $document->access_key) !== 1
            || FiscalDocumentJob::query()->where('duplicated_from_id', $document->id)->exists()
        ) {
            return false;
        }

        $disk = Storage::disk('local');
        $original = 'fiscal/nfce/'.$document->id.'/sefaz-response.ini';
        if (!$disk->exists($original)) {
            return false;
        }
        try {
            $data = $this->responses->parse($disk->get($original));
            // In the 539 response, SEFAZ identifies the *other* key already
            // occupying the same series and number. A failed consultation
            // (e.g. 613) alone is NEVER sufficient for duplication.
            $reason = (string) ($data['reason'] ?? '');
            if ((string) ($data['cstat'] ?? '') !== '539'
                || !preg_match('/duplicidade/i', $reason)
                || !preg_match('/chNFe:\s*(\d{44})/i', $reason, $match)
                || hash_equals((string) $document->access_key, $match[1])
            ) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public function duplicate(int $id): FiscalDocumentJob
    {
        $lock = Cache::lock('nextor:nfce:'.$id, 300);
        if (!$lock->get()) {
            $this->fail('Este documento está sendo processado. Aguarde.');
        }

        try {
            return DB::transaction(function () use ($id): FiscalDocumentJob {
                $source = FiscalDocumentJob::query()->lockForUpdate()->findOrFail($id);
                $existing = FiscalDocumentJob::query()->where('duplicated_from_id', $id)->first();
                if ($existing) {
                    return $existing;
                }
                if (!$this->eligible($source)) {
                    $this->fail('Duplicação permitida somente após rejeição 539 comprovada pela SEFAZ.');
                }

                if (!$source->sale || $source->sale->status !== 'completed') {
                    $this->fail('A venda original deve estar concluída.');
                }
                if (FiscalDocumentJob::query()
                    ->where('sale_id', $source->sale_id)
                    ->where('document_type', 'nfce')
                    ->whereIn('status', ['authorized', 'processing', 'offline_signed', 'offline_sending'])
                    ->exists()
                ) {
                    $this->fail('Esta venda já possui nota autorizada ou em processamento.');
                }

                $company = CompanySetting::current();
                if (!InstanceIdentity::matches($company->document)) {
                    $this->fail('O CNPJ da empresa não corresponde ao da nota.');
                }
                if ($this->settings->transmissionIssue('nfce', (string) $source->environment)
                    || $this->settings->environment('nfce') !== $source->environment
                    || (int) AppSetting::value('nfce', 'series', -1) !== (int) $source->series
                ) {
                    $this->fail('Confira a série e o ambiente fiscal nas configurações.');
                }

                $disk = Storage::disk('local');
                $signedPath = 'fiscal/nfce/'.$source->id.'/signed.xml';
                if ($source->xml_path !== $signedPath || !$disk->exists($signedPath)) {
                    $this->fail('XML original não encontrado. Não é seguro duplicar.');
                }

                try {
                    $key = $this->xml->verifySigned($disk->get($signedPath), $source);
                } catch (RuntimeException) {
                    $this->fail('Não foi possível conferir o arquivo da nota.');
                }
                if (!hash_equals((string) $source->access_key, $key)) {
                    $this->fail('Os dados da nota não conferem. Entre em contato com o suporte.');
                }

                $expected = (int) AppSetting::value('nfce', 'next_number', 1);
                if ($expected <= (int) $source->document_number || $expected > 999999999) {
                    $this->fail('Configure o próximo número fiscal para um valor maior que o rejeitado.');
                }

                $number = AppSetting::reserveInteger('nfce', 'next_number', 1);
                if ($number <= (int) $source->document_number
                    || $number > 999999999
                    || FiscalDocumentJob::query()
                        ->where('document_type', 'nfce')
                        ->where('environment', $source->environment)
                        ->where('series', $source->series)
                        ->where('document_number', $number)->exists()
                ) {
                    $this->fail('O próximo número fiscal já está reservado ou é inválido.');
                }

                $snapshot = $source->settings_snapshot ?? [];
                unset($snapshot['csc_token'], $snapshot['municipal_password'],
                    $snapshot['api_key'], $snapshot['api_secret']);

                // This is a separate fiscal document. The rejected original
                // is not modified and retains its original audit trail.
                return FiscalDocumentJob::query()->create([
                    'document_type' => 'nfce',
                    'sale_id' => $source->sale_id,
                    'duplicated_from_id' => $source->id,
                    'status' => 'prepared',
                    'emission_mode' => 'normal',
                    'environment' => $source->environment,
                    'series' => $source->series,
                    'document_number' => $number,
                    'settings_snapshot' => $snapshot,
                    'source_snapshot' => $source->source_snapshot,
                    'prepared_at' => now(),
                ]);
            }, 3);
        } finally {
            $lock->release();
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['nfce' => $message]);
    }
}
