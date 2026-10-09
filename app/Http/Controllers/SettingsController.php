<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FiscalDocumentJob;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\AccountingExportService;
use App\Services\FinancialBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public const TABS=[
        'general','printing','catalog','operations','inventory','pdv',
        'chart','accounts','payments','billing',
        'fiscal','tax','nfe','nfce','nfse','cte',
        'accounting','users','integrations','system',
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

    public function index(Request $request, FinancialBalanceService $balances, AccountingExportService $accountingExports)
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
            'catalog'=>AppSetting::groupValues('catalog',$this->defaults('catalog')),
            'operations'=>AppSetting::groupValues('operations',$this->defaults('operations')),
            'inventory'=>AppSetting::groupValues('inventory',array_replace(
                $this->defaults('inventory'),
                ['allow_negative_stock'=>(bool)AppSetting::value('operations','allow_negative_stock',false)]
            )),
            'pdv'=>AppSetting::groupValues('pdv',$this->defaults('pdv')),
            'printing'=>AppSetting::groupValues('printing',[
                'nfce_paper'=>(string)AppSetting::value('pdv','receipt_width','80'),
            ]),
            'billing'=>AppSetting::groupValues('billing',$this->defaults('billing')),
            'fiscal'=>AppSetting::groupValues('fiscal',$this->defaults('fiscal')),
            'tax'=>AppSetting::groupValues('tax',$this->defaults('tax')),
            'nfe'=>AppSetting::groupValues('nfe',$this->defaults('nfe')),
            'nfce'=>AppSetting::groupValues('nfce',$this->defaults('nfce')),
            'nfse'=>AppSetting::groupValues('nfse',$this->defaults('nfse')),
            'cte'=>AppSetting::groupValues('cte',$this->defaults('cte')),
            'accounting'=>AppSetting::groupValues('accounting',$this->defaults('accounting')),
            'integrations'=>AppSetting::groupValues('integrations',$this->defaults('integrations')),
            'system'=>AppSetting::groupValues('system',$this->defaults('system')),
            'accountingExports'=>$accountingExports->listExports(),
            'fiscalJobs'=>FiscalDocumentJob::query()->with('sale')->latest('id')->limit(12)->get(),
            'webhookStats'=>WebhookDelivery::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total','status')
                ->all(),
            'apiTokenConfigured'=>filled(AppSetting::value('integrations','api_token','')),
        ]);
    }

    public function companyLogo()
    {
        $company=CompanySetting::current();

        abort_unless(
            $company->logo_path && Storage::disk('public')->exists($company->logo_path),
            404
        );

        $disk=Storage::disk('public');
        $mime=$disk->mimeType($company->logo_path) ?: 'application/octet-stream';

        return response($disk->get($company->logo_path),200,[
            'Content-Type'=>$mime,
            'Cache-Control'=>'private, max-age=3600',
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
            'crt'=>['nullable',Rule::in(['1','2','3','4'])],
            'simple_rate'=>['nullable','numeric','min:0','max:100','decimal:0,4'],
            'main_activity'=>['nullable','string','max:40'],
            'print_header'=>['nullable','string','max:5000'],
            'print_footer'=>['nullable','string','max:5000'],
            'timezone'=>['required','timezone'],
            'logo'=>['nullable','image','mimes:jpg,jpeg,png,webp','max:4096'],
        ]);

        $company=CompanySetting::current();
        $regime=(string)($data['tax_regime'] ?? $company->tax_regime ?? '');
        $crt=(string)($data['crt'] ?? $company->crt ?? '');
        $validForRegime=match($regime) {
            'mei'=>['4'],
            'simples_nacional'=>['1','2'],
            'lucro_real','lucro_presumido'=>['3'],
            default=>[],
        };
        if ($crt!=='' && $validForRegime!==[] && !in_array($crt,$validForRegime,true)) {
            throw ValidationException::withMessages([
                'crt'=>'O CRT selecionado não corresponde ao regime tributário da empresa.',
            ]);
        }
        $data['show_currency_prefix']=$request->boolean('show_currency_prefix');

        if($request->hasFile('logo')) {
            if($company->logo_path) Storage::disk('public')->delete($company->logo_path);
            $data['logo_path']=$request->file('logo')->store('company','public');
        }

        unset($data['logo']);
        $company->update($data);

        return redirect()->route('settings.index',['tab'=>'general'])->with('success','Configurações gerais salvas.');
    }

    public function updatePrinting(Request $request)
    {
        $data=$request->validate([
            'print_header'=>['nullable','string','max:5000'],
            'print_footer'=>['nullable','string','max:5000'],
            'nfce_paper'=>['required',Rule::in(['58','80','a4'])],
            'logo'=>['nullable','image','mimes:jpg,jpeg,png,webp','max:4096'],
        ]);

        AppSetting::put('printing','nfce_paper',$data['nfce_paper']);
        unset($data['nfce_paper']);

        $company=CompanySetting::current();
        $data['show_currency_prefix']=$request->boolean('show_currency_prefix');

        if($request->hasFile('logo')) {
            if($company->logo_path) Storage::disk('public')->delete($company->logo_path);
            $data['logo_path']=$request->file('logo')->store('company','public');
        }

        unset($data['logo']);
        $company->update($data);

        return redirect()->route('settings.index',['tab'=>'printing'])->with('success','Configurações de impressão salvas.');
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
            'catalog'=>'catalog','operations'=>'operations','inventory'=>'inventory','pdv'=>'pdv',
            'billing'=>'billing','fiscal'=>'fiscal','tax'=>'tax','nfe'=>'nfe','nfce'=>'nfce',
            'nfse'=>'nfse','cte'=>'cte','accounting'=>'accounting','integrations'=>'integrations',
            'system'=>'system',
            default=>'general',
        };

        return redirect()->route('settings.index',['tab'=>$tab])->with('success','Configurações salvas.');
    }

    public function regenerateApiToken()
    {
        $token=Str::random(64);
        AppSetting::put('integrations','api_token',$token,true);

        return redirect()
            ->route('settings.index',['tab'=>'integrations'])
            ->with('success','Novo token da API gerado.')
            ->with('api_token_plain',$token);
    }

    public function exportAccounting(Request $request, AccountingExportService $exports)
    {
        $data=$request->validate([
            'month'=>['required','regex:/^\\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $path=$exports->generate($data['month']);

        return Storage::disk('local')->download($path,basename($path),[
            'Content-Type'=>'text/csv; charset=UTF-8',
        ]);
    }

    public function uploadCertificate(Request $request)
    {
        $data=$request->validate([
            'certificate'=>['required','file','extensions:pfx,p12','max:10240'],
            'certificate_password'=>['required','string','max:255'],
        ]);

        $bytes=file_get_contents($request->file('certificate')->getRealPath());
        $expiresAt=null;

        if(!function_exists('openssl_pkcs12_read')) {
            throw ValidationException::withMessages([
                'certificate'=>'A extensão OpenSSL precisa estar habilitada para validar o certificado A1.',
            ]);
        }

        {
            $certs=[];
            if(!@openssl_pkcs12_read($bytes,$certs,$data['certificate_password'])) {
                throw ValidationException::withMessages([
                    'certificate'=>'Certificado PKCS#12 inválido ou senha incorreta. Confira o arquivo .pfx/.p12 e a senha.',
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

        if($data['role']!=='admin' && empty($data['permissions'])) {
            throw ValidationException::withMessages(['permissions'=>'Selecione pelo menos uma permissão para este usuário.']);
        }

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

        if($data['role']!=='admin' && empty($data['permissions'])) {
            throw ValidationException::withMessages(['permissions'=>'Selecione pelo menos uma permissão para este usuário.']);
        }

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
            'catalog'=>[[
                'product_unit'=>['required','string','max:12'],
                'product_usage_type'=>['required',Rule::in(['resale','consumption','raw_material','fixed_asset','packaging','other'])],
                'product_control_stock'=>['nullable','boolean'],
                'product_minimum_stock'=>['required','numeric','min:0','max:9999999999','decimal:0,3'],
                'new_products_active'=>['nullable','boolean'],
                'new_services_active'=>['nullable','boolean'],
            ],['product_control_stock','new_products_active','new_services_active'],[]],

            'inventory'=>[[
                'allow_negative_stock'=>['nullable','boolean'],
                'minimum_stock_alerts'=>['nullable','boolean'],
                'stock_decimal_places'=>['required',Rule::in(['0','1','2','3'])],
                'default_adjustment_reason'=>['nullable','string','max:255'],
            ],['allow_negative_stock','minimum_stock_alerts'],[]],

            'operations'=>[[
                'default_final_consumer'=>['nullable','boolean'],
                'auto_finance_sale'=>['nullable','boolean'],
                'allow_partial_return'=>['nullable','boolean'],
                'quote_valid_days'=>['required','integer','min:0','max:3650'],
                'default_due_days'=>['required','integer','min:0','max:3650'],
                'default_financial_account_id'=>['nullable','integer','exists:financial_accounts,id'],
                'default_income_category_id'=>['nullable','integer','exists:financial_categories,id'],
                'default_expense_category_id'=>['nullable','integer','exists:financial_categories,id'],
            ],['default_final_consumer','auto_finance_sale','allow_partial_return'],[]],

            'pdv'=>[[
                'default_payment_method'=>['nullable','string','exists:payment_methods,code'],
                'require_customer'=>['nullable','boolean'],
                'allow_discount'=>['nullable','boolean'],
                'require_cash_opening'=>['nullable','boolean'],
                'ask_consumer_document'=>['nullable','boolean'],
                'allow_split_payment'=>['nullable','boolean'],
                'allow_cash_movements'=>['nullable','boolean'],
                'auto_nfce'=>['nullable','boolean'],
                'show_stock'=>['nullable','boolean'],
                'receipt_width'=>['required',Rule::in(['58','80'])],
                'receipt_copies'=>['required','integer','min:1','max:5'],
            ],['require_customer','allow_discount','require_cash_opening','ask_consumer_document','allow_split_payment','allow_cash_movements','auto_nfce','show_stock'],[]],

            'billing'=>[[
                'enabled'=>['nullable','boolean'],
                'default_financial_account_id'=>['nullable','integer','exists:financial_accounts,id'],
                'pix_key'=>['nullable','string','max:255'],
                'default_due_days'=>['required','integer','min:0','max:3650'],
                'fine_percent'=>['required','numeric','min:0','max:100','decimal:0,4'],
                'interest_monthly_percent'=>['required','numeric','min:0','max:100','decimal:0,4'],
                'instructions'=>['nullable','string','max:2000'],
            ],['enabled'],[]],

            'fiscal'=>[[
                'enabled'=>['nullable','boolean'],
                'default_environment'=>['required',Rule::in(['homologation','production'])],
                'send_xml_email'=>['nullable','boolean'],
                'keep_xml_copy'=>['nullable','boolean'],
                'accountant_email'=>['nullable','email','max:255'],
                'tax_profile'=>['nullable','string','max:120'],
            ],['enabled','send_xml_email','keep_xml_copy'],[]],

            'tax'=>[[
                'icms_origin_default'=>['nullable','string','max:2'],
                'icms_csosn_default'=>['nullable','string','max:4'],
                'icms_cst_default'=>['nullable','string','max:4'],
                'pis_cst_default'=>['nullable','string','max:4'],
                'cofins_cst_default'=>['nullable','string','max:4'],
                'ipi_cst_default'=>['nullable','string','max:4'],
                'iss_rate_default'=>['nullable','numeric','min:0','max:100','decimal:0,4'],
                'simple_credit_rate'=>['nullable','numeric','min:0','max:100','decimal:0,4'],
                'ibs_cst_default'=>['nullable','string','max:10'],
                'cbs_cst_default'=>['nullable','string','max:10'],
                'tax_classification_code'=>['nullable','string','max:40'],
                'fcp_rate_default'=>['nullable','numeric','min:0','max:100','decimal:0,4'],
                'notes'=>['nullable','string','max:3000'],
            ],[],[]],

            'nfe'=>[[
                'enabled'=>['nullable','boolean'],
                'environment'=>['required',Rule::in(['homologation','production'])],
                'series'=>['required','integer','min:0','max:999'],
                'next_number'=>['required','integer','min:1','max:999999999'],
                'default_nature'=>['nullable','string','max:120'],
                'default_cfop'=>['nullable','string','max:10'],
                'auto_from_sale'=>['nullable','boolean'],
                'production_enabled'=>['nullable','boolean'],
                'send_email'=>['nullable','boolean'],
                'print_danfe'=>['nullable','boolean'],
            ],['enabled','auto_from_sale','production_enabled','send_email','print_danfe'],[]],

            'nfce'=>[[
                'enabled'=>['nullable','boolean'],
                'environment'=>['required',Rule::in(['homologation','production'])],
                'series'=>['required','integer','min:0','max:999'],
                'next_number'=>['required','integer','min:1','max:999999999'],
                'default_cfop'=>['nullable','string','regex:/^5\\d{3}$/'],
                'csc_id'=>['nullable','string','max:20'],
                'csc_token'=>['nullable','string','max:255'],
                'auto_from_pdv'=>['nullable','boolean'],
                'production_enabled'=>['nullable','boolean'],
                'print_danfe'=>['nullable','boolean'],
            ],['enabled','auto_from_pdv','production_enabled','print_danfe'],['csc_token']],

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
                'production_enabled'=>['nullable','boolean'],
            ],['enabled','auto_from_sale','withhold_iss_default','production_enabled'],['municipal_password']],

            'cte'=>[[
                'cte_enabled'=>['nullable','boolean'],
                'cte_environment'=>['required',Rule::in(['homologation','production'])],
                'cte_series'=>['required','integer','min:0','max:999'],
                'cte_next_number'=>['required','integer','min:1','max:999999999'],
                'rntrc'=>['nullable','string','max:30'],
                'default_cfop'=>['nullable','string','max:10'],
                'cte_production_enabled'=>['nullable','boolean'],
                'mdfe_production_enabled'=>['nullable','boolean'],
                'mdfe_enabled'=>['nullable','boolean'],
                'mdfe_environment'=>['required',Rule::in(['homologation','production'])],
                'mdfe_series'=>['required','integer','min:0','max:999'],
                'mdfe_next_number'=>['required','integer','min:1','max:999999999'],
            ],['cte_enabled','mdfe_enabled','cte_production_enabled','mdfe_production_enabled'],[]],

            'accounting'=>[[
                'office_name'=>['nullable','string','max:190'],
                'accountant_name'=>['nullable','string','max:190'],
                'accountant_document'=>['nullable','string','max:20'],
                'crc'=>['nullable','string','max:40'],
                'email'=>['nullable','email','max:255'],
                'phone'=>['nullable','string','max:30'],
                'accounting_system'=>['nullable','string','max:120'],
                'cost_center_enabled'=>['nullable','boolean'],
                'automatic_monthly_export'=>['nullable','boolean'],
                'notes'=>['nullable','string','max:3000'],
            ],['cost_center_enabled','automatic_monthly_export'],[]],

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
                'webhook_enabled'=>['nullable','boolean'],
                'webhook_url'=>['nullable','url','max:2000'],
                'webhook_secret'=>['nullable','string','max:2000'],
            ],['smtp_enabled','api_enabled','webhook_enabled'],['smtp_password','webhook_secret']],

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
            'catalog'=>[
                'product_unit'=>'UN','product_usage_type'=>'resale','product_control_stock'=>true,
                'product_minimum_stock'=>'0.000','new_products_active'=>true,'new_services_active'=>true,
            ],
            'inventory'=>[
                'allow_negative_stock'=>false,'minimum_stock_alerts'=>true,
                'stock_decimal_places'=>'3','default_adjustment_reason'=>null,
            ],
            'operations'=>[
                'default_final_consumer'=>true,'auto_finance_sale'=>true,
                'allow_partial_return'=>true,'quote_valid_days'=>15,'default_due_days'=>0,
                'default_financial_account_id'=>null,'default_income_category_id'=>null,'default_expense_category_id'=>null,
            ],
            'pdv'=>[
                'default_payment_method'=>null,'require_customer'=>false,'allow_discount'=>true,
                'require_cash_opening'=>false,'ask_consumer_document'=>true,'allow_split_payment'=>true,'allow_cash_movements'=>true,
                'auto_nfce'=>false,'show_stock'=>true,'receipt_width'=>'80','receipt_copies'=>1,
            ],
            'billing'=>[
                'enabled'=>false,'default_financial_account_id'=>null,'pix_key'=>null,
                'default_due_days'=>3,'fine_percent'=>'0.0000','interest_monthly_percent'=>'0.0000','instructions'=>null,
            ],
            'fiscal'=>[
                'enabled'=>false,'default_environment'=>'homologation','send_xml_email'=>true,
                'keep_xml_copy'=>true,'accountant_email'=>null,'tax_profile'=>null,
            ],
            'tax'=>[
                'icms_origin_default'=>'0','icms_csosn_default'=>null,'icms_cst_default'=>null,
                'pis_cst_default'=>null,'cofins_cst_default'=>null,'ipi_cst_default'=>null,
                'iss_rate_default'=>null,'simple_credit_rate'=>null,'ibs_cst_default'=>null,
                'cbs_cst_default'=>null,'tax_classification_code'=>null,'fcp_rate_default'=>null,'notes'=>null,
            ],
            'nfe'=>[
                'enabled'=>false,'environment'=>'homologation','series'=>1,'next_number'=>1,
                'default_nature'=>null,'default_cfop'=>null,'auto_from_sale'=>false,'production_enabled'=>false,'send_email'=>true,'print_danfe'=>true,
            ],
            'nfce'=>[
                'enabled'=>false,'environment'=>'homologation','series'=>1,'next_number'=>1,
                'default_cfop'=>null,'csc_id'=>null,'csc_token'=>null,'auto_from_pdv'=>false,'production_enabled'=>false,'print_danfe'=>true,
            ],
            'nfse'=>[
                'enabled'=>false,'environment'=>'homologation','provider'=>null,'municipality_code'=>null,
                'series'=>null,'next_rps'=>1,'default_service_tax_code'=>null,'municipal_login'=>null,
                'municipal_password'=>null,'auto_from_sale'=>false,'withhold_iss_default'=>false,'production_enabled'=>false,
            ],
            'cte'=>[
                'cte_enabled'=>false,'cte_environment'=>'homologation','cte_series'=>1,'cte_next_number'=>1,
                'rntrc'=>null,'default_cfop'=>null,'cte_production_enabled'=>false,'mdfe_production_enabled'=>false,'mdfe_enabled'=>false,'mdfe_environment'=>'homologation',
                'mdfe_series'=>1,'mdfe_next_number'=>1,
            ],
            'accounting'=>[
                'office_name'=>null,'accountant_name'=>null,'accountant_document'=>null,'crc'=>null,
                'email'=>null,'phone'=>null,'accounting_system'=>null,
                'cost_center_enabled'=>false,'automatic_monthly_export'=>false,'notes'=>null,
            ],
            'integrations'=>[
                'smtp_enabled'=>false,'smtp_host'=>null,'smtp_port'=>587,'smtp_encryption'=>'tls',
                'smtp_username'=>null,'smtp_password'=>null,'smtp_from_address'=>null,'smtp_from_name'=>null,
                'api_enabled'=>false,'webhook_enabled'=>false,'webhook_url'=>null,'webhook_secret'=>null,
            ],
            'system'=>[
                'rows_per_page'=>'25','search_delay'=>240,'date_format'=>'d/m/Y',
                'compact_mode'=>true,'show_tutorials'=>true,'confirm_destructive_actions'=>true,
            ],
            default=>[],
        };
    }
}
