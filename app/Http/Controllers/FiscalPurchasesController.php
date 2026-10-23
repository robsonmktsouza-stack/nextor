<?php

namespace App\Http\Controllers;

use App\Http\Controllers\FiscalController;
use App\Models\IncomingNfe;
use App\Services\Fiscal\IncomingNfeSyncService;
use App\Services\Fiscal\IncomingNfeManifestationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class FiscalPurchasesController extends Controller
{
    public function index(Request $request,IncomingNfeSyncService $sync)
    {
        $state=$sync->state();
        $term=trim((string)$request->query('search',''));
        $status=(string)$request->query('status','');
        $month=(string)$request->query('month',now()->format('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month)) {
            $month=now()->format('Y-m');
        }
        $period=Carbon::createFromFormat('Y-m-d',$month.'-01')->startOfMonth();
        $periodEnd=$period->copy()->addMonth();
        $monthNames=[
            1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',
            7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro',
        ];
        $query=IncomingNfe::query()
            ->where('issued_at','>=',$period)
            ->where('issued_at','<',$periodEnd)
            ->when($term,fn($q)=>$q->where(function($w)use($term){
                $digits=preg_replace('/\D/','',$term);
                $w->where('access_key','like','%'.$term.'%')
                    ->orWhere('supplier_name','like','%'.$term.'%');
                if($digits!=='')$w->orWhere('supplier_cnpj','like','%'.$digits.'%');
            }))
            ->when(in_array($status,['summary','xml_received'],true),
                fn($q)=>$q->where('status',$status))
            ->orderByDesc('issued_at')->orderByDesc('id');
        $filteredTotal=(clone $query)->reorder()->sum('total');
        $filteredCount=(clone $query)->reorder()->count();
        $invoices=$query->with('latestManifestationAttempt')->paginate(25)->withQueryString();
        $counts=['total'=>$filteredCount];
        return view('fiscal.purchases.index',[
            'invoices'=>$invoices,'state'=>$state,'counts'=>$counts,
            'filteredTotal'=>$filteredTotal,
            'month'=>$month,
            'prevMonth'=>$period->copy()->subMonth()->format('Y-m'),
            'nextMonth'=>$period->copy()->addMonth()->format('Y-m'),
            'monthLabel'=>$monthNames[(int)$period->format('n')].' '.$period->format('Y'),
            'search'=>$term,'status'=>$status,'ready'=>$sync->isReady(),
            'manifestationEvents'=>IncomingNfeManifestationService::EVENTS,
            'tabs'=>FiscalController::TABS,'tab'=>'purchases',
        ]);
    }

    public function show(IncomingNfe $incomingNfe)
    {
        return view('fiscal.purchases.show',[
            'invoice'=>$incomingNfe->load(['latestManifestationAttempt','manifestationAttempts']),
            'manifestationEvents'=>IncomingNfeManifestationService::EVENTS,
            'tabs'=>FiscalController::TABS,'tab'=>'purchases',
        ]);
    }

    public function download(IncomingNfe $incomingNfe)
    {
        $path='fiscal/incoming-nfe/'.$incomingNfe->access_key.'.xml';
        abort_unless($incomingNfe->xml_path===$path && Storage::disk('local')->exists($path),404);
        return Storage::disk('local')->download(
            $path,'NFe-'.$incomingNfe->access_key.'.xml',
            ['Content-Type'=>'application/xml','X-Content-Type-Options'=>'nosniff',
             'Cache-Control'=>'private, no-store']
        );
    }

    /**
     * DANFE modelo 55: somente XML autorizado, nunca resumo da distribuição.
     */
    public function danfe(IncomingNfe $incomingNfe, \App\Services\Fiscal\IncomingNfeDanfeService $service)
    {
        abort_unless($incomingNfe->status==='xml_received' && $incomingNfe->xml_path,404);
        try {
            $pdf=$service->generate($incomingNfe);
        } catch (\Throwable $error) {
            \Illuminate\Support\Facades\Log::warning('DANFE de entrada indisponível',[
                'invoice_id'=>$incomingNfe->id,'kind'=>get_class($error),
            ]);
            abort(409,'DANFE indisponível. Confira o XML autorizado e a configuração fiscal.');
        }
        return response($pdf,200,[
            'Content-Type'=>'application/pdf',
            'Content-Disposition'=>'inline; filename="DANFE-'.$incomingNfe->access_key.'.pdf"',
            'Cache-Control'=>'private, no-store, no-cache, must-revalidate',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function synchronize(Request $request,IncomingNfeSyncService $sync)
    {
        $result=$sync->synchronize();
        $message=match($result['status']) {
            'ok'=>'Consulta concluída. Novas notas recebidas: '.$result['imported'].'.',
            'cooldown'=>'A SEFAZ exige um intervalo entre consultas. A próxima busca será automática.',
            'busy'=>'Já existe uma consulta fiscal em andamento.',
            'not_ready'=>'Configure o certificado digital A1 em Ajustes → Fiscal para buscar as notas.',
            default=>'A consulta não pôde ser concluída. Confira o certificado e tente mais tarde.',
        };
        return redirect()->route('fiscal.purchases.index',$request->only(['month','search','status']))
            ->with(in_array($result['status'],['error','not_ready'],true)?'error':'success',$message);
    }

    public function automatic(Request $request,IncomingNfeSyncService $sync)
    {
        $data=$request->validate(['auto_sync'=>['required','boolean']]);
        $enabled=(bool)$data['auto_sync'];
        if($enabled && !$sync->isReady()) {
            return back()->with('error','Cadastre o certificado digital A1 em Ajustes → Fiscal antes de ativar a busca automática.');
        }
        $state=$sync->state();
        $state->update(['auto_sync'=>$enabled]);
        return back()->with('success',$enabled?'Busca automática de NF-e ativada.':'Busca automática desativada.');
    }

    public function manifest(Request $request, IncomingNfe $incomingNfe, IncomingNfeManifestationService $service)
    {
        $input=$request->validate([
            'event_type'=>['required',Rule::in(array_keys(IncomingNfeManifestationService::EVENTS))],
            'reason'=>['nullable','string','max:255'],
        ]);
        $result=$service->manifest($incomingNfe,$input['event_type'],$input['reason']??null,$request->user()?->id);
        $message=match($result['status']) {
            'accepted'=>'Manifestação registrada na SEFAZ (protocolo confirmado).',
            'rejected'=>$result['message'] ?? 'SEFAZ rejeitou a manifestação.',
            'uncertain'=>$result['message'] ?? 'Retorno inconclusivo; consulte na SEFAZ.',
            'failed'=>$result['message'] ?? 'Falha local. Evento não enviado à SEFAZ.' ,
            'review'=>'Já existe um envio pendente de conferência para esta NF-e.',
            'already'=>'Esta manifestação já foi registrada para a NF-e.',
            'busy'=>'Já existe uma manifestação desta nota em andamento.',
            'not_ready'=>'Configure um certificado digital A1 válido para manifestar.',
            default=>'Não foi possível confirmar o evento fiscal.',
        };
        return back()->with(in_array($result['status'],['accepted','already'],true)?'success':'error',$message);
    }

    /** Consulta fiscal somente leitura; não faz uma segunda manifestação. */
    public function verifyManifestation(IncomingNfe $incomingNfe, IncomingNfeManifestationService $service)
    {
        $result=$service->verify($incomingNfe);
        $message=match($result['status']) {
            'accepted'=>'Manifestação confirmada na SEFAZ. Protocolo: '.$result['protocol'].'.',
            'cooldown'=>'Consulta recente. Aguarde o intervalo de segurança antes de conferir novamente.',
            'no_pending'=>'Não existe manifestação pendente para conferir.',
            'not_ready'=>'Certificado digital A1 não está disponível.',
            'busy'=>'Já existe uma operação fiscal em andamento para esta nota.',
            default=>$result['message'] ?? 'Não foi possível confirmar o evento. O reenvio permanece bloqueado.',
        };
        return back()->with($result['status']==='accepted'?'success':'warning',$message);
    }

    public function importXml(Request $request,IncomingNfeSyncService $sync)
    {
        $file=$request->validate([
            'xml'=>['required','file','max:6144',
                'mimetypes:application/xml,text/xml,text/plain,application/octet-stream'],
        ])['xml'];
        try {
            $incoming=$sync->importXml((string)file_get_contents($file->getRealPath()));
        } catch(InvalidArgumentException $error) {
            return back()->withErrors(['xml'=>$error->getMessage()]);
        }
        return redirect()->route('fiscal.purchases.show',$incoming)
            ->with('success','NF-e importada para conferência, sem movimentar estoque ou financeiro.');
    }
}
