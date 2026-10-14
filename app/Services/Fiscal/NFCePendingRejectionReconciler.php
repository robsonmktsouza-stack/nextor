<?php

namespace App\Services\Fiscal;

use App\Models\FiscalDocumentJob;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Recover a definitive SEFAZ rejection recorded by an older queue worker.
 * Never calls SEFAZ, signs, issues or changes the sale or financial ledger.
 * Indeterminate responses remain pending.
 */
final class NFCePendingRejectionReconciler
{
    public function __construct(
        private readonly NFCeSefazResponseParser $responses,
        private readonly NFCeProtocolXmlService $xml,
    ) {}

    public function reconcile(FiscalDocumentJob $document): bool
    {
        if ($document->document_type !== 'nfce'
            || $document->status !== 'pending'
            || $document->emission_mode !== 'normal'
            || !$document->sale_id
            || $document->protocol || $document->authorized_at
            || $document->cancelled_at
            || preg_match('/^\d{44}$/', (string) $document->access_key) !== 1) {
            return false;
        }

        $base='fiscal/nfce/'.$document->id.'/';
        $signedPath=$base.'signed.xml';
        $responsePath=$base.'sefaz-response.ini';
        if ($document->xml_path !== $signedPath
            || $document->response_path !== $responsePath) {
            return false;
        }

        try {
            $disk=Storage::disk('local');
            if (!$disk->exists($signedPath) || !$disk->exists($responsePath)) {
                return false;
            }

            // Both the signed document and the original transmission response
            // must agree before any database status is modified.
            $response=$this->responses->parse($disk->get($responsePath));
            if (!$this->responses->definitiveCorrectionRejection($response)
                || ($response['key'] !== null
                    && !hash_equals((string)$document->access_key,(string)$response['key']))) {
                return false;
            }

            $signedKey=$this->xml->verifySigned($disk->get($signedPath),$document);
            if (!hash_equals((string)$document->access_key,$signedKey)) {
                return false;
            }

            return DB::transaction(function () use($document,$signedPath,$responsePath,$signedKey) {
                // Lock the sale first to serialize with transmission/correction
                // and avoid a stale MySQL repeatable-read transaction snapshot.
                Sale::query()->whereKey($document->sale_id)->lockForUpdate()->firstOrFail();
                $locked=FiscalDocumentJob::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'pending'
                    || $locked->xml_path !== $signedPath
                    || $locked->response_path !== $responsePath
                    || !hash_equals($signedKey,(string)$locked->access_key)
                    || $locked->protocol || $locked->authorized_at
                    || FiscalDocumentJob::query()
                        ->where('document_type','nfce')
                        ->where('sale_id',$locked->sale_id)
                        ->where('id','!=',$locked->id)
                        ->whereIn('status',['authorized','processing','offline_signed','offline_sending'])
                        ->exists()) {
                    return false;
                }
                $locked->update(['status'=>'rejected']);
                return true;
            }, 3);
        } catch (Throwable $exception) {
            Log::warning('NFC-e: conciliação automática sem confirmação',[
                'fiscal_document_job_id'=>$document->id,
                'exception'=>get_class($exception),
            ]);
            return false;
        }
    }
}
