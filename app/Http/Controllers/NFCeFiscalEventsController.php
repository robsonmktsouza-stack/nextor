<?php

namespace App\Http\Controllers;

use App\Jobs\CancelNFCeJob;
use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\NFCeCancellationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class NFCeFiscalEventsController extends Controller
{
    public function cancel(Request $request,FiscalDocumentJob $fiscalDocumentJob,NFCeCancellationService $service)
    {
        $data=$request->validate([
            'reason'=>['required','string','min:15','max:255'],
            'no_circulation'=>['required','accepted'],
        ]);
        $connection=(string)config('queue.default','sync');
        if (in_array($connection,['','sync','null'],true)) {
            throw ValidationException::withMessages(['reason'=>'Configure a fila fiscal antes do cancelamento.']);
        }
        $job=$service->request($fiscalDocumentJob,$data['reason']);
        try {
            CancelNFCeJob::dispatch($job->id)->onConnection($connection);
        } catch(\Throwable $e) {
            Log::error('Não foi possível enfileirar evento fiscal NFC-e',[
                'document_id'=>$job->id,'reason'=>$e->getMessage(),
            ]);
            return back()->with('error','Pedido registrado, mas não foi enfileirado. Consulte o suporte antes de reenviar.');
        }
        return back()->with('success','Cancelamento solicitado. A nota só ficará cancelada após confirmação da SEFAZ.');
    }
}
