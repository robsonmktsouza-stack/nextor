<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\NFCePdfService;
use Illuminate\Support\Facades\Log;
use App\Services\Fiscal\NFCeDanfeService;
use Illuminate\Support\Facades\Storage;

final class NFCeDanfeController extends Controller
{
    public function __invoke(
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

        try {
            $content=$pdf->render($fiscalDocumentJob,$xml);
        } catch (\Throwable $e) {
            Log::error('Falha ao gerar DANFE NFC-e em PDF',[
                'fiscal_document_job_id'=>$fiscalDocumentJob->id,
                'reason'=>$e->getMessage(),
            ]);
            abort(503, 'Não foi possível gerar o DANFE PDF. Consulte o registro do sistema.');
        }

        $name='DANFE-NFCe-'.$fiscalDocumentJob->series.'-'.$fiscalDocumentJob->document_number.'.pdf';

        return response($content,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'inline; filename="'.$name.'"',
            'Cache-Control'=>'private, no-store, no-cache, must-revalidate',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
