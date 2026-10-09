<?php

namespace App\Jobs;

use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\NFCeDanfeService;
use App\Services\Fiscal\NFCePdfService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Only the fiscal queue worker can use the native ACBr library.
 * Rendering from an already authorized XML is read-only; never retransmit.
 */
final class GenerateNFCeDanfePdfJob implements ShouldQueue
{
    use Queueable;

    public int $tries=1;
    public int $timeout=120;
    public bool $failOnTimeout=true;

    public function __construct(public readonly int $fiscalDocumentJobId)
    {
        $this->onQueue('fiscal');
    }

    public function handle(NFCePdfService $pdf, NFCeDanfeService $danfe): void
    {
        $id=$this->fiscalDocumentJobId;
        $lock=Cache::lock('nextor:nfce-pdf-generate:'.$id,180);
        if (!$lock->get()) {
            return;
        }

        try {
            $doc=FiscalDocumentJob::query()->findOrFail($id);
            if ($doc->document_type!=='nfce' || $doc->status!=='authorized') {
                return;
            }

            $disk=Storage::disk('local');
            $path=(string)$doc->xml_path;
            $allowed=[
                'fiscal/nfce/'.$id.'/authorized.xml',
                'fiscal/nfce/'.$id.'/authorized-recovered.xml',
            ];
            if (!in_array($path,$allowed,true) || !$disk->exists($path)) {
                return;
            }

            $xml=$disk->get($path);
            $danfe->parse($xml,$doc);
            $pdf->generate($doc,$xml);
            Cache::forget('nextor:nfce-pdf-error:'.$id);
        } catch (Throwable $error) {
            Log::error('Falha ao gerar DANFE NFC-e no worker fiscal',[
                'fiscal_document_job_id'=>$id,
                'reason'=>$error->getMessage(),
            ]);
            Cache::put('nextor:nfce-pdf-error:'.$id,true,300);
            // The note is still authorized, regardless of report-generation errors.
        } finally {
            $lock->release();
            Cache::forget('nextor:nfce-pdf-request:'.$id);
        }
    }
}
