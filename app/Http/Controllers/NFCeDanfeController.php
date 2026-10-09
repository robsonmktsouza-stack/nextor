<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\NFCePdfService;
use Illuminate\Support\Facades\Cache;
use App\Jobs\GenerateNFCeDanfePdfJob;
use Illuminate\Http\Request;
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
                return response()->json(['status'=>'ready'])
                    ->header('Cache-Control','no-store');
            }

            $name='DANFE-NFCe-'.$fiscalDocumentJob->series.'-'.$fiscalDocumentJob->document_number.'.pdf';
            return response()->file($disk->path($cached),[
                'Content-Type'=>'application/pdf',
                'Content-Disposition'=>'inline; filename="'.$name.'"',
                'Cache-Control'=>'private, no-store, no-cache, must-revalidate',
                'X-Content-Type-Options'=>'nosniff',
            ]);
        }

        $errorKey='nextor:nfce-pdf-error:'.$fiscalDocumentJob->id;
        $requestKey='nextor:nfce-pdf-request:'.$fiscalDocumentJob->id;

        if ($request->boolean('retry')) {
            Cache::forget($errorKey);
            Cache::forget($requestKey);
            return redirect()->route(
                request()->routeIs('pdv.nfce.danfe') ? 'pdv.nfce.danfe' : 'fiscal.nfce.danfe',
                $fiscalDocumentJob
            );
        }

        $failed=Cache::has($errorKey);
        if ($request->boolean('status')) {
            return response()->json(['status'=>$failed ? 'failed' : 'pending'])
                ->header('Cache-Control','no-store');
        }

        $connection=(string)config('queue.default','sync');
        if (!$failed && !in_array($connection,['sync','null',''],true)
            && Cache::add($requestKey,true,120)) {
            try {
                GenerateNFCeDanfePdfJob::dispatch($fiscalDocumentJob->id)
                    ->onConnection($connection);
            } catch (\Throwable $e) {
                Cache::forget($requestKey);
                Cache::put($errorKey,true,120);
                \Illuminate\Support\Facades\Log::error('Não foi possível solicitar DANFE PDF',[
                    'fiscal_document_job_id'=>$fiscalDocumentJob->id,
                    'reason'=>$e->getMessage(),
                ]);
                $failed=true;
            }
        }

        return response()->view('fiscal.nfce-danfe-wait',[
            'failed'=>$failed || in_array($connection,['sync','null',''],true),
            'url'=>route(
                request()->routeIs('pdv.nfce.danfe') ? 'pdv.nfce.danfe' : 'fiscal.nfce.danfe',
                $fiscalDocumentJob
            ),
        ],202)->header('Cache-Control','private, no-store');
    }
}
