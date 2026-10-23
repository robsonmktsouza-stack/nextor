<?php

namespace App\Http\Controllers;

use App\Http\Controllers\FiscalController;
use App\Models\IncomingNfe;
use App\Services\Fiscal\IncomingNfeSyncService;
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
        $query=IncomingNfe::query()
            ->when($term,fn($q)=>$q->where(function($w)use($term){
                $w->where('access_key','like','%'.$term.'%')
                    ->orWhere('supplier_name','like','%'.$term.'%')
                    ->orWhere('supplier_cnpj','like','%'.preg_replace('/\D/','',$term).'%');
            }))
            ->when(in_array($status,['summary','xml_received'],true),
                fn($q)=>$q->where('status',$status))
            ->orderByDesc('issued_at')->orderByDesc('id');
        $invoices=$query->paginate(25)->withQueryString();
        $counts=[
            'total'=>IncomingNfe::query()->count(),
            'summary'=>IncomingNfe::query()->where('status','summary')->count(),
            'xml_received'=>IncomingNfe::query()->where('status','xml_received')->count(),
        ];
        return view('fiscal.purchases.index',[
            'invoices'=>$invoices,'state'=>$state,'counts'=>$counts,
            'search'=>$term,'status'=>$status,'ready'=>$sync->isReady(),
            'tabs'=>FiscalController::TABS,'tab'=>'purchases',
        ]);
    }

    public function show(IncomingNfe $incomingNfe)
    {
        return view('fiscal.purchases.show',[
            'invoice'=>$incomingNfe,'tabs'=>FiscalController::TABS,'tab'=>'purchases',
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

    public function synchronize(IncomingNfeSyncService $sync)
    {
        $result=$sync->synchronize();
        $message=match($result['status']) {
            'ok'=>'Consulta concluída. Novas notas recebidas: '.$result['imported'].'.',
            'cooldown'=>'A SEFAZ exige um intervalo entre consultas. A próxima busca será automática.',
            'busy'=>'Já existe uma consulta fiscal em andamento.',
            default=>'A consulta não pôde ser concluída. Confira o certificado e tente mais tarde.',
        };
        return redirect()->route('fiscal.purchases.index')
            ->with($result['status']==='error'?'error':'success',$message);
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
