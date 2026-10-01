<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CustomerController extends Controller {
    public function index(Request $request) {
        $term=trim((string)$request->query('search',''));
        $customers=Customer::query()
            ->when($term,fn($q)=>$q->where(fn($t)=>$t
                ->where('name','like',"%{$term}%")
                ->orWhere('trade_name','like',"%{$term}%")
                ->orWhere('document','like',"%{$term}%")
                ->orWhere('email','like',"%{$term}%")))
            ->orderBy('name')->paginate(12)->withQueryString();
        return view('customers.index',compact('term','customers'));
    }

    public function lookupCnpj(string $cnpj) {
        $cnpj=strtoupper(preg_replace('/[^0-9A-Z]/','',$cnpj));

        if(strlen($cnpj)!==14) {
            return response()->json([
                'message'=>'Informe um CNPJ válido com 14 caracteres.',
            ],422);
        }

        $request=Http::acceptJson()
            ->withHeaders([
                'User-Agent'=>'NextorERP/1.0',
            ])
            ->connectTimeout(5)
            ->timeout(10);

        try {
            $response=$request->get('https://brasilapi.com.br/api/cnpj/v1/'.$cnpj);
        } catch (\Throwable $e) {
            report($e);

            // Em Laragon/Windows é comum o PHP local estar sem um CA bundle configurado.
            // Mantemos verificação SSL normal em produção e usamos este fallback apenas no ambiente local.
            if(app()->environment('local')) {
                try {
                    $response=$request
                        ->withoutVerifying()
                        ->get('https://brasilapi.com.br/api/cnpj/v1/'.$cnpj);
                } catch (\Throwable $localException) {
                    report($localException);

                    return response()->json([
                        'message'=>'Falha ao acessar a BrasilAPI pelo PHP local: '.$localException->getMessage(),
                    ],503);
                }
            } else {
                return response()->json([
                    'message'=>'O serviço de consulta de CNPJ não respondeu. Tente novamente em alguns instantes.',
                ],503);
            }
        }

        if($response->successful()) {
            return response()->json($response->json());
        }

        if($response->status()===404) {
            return response()->json([
                'message'=>'CNPJ não encontrado.',
            ],404);
        }

        if($response->status()===400) {
            return response()->json([
                'message'=>'CNPJ inválido ou mal formatado.',
            ],422);
        }

        if(in_array($response->status(),[403,429],true)) {
            return response()->json([
                'message'=>'O serviço de consulta está temporariamente limitando requisições. Tente novamente em instantes.',
            ],503);
        }

        return response()->json([
            'message'=>'Não foi possível consultar o CNPJ agora.',
        ],503);
    }

    public function create() {
        return view('customers.form',[
            'customer'=>new Customer([
                'is_customer'=>true,
                'ie_indicator'=>'non_contributor',
                'lgpd_legal_basis'=>'default',
            ]),
            'editing'=>false,
        ]);
    }

    public function edit(Customer $customer) {
        $customer->load('deliveryAddresses');
        return view('customers.form',compact('customer')+['editing'=>true]);
    }

    private function rules(): array {
        return [
            'name'=>['required','string','max:190'],
            'trade_name'=>['nullable','string','max:190'],
            'document'=>['nullable','string','max:20'],
            'contact_name'=>['nullable','string','max:190'],
            'is_customer'=>['nullable','boolean'],
            'is_supplier'=>['nullable','boolean'],
            'is_carrier'=>['nullable','boolean'],
            'email'=>['nullable','email','max:255'],
            'phone'=>['nullable','string','max:25'],

            'zip_code'=>['nullable','string','max:10'],
            'state'=>['nullable','string','size:2'],
            'city'=>['nullable','string','max:120'],
            'address'=>['nullable','string','max:190'],
            'address_number'=>['nullable','string','max:30'],
            'address_complement'=>['nullable','string','max:120'],
            'district'=>['nullable','string','max:120'],

            'final_consumer'=>['nullable','boolean'],
            'ie_indicator'=>['nullable','string','max:32'],
            'state_registration'=>['nullable','string','max:40'],
            'substitute_state_registration'=>['nullable','string','max:40'],
            'municipal_registration'=>['nullable','string','max:40'],
            'suframa'=>['nullable','string','max:40'],
            'government_entity'=>['nullable','string','max:40'],
            'rntrc'=>['nullable','string','max:40'],
            'carrier_type'=>['nullable','string','max:40'],
            'driver_license'=>['nullable','string','max:40'],

            'birth_date'=>['nullable','date'],
            'keywords'=>['nullable','string','max:500'],
            'celebration_date'=>['nullable','date'],
            'celebration_note'=>['nullable','string','max:190'],
            'lgpd_legal_basis'=>['nullable','string','max:80'],
            'notes'=>['nullable','string','max:5000'],

            'delivery_addresses'=>['nullable','array','max:20'],
            'delivery_addresses.*.name'=>['nullable','string','max:190'],
            'delivery_addresses.*.document'=>['nullable','string','max:20'],
            'delivery_addresses.*.state_registration'=>['nullable','string','max:40'],
            'delivery_addresses.*.zip_code'=>['nullable','string','max:10'],
            'delivery_addresses.*.state'=>['nullable','string','size:2'],
            'delivery_addresses.*.city'=>['nullable','string','max:120'],
            'delivery_addresses.*.address'=>['nullable','string','max:190'],
            'delivery_addresses.*.address_number'=>['nullable','string','max:30'],
            'delivery_addresses.*.address_complement'=>['nullable','string','max:120'],
            'delivery_addresses.*.district'=>['nullable','string','max:120'],
            'delivery_addresses.*.email'=>['nullable','email','max:255'],
            'delivery_addresses.*.phone'=>['nullable','string','max:25'],
        ];
    }

    private function persist(Request $request, ?Customer $customer=null): Customer {
        $data=$request->validate($this->rules());
        $addresses=$data['delivery_addresses'] ?? [];
        unset($data['delivery_addresses']);

        foreach(['is_customer','is_supplier','is_carrier','final_consumer'] as $flag) {
            $data[$flag]=$request->boolean($flag);
        }
        if(!$data['is_customer'] && !$data['is_supplier'] && !$data['is_carrier']) {
            $data['is_customer']=true;
        }

        return DB::transaction(function() use ($customer,$data,$addresses) {
            if($customer) {
                $customer->update($data);
                $customer->deliveryAddresses()->delete();
            } else {
                $customer=Customer::create($data);
            }

            foreach($addresses as $address) {
                $hasData=collect($address)->filter(fn($v)=>$v!==null && $v!=='')->isNotEmpty();
                if($hasData) $customer->deliveryAddresses()->create($address);
            }
            return $customer;
        });
    }

    public function store(Request $request) {
        $this->persist($request);
        return redirect()->route('customers.index')->with('success','Cliente cadastrado.');
    }

    public function update(Request $request,Customer $customer) {
        $this->persist($request,$customer);
        return redirect()->route('customers.index')->with('success','Cliente atualizado.');
    }

    public function bulkDuplicate(Request $request) {
        $ids=$request->validate(['ids'=>['required','array','min:1'],'ids.*'=>['integer','exists:customers,id']])['ids'];
        $count=0;
        DB::transaction(function() use ($ids,&$count) {
            Customer::with('deliveryAddresses')->whereIn('id',$ids)->orderBy('id')->get()->each(function(Customer $customer) use (&$count) {
                $copy=$customer->replicate();
                $copy->name=$customer->name.' (cópia)';
                $copy->document=null;
                $copy->save();
                foreach($customer->deliveryAddresses as $address) {
                    $copy->deliveryAddresses()->create($address->only([
                        'name','document','state_registration','zip_code','state','city','address',
                        'address_number','address_complement','district','email','phone'
                    ]));
                }
                $count++;
            });
        });
        return redirect()->route('customers.index')->with('success',"{$count} cliente(s) duplicado(s).");
    }

    public function bulkDelete(Request $request) {
        $ids=$request->validate(['ids'=>['required','array','min:1'],'ids.*'=>['integer','exists:customers,id']])['ids'];
        $deleted=0;$blocked=0;
        DB::transaction(function() use ($ids,&$deleted,&$blocked) {
            Customer::whereIn('id',$ids)->get()->each(function(Customer $customer) use (&$deleted,&$blocked) {
                if (Sale::where('customer_id',$customer->id)->exists()) { $blocked++; return; }
                $customer->delete(); $deleted++;
            });
        });
        $message="{$deleted} cliente(s) excluído(s).";
        if($blocked) $message.=" {$blocked} não foram excluídos porque possuem vendas vinculadas.";
        return redirect()->route('customers.index')->with('success',$message);
    }
}
