<?php

namespace App\Http\Controllers;

use App\Models\NfeDraft;
use App\Services\Fiscal\Nfe55EmissionService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

final class Nfe55EmissionController extends Controller
{
    public function emit(NfeDraft $nfeDraft,Nfe55EmissionService $service)
    {
        $service->queue($nfeDraft);
        return redirect()->route('fiscal.nfe.edit',$nfeDraft)
            ->with('success','NF-e encaminhada à fila fiscal.');
    }

    public function retry(NfeDraft $nfeDraft,Nfe55EmissionService $service)
    {
        $service->retryPreparation($nfeDraft);
        return redirect()->route('fiscal.nfe.edit',$nfeDraft)
            ->with('success','Preparação fiscal agendada.');
    }

    public function consult(NfeDraft $nfeDraft,Nfe55EmissionService $service)
    {
        $service->consult($nfeDraft);
        return redirect()->route('fiscal.nfe.edit',$nfeDraft)
            ->with('success','Consulta à SEFAZ concluída.');
    }

    public function danfe(NfeDraft $nfeDraft,\App\Services\Fiscal\Nfe55DanfeService $service)
    {
        try {
            $pdf=$service->generate($nfeDraft);
        }catch(\RuntimeException $error){
            abort(409,'DANFE indisponível para esta NF-e.');
        }
        return response($pdf,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'inline; filename="DANFE-'.$nfeDraft->id.'.pdf"',
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function xml(NfeDraft $nfeDraft)
    {
        $path=$nfeDraft->xml_path;
        $expected=['fiscal/nfe55/'.$nfeDraft->id.'/signed.xml','fiscal/nfe55/'.$nfeDraft->id.'/authorized.xml'];
        abort_unless($path && in_array($path,$expected,true) && Storage::disk('local')->exists($path),404);
        return Storage::disk('local')->download($path,
            'NFe-'.$nfeDraft->id.'-'.($nfeDraft->access_key??'rascunho').'.xml',
            ['Content-Type'=>'application/xml','Cache-Control'=>'private, no-store',
             'X-Content-Type-Options'=>'nosniff']);
    }
}
