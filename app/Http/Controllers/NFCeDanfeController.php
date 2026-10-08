<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocumentJob;
use App\Models\AppSetting;
use App\Services\Fiscal\NFCeDanfeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class NFCeDanfeController extends Controller
{
    public function __invoke(
        Request $request,
        FiscalDocumentJob $fiscalDocumentJob,
        NFCeDanfeService $danfe
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

        try {
            $document = $danfe->parse($disk->get($path), $fiscalDocumentJob);
        } catch (\RuntimeException $e) {
            abort(409, 'DANFE NFC-e indisponível: '.$e->getMessage());
        }

        $defaultPaper = (string) AppSetting::value('pdv', 'receipt_width', '80');
        $paper = (string) $request->query('paper', $defaultPaper) === '58' ? '58' : '80';

        return response()->view('fiscal.nfce-danfe', [
            'danfe' => $document,
            'paper' => $paper,
            'printRoute' => $request->routeIs('pdv.nfce.danfe') ? 'pdv.nfce.danfe' : 'fiscal.nfce.danfe',
            'fiscalDocument' => $fiscalDocumentJob,
        ])->header('Cache-Control', 'private, no-store, no-cache, must-revalidate')
          ->header('X-Content-Type-Options', 'nosniff');
    }
}
