<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Models\NFCeRenumberHistory;
use App\Support\InstanceIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class NFCeRenumberService
{
    public function __construct(
        private readonly NFCeSefazResponseParser $responses,
        private readonly NFCeProtocolXmlService $xml,
        private readonly FiscalDocumentSettings $settings,
    ) {}

    /**
     * Only a definitive, individually rejected 539 response qualifies.
     * A pending timeout or unsuccessful key consultation is NOT proof of rejection.
     */
    public function eligible(FiscalDocumentJob $job): bool
    {
        if ($job->document_type !== 'nfce'
            || $job->environment !== 'homologation'
            || $job->emission_mode !== 'normal'
            || $job->status !== 'pending'
            || $job->protocol || $job->authorized_at
            || $job->cancelled_at || $job->cancellation_status
            || preg_match('/^\d{44}$/', (string) $job->access_key) !== 1
        ) {
            return false;
        }

        $originalResponse = 'fiscal/nfce/'.$job->id.'/sefaz-response.ini';
        $disk = Storage::disk('local');
        if (!$disk->exists($originalResponse)) {
            return false;
        }

        try {
            $result = $this->responses->parse($disk->get($originalResponse));
        } catch (Throwable) {
            return false;
        }

        return $result['individual'] === true
            && (string) $result['cstat'] === '539'
            && ($result['key'] === null || hash_equals((string) $job->access_key, (string) $result['key']));
    }

    /**
     * Creates a new fiscal attempt on the SAME sale/document record.
     * Original signed XML and SEFAZ responses remain in private immutable history.
     * This operation NEVER transmits a document or touches stock / finance.
     */
    public function prepareWithNextNumber(int $id): FiscalDocumentJob
    {
        $lock = Cache::lock('nextor:nfce:'.$id, 300);
        if (!$lock->get()) {
            throw ValidationException::withMessages([
                'nfce' => 'Documento fiscal em processamento. Aguarde e tente novamente.',
            ]);
        }

        try {
            return DB::transaction(function () use ($id): FiscalDocumentJob {
                $job = FiscalDocumentJob::query()->lockForUpdate()->findOrFail($id);
                if (!$this->eligible($job)) {
                    $this->fail('Somente NFC-e pendente com rejeição 539 comprovada pode ter a numeração corrigida.');
                }

                if (!$job->sale_id || $job->sale?->status !== 'completed') {
                    $this->fail('A venda original precisa estar concluída e vinculada à NFC-e.');
                }

                $company = CompanySetting::current();
                if (!InstanceIdentity::matches($company->document)) {
                    $this->fail('O CNPJ configurado não corresponde à instalação.');
                }

                if ($this->settings->transmissionIssue('nfce', 'homologation') !== null
                    || $this->settings->environment('nfce') !== 'homologation'
                    || (int) AppSetting::value('nfce', 'series', -1) !== (int) $job->series
                ) {
                    $this->fail('Mantenha a NFC-e habilitada na mesma série e em homologação.');
                }

                $disk = Storage::disk('local');
                $base = 'fiscal/nfce/'.$job->id;
                $signed = $base.'/signed.xml';
                $originalResponse = $base.'/sefaz-response.ini';
                $consult = $base.'/consult-response.ini';

                if ($job->xml_path !== $signed || !$disk->exists($signed)
                    || !$disk->exists($originalResponse)
                ) {
                    $this->fail('XML assinado ou retorno original indisponível. Não é seguro reemitir.');
                }

                try {
                    $signedKey = $this->xml->verifySigned($disk->get($signed), $job);
                } catch (RuntimeException) {
                    $this->fail('O XML anterior não passou na conferência de integridade.');
                }

                if (!hash_equals((string) $job->access_key, $signedKey)) {
                    $this->fail('A chave do XML anterior não corresponde ao documento.');
                }

                $expected = (int) AppSetting::value('nfce', 'next_number', 1);
                if ($expected <= (int) $job->document_number || $expected > 999999999) {
                    $this->fail('Ajuste o próximo número de NFC-e para um valor superior ao número rejeitado.');
                }

                // The sequence is reserved under database lock by AppSetting.
                $newNumber = AppSetting::reserveInteger('nfce', 'next_number', 1);
                if ($newNumber <= (int) $job->document_number || $newNumber > 999999999
                    || FiscalDocumentJob::query()
                        ->where('id', '!=', $job->id)
                        ->where('document_type', 'nfce')
                        ->where('environment', $job->environment)
                        ->where('series', $job->series)
                        ->where('document_number', $newNumber)
                        ->exists()
                ) {
                    $this->fail('Numeração já utilizada localmente ou inválida. Corrija o próximo número.');
                }

                // Preserve all previous evidence BEFORE the next transmission
                // overwrites signed.xml and sefaz-response.ini.
                $archive = $base.'/renumber-history/'.bin2hex(random_bytes(12));
                $signedArchive = $archive.'/signed.xml';
                $responseArchive = $archive.'/sefaz-response.ini';
                $consultArchive = $disk->exists($consult) ? $archive.'/consult-response.ini' : null;

                try {
                    $copied = $disk->copy($signed, $signedArchive)
                        && $disk->copy($originalResponse, $responseArchive)
                        && (!$consultArchive || $disk->copy($consult, $consultArchive));
                } catch (Throwable) {
                    $copied = false;
                }
                if (!$copied) {
                    $this->fail('Não foi possível preservar os arquivos da tentativa anterior.');
                }

                NFCeRenumberHistory::query()->create([
                    'fiscal_document_job_id' => $job->id,
                    'old_series' => $job->series,
                    'old_number' => $job->document_number,
                    'old_access_key' => $job->access_key,
                    'old_signed_xml_path' => $signedArchive,
                    'old_sefaz_response_path' => $responseArchive,
                    'old_consult_response_path' => $consultArchive,
                    'old_error_message' => $job->error_message,
                    'new_number' => $newNumber,
                ]);

                $job->update([
                    'status' => 'prepared',
                    'document_number' => $newNumber,
                    'access_key' => null,
                    'protocol' => null,
                    'authorized_at' => null,
                    'xml_path' => null,
                    'response_path' => null,
                    'error_message' => null,
                    'prepared_at' => now(),
                    'processed_at' => null,
                ]);

                return $job->refresh();
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
