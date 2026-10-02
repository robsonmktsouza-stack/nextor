<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\FinancialBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public const TABS=[
        'general','chart','accounts','operations','payments','pdv','billing',
        'fiscal','nfe','nfce','nfse','cte','users','integrations','system',
    ];

    public const PERMISSIONS=[
        'dashboard'=>'Dashboard',
        'finance'=>'Financeiro',
        'products'=>'Produtos',
        'services'=>'Serviços',
        'customers'=>'Clientes',
        'sales'=>'Vendas',
        'returns'=>'Devoluções',
        'stock'=>'Estoque',
        'pdv'=>'PDV',
        'settings'=>'Configurações',
        'fiscal'=>'Fiscal',
    ];

    public function index(Request $request, FinancialBalanceService $balances)
    {
        $tab=(string)$request->query('tab','general');
        if(!in_array($tab,self::TABS,true)) $tab='general';

        return view('settings.index',[
            'tab'=>$tab,
            'company'=>CompanySetting::current(),
            'categories'=>FinancialCategory::query()->orderBy('type')->orderBy('name')->get(),
            'accounts'=>$balances->accounts(false),
            'paymentMethods'=>PaymentMethod::query()->with('financialAccount')->orderBy('sort_order')->orderBy('name')->get(),
            'users'=>User::query()->orderBy('name')->get(),
            'permissions'=>self::PERMISSIONS,
            'operations'=>AppSetting::groupValues('operations',$this->defaults('operations')),
            'pdv'=>AppSetting::groupValues('pdv',$this->defaults('pdv')),
            'billing'=>AppSetting::groupValues('billing',$this->defaults('billing')),
            'fiscal'=>AppSetting::groupValues('fiscal',$this->defaults('fiscal')),
            'nfe'=>AppSetting::groupValues('nfe',$this->defaults('nfe')),
            'nfce'=>AppSetting::groupValues('nfce',$this->defaults('nfce')),
            'nfse'=>AppSetting::groupValues('nfse',$this->defaults('nfse')),
            'cte'=>AppSetting::groupValues('cte',$this->defaults('cte')),
            'integrations'=>AppSetting::groupValues('integrations',$this->defaults('integrations')),
            'system'=>AppSetting::groupValues('system',$this->defaults('system')),
        ]);
    }

    public function updateCompany(Request $request)
    {
        $data=$request->validate([
            'document'=>['nullable','string','max:20'],
            'legal_name'=>['nullable','string','max:190'],
            'trade_name'=>['nullable','string','max:190'],
            'state_registration'=>['nullable','string','max:40'],
            'municipal_registration'=>['nullable','string','max:40'],
            'cnae_main'=>['nullable','string','max:12'],
            'phone'=>['nullable','string','max:30'],
            'email'=>['nullable','email','max:255'],
            'zip_code'=>['nullable','string','max:10'],
            'state'=>['nullable','string','size:2'],
            'city'=>['nullable','string','max:120'],
            'city_ibge_code'=>['nullable','string','max:12'],
            'address'=>['nullable','string','max:190'],
            'address_number'=>['nullable','string','max:30'],
            'address_complement'=>['nullable','string','max:120'],
            'district'=>['nullable','string','max:120'],
            'tax_regime'=>['nullable','string','max:60'],
            'crt'=>['nullable','string','max:4'],
            'simple_rate'=>['nullable','numeric','min:0','max:100','decimal:0,4'],
            'main_activity'=>['nullable','string','max:40'],
            'print_header'=>['nullable','string','max:5000'],
            'print_footer'=>['nullable','string','max:5000'],
            'timezone'=>['required','timezone'],
            'logo'=>['nullable','image','mimes:jpg,jpeg,png,webp','max:4096'],
        ]);

        $company=CompanySetting::current();
        $data['show_currency_prefix']=$request->boolean('show_currency_prefix');

        if($request->hasFile('logo')) {
            if($company->logo_path) Storage::disk('public')->delete($company->logo_path);
            $data['logo_path']=$request->file('logo')->store('company','public');
        }

        unset($data['logo']);
        $company->update($data);

        return redirect()->route('settings.index',['tab'=>'general'])->with('success','Configurações gerais salvas.');
    }

    public function updateGroup(Request $request,string $group)
    {
        [$rules,$booleanKeys,$secretKeys]=$this->groupRules($group);
        $data=$request->validate($rules);

        foreach($booleanKeys as $key) {
            $data[$key]=$request->boolean($key);
        }

        foreach($rules as $key=>$_rules) {
            if(in_array($key,$booleanKeys,true)) continue;
            if(!array_key_exists($key,$data)) $data[$key]=null;
        }

        foreach($data as $key=>$value) {
            $secret=in_array($key,$secretKeys,true);

            // Campo secreto vazio preserva a credencial já armazenada.
            if($secret && ($value===null || $value==='')) continue;

            AppSetting::put($group,$key,$value,$secret);
        }

        $tab=match($group){
            'operations'=>'operations','pdv'=>'pdv','billing'=>'billing','fiscal'=>'fiscal',
            'nfe'=>'nfe','nfce'=>'nfce','nfse'=>'nfse','cte'=>'cte',
            'integrations'=>'integrations','system'=>'system',
            default=>'general',
        };

        return redirect()->route('settings.index',['tab'=>$tab])->with('success','Configurações salvas.');
    }

    public function uploadCertificate(Request $request)
    {
        $data=$request->validate([
            'certificate'=>['required','file','mimes:pfx,p12','max:10240'],
            'certificate_password'=>['required','string','max:255'],
        ]);

        $bytes=file_get_contents($request->file('certificate')->getRealPath());
        $expiresAt=null;

        if(function_exists('openssl_pkcs12_read')) {
            $certs=[];
            if(!@openssl_pkcs12_read($bytes,$certs,$data['certificate_password'])) {
                throw ValidationException::withMessages([
                    'certificate'=>'Não foi possível abrir o certificado A1. Confira o arquivo e a senha.',
                ]);
            }

            if(!empty($certs['cert'])) {
                $parsed=@openssl_x509_parse($certs['cert']);
                if(!empty($parsed['validTo_time_t'])) {
                    $expiresAt=date('Y-m-d H:i:s',(int)$parsed['validTo_time_t']);
                }
            }
        }

        $company=CompanySetting::current();
        if($company->certificate_path) Storage::disk('local')->delete($company->certificate_path);

        $extension=strtolower($request->file('certificate')->getClientOriginalExtension()) ?: 'pfx';
        $path='fiscal/certificates/a1_'.now()->format('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$extension;
        Storage::disk('local')->put($path,$bytes);

        $company->update([
            'certificate_path'=>$path,
            'certificate_password'=>$data['certificate_password'],
            'certificate_expires_at'=>$expiresAt,
        ]);

        return redirect()->route('settings.index',['tab'=>'fiscal'])->with('success','Certificado A1 armazenado com segurança.');
    }

    public function removeCertificate()
    {
        $company=CompanySetting::current();
        if($company->certificate_path) Storage::disk('local')->delete($company->certificate_path);

        $company->update([
            'certificate_path'=>null,
            'certificate_password'=>null,
            'certificate_expires_at'=>null,
        ]);

        return redirect()->route('settings.index',['tab'=>'fiscal'])->with('success','Certificado removido.');
    }

    public function storePaymentMethod(Request $request)
    {
        $data=$this->validatePaymentMethod($request);
        $data['is_active']=$request->boolean('is_active',true);
        $data['pdv_enabled']=$request->boolean('pdv_enabled',true);

        PaymentMethod::query()->create($data);

        return redirect()->route('settings.index',['tab'=>'payments'])->with('success','Forma de pagamento criada.');
    }

    public function updatePaymentMethod(Request $request, PaymentMethod $paymentMethod)
    {
        $data=$this->validatePaymentMethod($request,$paymentMethod);
        $data['is_active']=$request->boolean('is_active');
        $data['pdv_enabled']=$request->boolean('pdv_enabled');

        $paymentMethod->update($data);

        return redirect()->route('settings.index',['tab'=>'payments'])->with('success','Forma de pagamento atualizada.');
    }

    public function storeUser(Request $request)
    {
        $data=$request->validate([
            'name'=>['required','string','max:190'],
            'email'=>['required','email','max:255','unique:users,email'],
            'password'=>['required','string','min:8','max:255'],
            'role'=>['required',Rule::in(['admin','manager','finance','sales','operator'])],
            'permissions'=>['nullable','array'],
            'permissions.*'=>['string',Rule::in(array_keys(self::PERMISSIONS))],
        ]);

        $data['is_active']=true;
        $data['permissions']=$data['role']==='admin' ? array_keys(self::PERMISSIONS) : ($data['permissions'] ?? []);
        User::query()->create($data);

        return redirect()->route('settings.index',['tab'=>'users'])->with('success','Usuário criado.');
    }

    public function updateUser(Request $request, User $user)
    {
        $data=$request->validate([
            'name'=>['required','string','max:190'],
            'email'=>['required','email','max:255',Rule::unique('users','email')->ignore($user->id)],
            'password'=>['nullable','string','min:8','max:255'],
            'role'=>['required',Rule::in(['admin','manager','finance','sales','operator'])],
            'permissions'=>['nullable','array'],
            'permissions.*'=>['string',Rule::in(array_keys(self::PERMISSIONS))],
        ]);

        $active=$request->boolean('is_active');
        if($request->user()->is($user) && !$active) {
            throw ValidationException::withMessages(['is_active'=>'Você não pode desativar o próprio usuário.']);
        }

        if(!$data['password']) unset($data['password']);
        $data['is_active']=$active;
        $data['permissions']=$data['role']==='admin' ? array_keys(self::PERMISSIONS) : ($data['permissions'] ?? []);
        $user->update($data);

        return redirect()->route('settings.index',['tab'=>'users'])->with('success','Usuário atualizado.');
    }

    private function validatePaymentMethod(Request $request,?PaymentMethod $method=null): array
    {
        return $request->validate([
            'code'=>['required','string','max:40','alpha_dash',Rule::unique('payment_methods','code')->ignore($method?->id)],
            'name'=>['required','string','max:120'],
            'kind'=>['required',Rule::in(['cash','pix','card','bank_slip','transfer','other'])],
            'financial_account_id'=>['nullable','integer','exists:financial_accounts,id'],
            'fee_percent'=>['required','numeric','min:0','max:100','decimal:0,4'],
            'fee_fixed'=>['required','numeric','min:0','max:9999999999.99','decimal:0,2'],
            'settlement_days'=>['required','integer','min:0','max:3650'],
            'sort_order'=>['required','integer','min:0','max:9999'],
        ]);
    }

    private function groupRules(string $group): array
    {
        return match($group) {
            'operations'=>[[
                'default_final_consumer'=>['nullable','boolean'],
                'allow_negative_stock'=>['nullable','boolean'],
                'auto_finance_sale'=>['nullable','boolean'],
                'allow_partial_return'=>['nullable','boolean'],
                'quote_valid_days'=>['required','integer','min:0','max:3650'],
                'default_due_days'=>['required','integer','min:0','max:3650'],
                'default_financial_account_id'=>['nullable','integer','exists:financial_accounts,id'],
                'default_income_category_id'=>['nullable','integer','exists:financial_categories,id'],
                'default_expense_category_id'=>['nullable','integer','exists:financial_categories,id'],
            ],['default_final_consumer','allow_negative_stock','auto_finance_sale','allow_partial_return'],[]],

            'pdv'=>[[
                'default_payment_method'=>['nullable','string','exists:payment_methods,code'],
                'require_customer'=>['nullable','boolean'],
                'allow_discount'=>['nullable','boolean'],
                'require_cash_opening'=>['nullable','boolean'],
                'auto_nfce'=>['nullable','boolean'],
                'show_stock'=>['nullable','boolean'],
                'receipt_width'=>['required',Rule::in(['58','80'])],
                'receipt_copies'=>['required','integer','min:1','max:5'],
            ],['require_customer','allow_discount','require_cash_opening','auto_nfce','show_stock'],[]],

            'billing'=>[[
                'enabled'=>['nullable','boolean'],
                'provider'=>['nullable','string','max:80'],
                'default_financial_account_id'=>['nullable','integer','exists:financial_accounts,id'],
                'pix_key'=>['nullable','string','max:255'],
                'default_due_days'=>['required','integer','min:0','max:3650'],
                'fine_percent'=>['required','numeric','min:0','max:100','decimal:0,4'],
                'interest_monthly_percent'=>['required','numeric','min:0','max:100','decimal:0,4'],
                'instructions'=>['nullable','string','max:2000'],
                'api_key'=>['nullable','string','max:2000'],
                'api_secret'=>['nullable','string','max:2000'],
            ],['enabled'],['api_key','api_secret']],

            'fiscal'=>[[
                'enabled'=>['nullable','boolean'],
                'default_environment'=>['required',Rule::in(['homologation','production'])],
                'send_xml_email'=>['nullable','boolean'],
                'keep_xml_copy'=>['nullable','boolean'],
                'accountant_email'=>['nullable','email','max:255'],
                'tax_profile'=>['nullable','string','max:120'],
            ],['enabled','send_xml_email','keep_xml_copy'],[]],

            'nfe'=>[[
                'enabled'=>['nullable','boolean'],
                'environment'=>['required',Rule::in(['homologation','production'])],
                'series'=>['required','integer','min:0','max:999'],
                'next_number'=>['required','integer','min:1','max:999999999'],
                'default_nature'=>['nullable','string','max:120'],
                'default_cfop'=>['nullable','string','max:10'],
                'auto_from_sale'=>['nullable','boolean'],
                'send_email'=>['nullable','boolean'],
                'print_danfe'=>['nullable','boolean'],
            ],['enabled','auto_from_sale','send_email','print_danfe'],[]],

            'nfce'=>[[
                'enabled'=>['nullable','boolean'],
                'environment'=>['required',Rule::in(['homologation','production'])],
                'series'=>['required','integer','min:0','max:999'],
                'next_number'=>['required','integer','min:1','max:999999999'],
                'csc_id'=>['nullable','string','max:20'],
                'csc_token'=>['nullable','string','max:255'],
                'auto_from_pdv'=>['nullable','boolean'],
                'print_danfe'=>['nullable','boolean'],
            ],['enabled','auto_from_pdv','print_danfe'],['csc_token']],

            'nfse'=>[[
                'enabled'=>['nullable','boolean'],
                'environment'=>['required',Rule::in(['homologation','production'])],
                'provider'=>['nullable','string','max:120'],
                'municipality_code'=>['nullable','string','max:12'],
                'series'=>['nullable','string','max:20'],
                'next_rps'=>['required','integer','min:1','max:999999999'],
                'default_service_tax_code'=>['nullable','string','max:60'],
                'municipal_login'=>['nullable','string','max:255'],
                'municipal_password'=>['nullable','string','max:2000'],
                'auto_from_sale'=>['nullable','boolean'],
                'withhold_iss_default'=>['nullable','boolean'],
            ],['enabled','auto_from_sale','withhold_iss_default'],['municipal_password']],

            'cte'=>[[
                'cte_enabled'=>['nullable','boolean'],
                'cte_environment'=>['required',Rule::in(['homologation','production'])],
                'cte_series'=>['required','integer','min:0','max:999'],
                'cte_next_number'=>['required','integer','min:1','max:999999999'],
                'rntrc'=>['nullable','string','max:30'],
                'default_cfop'=>['nullable','string','max:10'],
                'mdfe_enabled'=>['nullable','boolean'],
                'mdfe_environment'=>['required',Rule::in(['homologation','production'])],
                'mdfe_series'=>['required','integer','min:0','max:999'],
                'mdfe_next_number'=>['required','integer','min:1','max:999999999'],
            ],['cte_enabled','mdfe_enabled'],[]],

            'integrations'=>[[
                'smtp_enabled'=>['nullable','boolean'],
                'smtp_host'=>['nullable','string','max:255'],
                'smtp_port'=>['required','integer','min:1','max:65535'],
                'smtp_encryption'=>['nullable',Rule::in(['','tls','ssl'])],
                'smtp_username'=>['nullable','string','max:255'],
                'smtp_password'=>['nullable','string','max:2000'],
                'smtp_from_address'=>['nullable','email','max:255'],
                'smtp_from_name'=>['nullable','string','max:190'],
                'api_enabled'=>['nullable','boolean'],
                'webhook_url'=>['nullable','url','max:2000'],
                'webhook_secret'=>['nullable','string','max:2000'],
                'accounting_integration'=>['nullable','string','max:120'],
            ],['smtp_enabled','api_enabled'],['smtp_password','webhook_secret']],

            'system'=>[[
                'rows_per_page'=>['required',Rule::in(['10','25','50','100'])],
                'search_delay'=>['required','integer','min:120','max:1000'],
                'date_format'=>['required',Rule::in(['d/m/Y','Y-m-d'])],
                'compact_mode'=>['nullable','boolean'],
                'show_tutorials'=>['nullable','boolean'],
                'confirm_destructive_actions'=>['nullable','boolean'],
            ],['compact_mode','show_tutorials','confirm_destructive_actions'],[]],

            default=>throw ValidationException::withMessages(['group'=>'Grupo de configuração inválido.']),
        };
    }

    private function defaults(string $group): array
    {
        return match($group) {
            'operations'=>[
                'default_final_consumer'=>true,'allow_negative_stock'=>false,'auto_finance_sale'=>true,
                'allow_partial_return'=>true,'quote_valid_days'=>15,'default_due_days'=>0,
                'default_financial_account_id'=>null,'default_income_category_id'=>null,'default_expense_category_id'=>null,
            ],
            'pdv'=>[
                'default_payment_method'=>null,'require_customer'=>false,'allow_discount'=>true,
                'require_cash_opening'=>false,'auto_nfce'=>false,'show_stock'=>true,'receipt_width'=>'80','receipt_copies'=>1,
            ],
            'billing'=>[
                'enabled'=>false,'provider'=>null,'default_financial_account_id'=>null,'pix_key'=>null,
                'default_due_days'=>3,'fine_percent'=>'0.0000','interest_monthly_percent'=>'0.0000','instructions'=>null,
                'api_key'=>null,'api_secret'=>null,
            ],
            'fiscal'=>[
                'enabled'=>false,'default_environment'=>'homologation','send_xml_email'=>true,
                'keep_xml_copy'=>true,'accountant_email'=>null,'tax_profile'=>null,
            ],
            'nfe'=>[
                'enabled'=>false,'environment'=>'homologation','series'=>1,'next_number'=>1,
                'default_nature'=>null,'default_cfop'=>null,'auto_from_sale'=>false,'send_email'=>true,'print_danfe'=>true,
            ],
            'nfce'=>[
                'enabled'=>false,'environment'=>'homologation','series'=>1,'next_number'=>1,
                'csc_id'=>null,'csc_token'=>null,'auto_from_pdv'=>false,'print_danfe'=>true,
            ],
            'nfse'=>[
                'enabled'=>false,'environment'=>'homologation','provider'=>null,'municipality_code'=>null,
                'series'=>null,'next_rps'=>1,'default_service_tax_code'=>null,'municipal_login'=>null,
                'municipal_password'=>null,'auto_from_sale'=>false,'withhold_iss_default'=>false,
            ],
            'cte'=>[
                'cte_enabled'=>false,'cte_environment'=>'homologation','cte_series'=>1,'cte_next_number'=>1,
                'rntrc'=>null,'default_cfop'=>null,'mdfe_enabled'=>false,'mdfe_environment'=>'homologation',
                'mdfe_series'=>1,'mdfe_next_number'=>1,
            ],
            'integrations'=>[
                'smtp_enabled'=>false,'smtp_host'=>null,'smtp_port'=>587,'smtp_encryption'=>'tls',
                'smtp_username'=>null,'smtp_password'=>null,'smtp_from_address'=>null,'smtp_from_name'=>null,
                'api_enabled'=>false,'webhook_url'=>null,'webhook_secret'=>null,'accounting_integration'=>null,
            ],
            'system'=>[
                'rows_per_page'=>'25','search_delay'=>240,'date_format'=>'d/m/Y',
                'compact_mode'=>true,'show_tutorials'=>true,'confirm_destructive_actions'=>true,
            ],
            default=>[],
        };
    }
}
