<?php

namespace App\Http\Controllers;

use App\Jobs\CancelNFCeJob;
use App\Jobs\TransmitOfflineNFCeJob;
use App\Services\Fiscal\NFCePdfService;
use Illuminate\Support\Facades\Storage;
use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\NFCeCancellationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class NFCeFiscalEventsController extends Controller
{
    public function transmitOffline(FiscalDocumentJob $fiscalDocumentJob,NFCePdfService $pdf)
    {
        $job=$fiscalDocumentJob;
        if ($job->document_type!=='nfce' || $job->emission_mode!=='offline'
            || $job->status!=='offline_signed') {
            throw ValidationException::withMessages([
                'offline'=>'Esta nota não está pronta para transmissão de contingência.',
            ]);
        }
        $signedPath='fiscal/nfce/'.$job->id.'/signed.xml';
        if(!Storage::disk('local')->exists($signedPath)
            || !$pdf->existingPath($job,Storage::disk('local')->get($signedPath))) {
            throw ValidationException::withMessages([
                'offline'=>'Gere o DANFE da contingência antes da transmissão.',
            ]);
        }
        $queue=(string)config('queue.default','sync');
        if(in_array($queue,['','sync','null'],true)){
            throw ValidationException::withMessages(['offline'=>'Fila fiscal indisponível.']);
        }
        TransmitOfflineNFCeJob::dispatch($job->id)->onConnection($queue);
        return back()->with('success','Transmissão do XML original iniciada. Acompanhe a autorização pela chave.');
    }

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
