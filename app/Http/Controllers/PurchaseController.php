<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\IncomingNfe;
use App\Models\Product;
use App\Models\Purchase;
use App\Services\PurchaseService;
use App\Services\Fiscal\IncomingNfePurchasePrefill;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $month=(string)$request->query('month',now()->format('Y-m'));
        if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month))$month=now()->format('Y-m');
        $period=Carbon::createFromFormat('Y-m-d',$month.'-01')->startOfMonth();
        $term=trim((string)$request->query('search',''));
        $status=(string)$request->query('status','');
        if(!in_array($status,['draft','received','cancelled'],true))$status='';
        $supplierId=$request->integer('supplier_id');
        $query=Purchase::query()->with('supplier')
            ->whereDate('operation_date','>=',$period->toDateString())
            ->whereDate('operation_date','<',$period->copy()->addMonth()->toDateString())
            ->when($status,fn($q)=>$q->where('status',$status))
            ->when($supplierId,fn($q)=>$q->where('supplier_id',$supplierId))
            ->when($term,fn($q)=>$q->where(function($filter)use($term){
                $filter->where('keyword','like',"%{$term}%")
                    ->orWhere('document_number','like',"%{$term}%")
                    ->orWhere('id','like',"%{$term}%")
                    ->orWhereHas('supplier',fn($s)=>$s->where('name','like',"%{$term}%")
                        ->orWhere('document','like',"%{$term}%"));
            }));
        $count=(clone $query)->count();
        $total=(clone $query)->where('status','!=','cancelled')->sum('total');
        $purchases=$query->orderByDesc('operation_date')->orderByDesc('id')
            ->paginate(AppSetting::tablePerPage($request))->withQueryString();
        return view('purchases.index',[
            'purchases'=>$purchases,'count'=>$count,'total'=>$total,
            'month'=>$month,'prevMonth'=>$period->copy()->subMonth()->format('Y-m'),
            'nextMonth'=>$period->copy()->addMonth()->format('Y-m'),
            'monthLabel'=>ucfirst($period->locale('pt_BR')->translatedFormat('F Y')),
            'term'=>$term,'status'=>$status,'supplierId'=>$supplierId,
            'suppliers'=>Customer::query()->where('is_supplier',true)->orderBy('name')->get(['id','name']),
        ]);
    }

    public function create(Request $request)
    {
        return view('purchases.form',$this->formData(new Purchase([
            'operation_date'=>today(),'due_date'=>today()->addDays(30),
            'freight'=>'0.00','discount'=>'0.00','other_expenses'=>'0.00','generate_payable'=>true,
        ]),false,$request));
    }

    public function importFromNfe(Request $request,IncomingNfe $incomingNfe,IncomingNfePurchasePrefill $prefill)
    {
        $existing=$incomingNfe->purchase;
        if($existing) {
            return redirect()->route('purchases.show',$existing)
                ->with('success','Esta NF-e já está vinculada a uma compra.');
        }
        try {
            $defaults=$prefill->fromInvoice($incomingNfe);
        } catch (ValidationException $error) {
            return redirect()->route('fiscal.purchases.index',[
                'month'=>$incomingNfe->issued_at?->format('Y-m')??now()->format('Y-m'),
            ])->withErrors($error->errors());
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            report($error);
            return redirect()->route('fiscal.purchases.index',[
                'month'=>$incomingNfe->issued_at?->format('Y-m')??now()->format('Y-m'),
            ])->withErrors(['xml'=>'Não foi possível conferir o XML desta NF-e.']);
        }
        $purchase=new Purchase(array_merge([
            'due_date'=>null,'generate_payable'=>false,
        ],array_diff_key($defaults,['items'=>true])));
        return view('purchases.form',array_merge(
            $this->formData($purchase,false,$request),
            ['importItems'=>$defaults['items'],'sourceInvoice'=>$incomingNfe],
        ));
    }

    public function edit(Request $request,Purchase $purchase)
    {
        abort_unless($purchase->status==='draft',409,'Somente rascunhos podem ser editados.');
        return view('purchases.form',$this->formData($purchase->load('items'),true,$request));
    }

    public function store(Request $request, PurchaseService $service)
    {
        $data=$this->validateData($request);
        $this->assertCanGeneratePayable($request,$data['generate_payable']);
        $purchase=$service->saveDraft($data,$request->user()->id);
        return redirect()->route('purchases.show',$purchase)->with('success','Compra salva como rascunho.');
    }

    public function update(Request $request,Purchase $purchase,PurchaseService $service)
    {
        $data=$this->validateData($request);
        $this->assertCanGeneratePayable($request,$data['generate_payable']);
        $purchase=$service->saveDraft($data,$request->user()->id,$purchase);
        return redirect()->route('purchases.show',$purchase)->with('success','Rascunho atualizado.');
    }

    public function show(Purchase $purchase)
    {
        $purchase->load(['supplier','invoice','items.product','payable','creator','movements']);
        return view('purchases.show',compact('purchase'));
    }

    public function receive(Request $request,Purchase $purchase,PurchaseService $service)
    {
        $this->assertCanGeneratePayable($request,$purchase->generate_payable);
        $purchase=$service->receive($purchase,(int)$request->user()->id);
        return redirect()->route('purchases.show',$purchase)->with('success',
            'Recebimento confirmado. Estoque e financeiro atualizados conforme a compra.');
    }

    public function cancel(Request $request,Purchase $purchase,PurchaseService $service)
    {
        $purchase=$service->cancel($purchase,(int)$request->user()->id);
        return redirect()->route('purchases.show',$purchase)
            ->with('success','Compra cancelada. Estoque e contas a pagar foram estornados, quando aplicáveis.');
    }

    private function formData(Purchase $purchase,bool $editing,Request $request): array
    {
        $hasFinance=$this->canGeneratePayable($request);
        $invoices=IncomingNfe::query()->where('status','xml_received')->whereNotNull('xml_path')
            ->whereDoesntHave('purchase',fn($q)=>$editing?$q->whereKeyNot($purchase->id):$q)
            ->orderByDesc('issued_at')->limit(80)->get(['id','access_key','supplier_name','supplier_cnpj','total','issued_at']);
        if($editing&&$purchase->incoming_nfe_id&&!$invoices->contains('id',$purchase->incoming_nfe_id)) {
            $selected=IncomingNfe::query()->find($purchase->incoming_nfe_id);
            if($selected)$invoices->push($selected);
        }
        return [
            'purchase'=>$purchase,'editing'=>$editing,'hasFinance'=>$hasFinance,
            'suppliers'=>Customer::query()->where('is_supplier',true)->orderBy('name')->get(['id','name','document']),
            'products'=>Product::query()->where('is_active',true)->orderBy('name')
                ->get(['id','name','sku','unit','cost_price','stock_quantity','control_stock']),
            'invoices'=>$invoices,
        ];
    }

    private function validateData(Request $request): array
    {
        $data=$request->validate([
            'supplier_id'=>['required','integer',Rule::exists('customers','id')->where('is_supplier',true)],
            'incoming_nfe_id'=>['nullable','integer','exists:incoming_nfes,id'],
            'operation_date'=>['required','date_format:Y-m-d'],
            'due_date'=>['nullable','date_format:Y-m-d'],
            'document_number'=>['nullable','string','max:80'],
            'keyword'=>['nullable','string','max:190'],
            'freight'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'other_expenses'=>['nullable','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'discount'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'generate_payable'=>['required','boolean'],
            'notes'=>['nullable','string','max:5000'],
            'items'=>['required','array','min:1','max:80'],
            'items.*.product_id'=>['nullable','integer','exists:products,id'],
            'items.*.source_code'=>['nullable','string','max:80'],
            'items.*.source_description'=>['nullable','string','max:190'],
            'items.*.source_unit'=>['nullable','string','max:12'],
            'items.*.source_ncm'=>['nullable','string','max:10'],
            'items.*.source_cfop'=>['nullable','string','max:4'],
            'items.*.source_ean'=>['nullable','string','max:32'],
            'items.*.source_number'=>['nullable','integer','min:1','max:9999'],
            'items.*.quantity'=>['required','numeric','gt:0','max:999999999','decimal:0,3'],
            'items.*.unit_cost'=>['required','numeric','min:0','max:999999999','decimal:0,2'],
            'items.*.discount'=>['nullable','numeric','min:0','max:999999999','decimal:0,2'],
            'items.*.other_costs'=>['nullable','numeric','min:0','max:999999999','decimal:0,2'],
            'items.*.external_costs'=>['nullable','numeric','min:0','max:999999999','decimal:0,2'],
            'items.*.ipi'=>['nullable','numeric','min:0','max:999999999','decimal:0,2'],
            'items.*.icms_st'=>['nullable','numeric','min:0','max:999999999','decimal:0,2'],
        ]);
        $data['generate_payable']=(bool)$data['generate_payable'];
        return $data;
    }

    private function canGeneratePayable(Request $request): bool
    {
        return $request->user()->canAccess('finance')
            && \App\Support\CommercialModuleAccess::visible(
                (array)$request->attributes->get('lumeron_commercial_license',[]),'finance');
    }
    private function assertCanGeneratePayable(Request $request,bool $enabled): void
    {
        if($enabled&&!$this->canGeneratePayable($request)) {
            throw ValidationException::withMessages(['generate_payable'=>'Você não possui acesso ao Financeiro. Desative a geração do título.']);
        }
    }
}
