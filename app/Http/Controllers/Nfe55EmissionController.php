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
            ->with('success','Emissão solicitada. Acompanhe a situação da nota nesta tela.');
    }

    public function retry(NfeDraft $nfeDraft,Nfe55EmissionService $service)
    {
        $service->retryPreparation($nfeDraft);
        return redirect()->route('fiscal.nfe.edit',$nfeDraft)
            ->with('success','Nova tentativa de envio solicitada.');
    }

    public function consult(NfeDraft $nfeDraft,Nfe55EmissionService $service)
    {
        $service->consult($nfeDraft);
        return redirect()->route('fiscal.nfe.edit',$nfeDraft)
            ->with('success','Consulta à SEFAZ concluída.');
    }

    public function cancel(Request $request,NfeDraft $nfeDraft,\App\Services\Fiscal\Nfe55CancellationService $service)
    {
        abort_unless(auth()->user()?->canAccess('settings'),403);
        $data=$request->validate([
            'reason'=>['required','string','min:15','max:255'],
            'current_password'=>['required','string'],
            'operation_not_completed'=>['accepted'],
        ]);
        if(!\Illuminate\Support\Facades\Hash::check(
            $data['current_password'],(string)auth()->user()->password
        )){
            throw \Illuminate\Validation\ValidationException::withMessages([
                'current_password'=>'Senha incorreta.',
            ]);
        }
        $service->request($nfeDraft,$data['reason']);
        return redirect()->route('fiscal.nfe.edit',$nfeDraft)
            ->with('success','Cancelamento solicitado. Acompanhe a confirmação da SEFAZ.');
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
            'Content-Disposition'=>'inline; filename="DANFE-NFe-'.(int)$nfeDraft->series.'-'.(int)$nfeDraft->document_number.'.pdf"',
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
            'NFe-'.($nfeDraft->access_key??((int)$nfeDraft->series.'-'.(int)$nfeDraft->document_number))
                .(str_ends_with($path,'/signed.xml')?'-assinado-sem-protocolo':'-autorizada').'.xml',
            ['Content-Type'=>'application/xml','Cache-Control'=>'private, no-store',
             'X-Content-Type-Options'=>'nosniff']);
    }
}
