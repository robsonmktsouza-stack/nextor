<?php

namespace App\Http\Controllers;

use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\FiscalDocumentUtilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use ZipArchive;

final class FiscalUtilitiesController extends Controller
{
    public function xml(Request $request, FiscalDocumentUtilityService $files)
    {
        $data=$request->validate([
            'document_type'=>['required',Rule::in(FiscalDocumentUtilityService::types())],
            'ids'=>['required','array','min:1','max:100'],
            'ids.*'=>['required','integer','distinct','min:1'],
        ]);
        $ids=array_map('intval',$data['ids']);
        $documents=FiscalDocumentJob::query()
            ->where('document_type',$data['document_type'])
            ->whereIn('id',$ids)
            ->get()->keyBy('id');

        $available=[];
        foreach ($ids as $id) {
            $document=$documents->get($id);
            if (!$document || !($path=$files->xmlPath($document))) {
                return back()->with('error','Um ou mais documentos selecionados não possuem XML autorizado disponível.');
            }
            $available[]=[$document,$path];
        }

        $disk=Storage::disk('local');
        if (count($available)===1) {
            [$document,$path]=$available[0];
            return $disk->download(
                $path,$files->filename($document,'xml'),
                ['Content-Type'=>'application/xml','X-Content-Type-Options'=>'nosniff']
            );
        }

        if (!class_exists(ZipArchive::class)) {
            return back()->with('error','Não foi possível gerar o ZIP dos XMLs. A extensão PHP ZIP não está habilitada.');
        }

        $temporary=tempnam(sys_get_temp_dir(),'nextor-fiscal-');
        if ($temporary===false) {
            return back()->with('error','Não foi possível preparar o arquivo de download.');
        }

        $zip=new ZipArchive();
        if ($zip->open($temporary,ZipArchive::OVERWRITE)!==true) {
            @unlink($temporary);
            return back()->with('error','Não foi possível criar o ZIP dos XMLs.');
        }

        $ok=true;
        foreach ($available as [$document,$path]) {
            $ok=$zip->addFile($disk->path($path),$files->filename($document,'xml')) && $ok;
        }
        $ok=$zip->close() && $ok;
        if (!$ok) {
            @unlink($temporary);
            return back()->with('error','Falha ao adicionar os XMLs ao ZIP.');
        }

        return response()->download(
            $temporary,'XMLs-'.$data['document_type'].'-'.now()->format('Ymd-His').'.zip',
            ['Content-Type'=>'application/zip','X-Content-Type-Options'=>'nosniff']
        )->deleteFileAfterSend(true);
    }

    public function auxiliary(
        FiscalDocumentJob $fiscalDocumentJob,
        FiscalDocumentUtilityService $files
    ) {
        if ($fiscalDocumentJob->document_type==='nfce'
            && $fiscalDocumentJob->status==='authorized'
            && $files->xmlPath($fiscalDocumentJob)!==null) {
            return redirect()->route('fiscal.nfce.danfe',$fiscalDocumentJob);
        }

        $path=$files->auxiliaryPath($fiscalDocumentJob);
        if ($path===null) {
            return back()->with('error','O documento auxiliar ainda não está disponível para esta nota.');
        }

        return Storage::disk('local')->download(
            $path,$files->filename($fiscalDocumentJob,'pdf'),
            ['Content-Type'=>'application/pdf','X-Content-Type-Options'=>'nosniff']
        );
    }
}
