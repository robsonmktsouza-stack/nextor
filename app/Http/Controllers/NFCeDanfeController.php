<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\NFCePdfService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Fiscal\NFCeDanfeService;
use Illuminate\Support\Facades\Storage;

final class NFCeDanfeController extends Controller
{
    public function __invoke(
        Request $request,
        FiscalDocumentJob $fiscalDocumentJob,
        NFCeDanfeService $danfe,
        NFCePdfService $pdf
    ) {
        abort_unless($fiscalDocumentJob->document_type === 'nfce'
            && $fiscalDocumentJob->status === 'authorized', 404);

        // Nunca imprimir dados comerciais de uma venda como documento fiscal.
        // Somente um nfeProc autorizado arquivado no diretório privado.
        $directory = 'fiscal/nfce/'.$fiscalDocumentJob->id.'/';
        $path = (string) $fiscalDocumentJob->xml_path;
        abort_unless(in_array($path, [
            $directory.'authorized.xml',
            $directory.'authorized-recovered.xml',
        ], true), 409, 'O XML autorizado precisa ser recuperado antes de imprimir o DANFE NFC-e.');

        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 409, 'Arquivo autorizado indisponível no armazenamento fiscal.');

        $xml=$disk->get($path);
        try {
            // Verifica chave, protocolo, ambiente e QR Code autorizados
            // antes de preparar o documento auxiliar.
            $danfe->parse($xml,$fiscalDocumentJob);
        } catch (\RuntimeException $e) {
            abort(409, 'DANFE NFC-e indisponível: '.$e->getMessage());
        }

        $cached=$pdf->existingPath($fiscalDocumentJob,$xml);
        if ($cached!==null) {
            if ($request->boolean('status')) {
                return response()->json(['status'=>'ready'])->header('Cache-Control','no-store');
            }
            return $this->showPdf($fiscalDocumentJob,$cached);
        }

        $id=$fiscalDocumentJob->id;
        $errorKey='nextor:nfce-pdf-error:'.$id;
        if ($request->boolean('status')) {
            return response()->json(['status'=>Cache::has($errorKey) ? 'failed' : 'pending'])
                ->header('Cache-Control','no-store');
        }

        $routeName=$request->routeIs('pdv.nfce.danfe') ? 'pdv.nfce.danfe' : 'fiscal.nfce.danfe';
        $url=route($routeName,$fiscalDocumentJob);
        if ($request->boolean('retry')) {
            Cache::forget($errorKey);
            return redirect()->to($url);
        }

        // Rendering uses the already-authorized XML and headless Chrome,
        // NOT ACBr/SEFAZ. It is safe to run from the web PHP process:
        // first access creates the PDF, later accesses stream the cached file.
        // No separate queue worker is necessary to view an existing NFC-e.
        $lock=Cache::lock('nextor:nfce-pdf-generate:'.$id,25);
        if (!$lock->get()) {
            return response()->view('fiscal.nfce-danfe-wait',[
                'failed'=>false,
                'url'=>$url,
            ],202)->header('Cache-Control','private, no-store');
        }

        try {
            // An existing background job may have finished just before the lock.
            $cached=$pdf->existingPath($fiscalDocumentJob,$xml);
            if ($cached===null) {
                $cached=$pdf->generate($fiscalDocumentJob,$xml);
            }
            Cache::forget($errorKey);
            return $this->showPdf($fiscalDocumentJob,$cached);
        } catch (\Throwable $error) {
            Cache::put($errorKey,true,30);
            Log::error('DANFE PDF indisponível',[
                'fiscal_document_job_id'=>$id,
                'reason'=>$error->getMessage(),
            ]);

            return response()->view('fiscal.nfce-danfe-wait',[
                'failed'=>true,
                'url'=>$url,
            ],200)->header('Cache-Control','private, no-store');
        } finally {
            $lock->release();
        }
    }

    private function showPdf(FiscalDocumentJob $document, string $path)
    {
        $name='DANFE-NFCe-'.$document->series.'-'.$document->document_number.'.pdf';
        return response()->file(Storage::disk('local')->path($path),[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'inline; filename="'.$name.'"',
            'Cache-Control'=>'private, no-store, no-cache, must-revalidate',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
