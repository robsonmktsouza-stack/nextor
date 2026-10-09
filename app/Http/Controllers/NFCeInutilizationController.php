<?php

namespace App\Http\Controllers;

use App\Jobs\InutilizeNFCeNumbersJob;
use App\Models\AppSetting;
use App\Models\NFCeInutilization;
use App\Services\Fiscal\NFCeInutilizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class NFCeInutilizationController extends Controller
{
    public function index()
    {
        return view('fiscal.nfce.inutilizations',[
            'rows'=>NFCeInutilization::query()->latest('id')->paginate(25),
            'series'=>(int)AppSetting::value('nfce','series',1),
            'nextNumber'=>(int)AppSetting::value('nfce','next_number',1),
        ]);
    }

    public function store(Request $request,NFCeInutilizationService $service)
    {
        $data=$request->validate([
            'year'=>['required','integer','between:2020,2100'],
            'series'=>['required','integer','between:0,999'],
            'first_number'=>['required','integer','between:1,999999999'],
            'last_number'=>['required','integer','between:1,999999999','gte:first_number'],
            'reason'=>['required','string','min:15','max:255'],
        ]);
        $queue=(string)config('queue.default','sync');
        if(in_array($queue,['','sync','null'],true)){
            throw ValidationException::withMessages(['first_number'=>'A fila fiscal deve estar ativa para inutilização.']);
        }
        $record=$service->request($data,(int)$request->user()->id);
        try{
            InutilizeNFCeNumbersJob::dispatch($record->id)->onConnection($queue);
        }catch(\Throwable $e){
            Log::error('Falha ao enfileirar inutilização NFC-e',[
                'request_id'=>$record->id,'reason'=>$e->getMessage(),
            ]);
            return back()->with('error','Pedido registrado, mas não enfileirado. Verifique antes de solicitar novamente.');
        }
        return redirect()->route('fiscal.nfce.inutilizations')
            ->with('success','Solicitação enviada. A inutilização só será concluída após confirmação da SEFAZ.');
    }
}
