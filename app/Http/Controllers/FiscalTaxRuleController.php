<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\FiscalTaxRule;
use App\Models\Product;
use App\Models\OperationNature;
use App\Models\FiscalTaxGroup;
use App\Services\Fiscal\FiscalTaxRuleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FiscalTaxRuleController extends Controller
{
    private const UFS = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
    public const TAX_PARAMETERS = \App\Services\Fiscal\Nfe55TaxParameters::RULE_PARAMETERS;

    public function index()
    {
        $company = CompanySetting::current();
        return view('fiscal.rules.index', [
            'rules' => FiscalTaxRule::query()->with('revisions')->orderByDesc('is_active')
                ->orderByDesc('priority')->orderBy('name')->get(),
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(['id','name','sku']),
            'ufs' => self::UFS, 'issuerUf' => strtoupper((string) $company->state),
            'issuerCrt' => (string) $company->crt,
            'operationNatures' => OperationNature::query()->orderBy('name')->get(['id','name','catalog_version']),
            'fiscalTaxGroups' => FiscalTaxGroup::query()->where('kind', 'products')->orderBy('name')->get(['id','name','catalog_version']),
        ]);
    }

    public function store(Request $request)
    {
        $this->save($request);
        return back()->with('success', 'Regra fiscal cadastrada.');
    }

    public function update(Request $request, FiscalTaxRule $fiscalTaxRule)
    {
        $this->save($request, $fiscalTaxRule->id);
        return back()->with('success', 'Regra fiscal atualizada.');
    }

    private function save(Request $request, ?int $id = null): void
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $data, $id) {
            // A single issuer per installation provides a stable mutex even when no rules exist yet.
            $company = CompanySetting::current();
            CompanySetting::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $rule = $id ? FiscalTaxRule::query()->lockForUpdate()->findOrFail($id) : new FiscalTaxRule;
            if ($rule->exists && $rule->document_type === 'nfe' && !$request->filled('revision')) {
                throw ValidationException::withMessages(['revision' => 'Reabra o cadastro desta regra antes de salvar as alterações.']);
            }
            if ($rule->exists && $request->filled('revision') && (int) $request->input('revision') !== $rule->revision) {
                throw ValidationException::withMessages(['revision' => 'Esta regra foi alterada por outro usuário. Reabra o cadastro antes de salvar.']);
            }
            if ($rule->exists) $this->recordRevision($rule, null);
            // Catalog provenance cannot be removed by omitting metadata from an update.
            if ($rule->exists && $rule->catalog_version && empty($data['catalog_version'])) {
                $data['catalog_version'] = $rule->catalog_version;
                $data['catalog_status'] = $data['catalog_status'] ?? $rule->catalog_status;
            }
            $rule->fill($data);
            $managed = (bool) $rule->catalog_version;
            if ($managed && $rule->is_active && (!$request->boolean('fiscal_review_confirmed')
                || !in_array($rule->catalog_status, ['reviewed','custom'], true))) {
                $this->invalid('fiscal_review_confirmed', 'Revise os critérios, os tributos e a fonte normativa antes de habilitar esta regra do catálogo.');
            }
            if ($managed && in_array($rule->cfop, ['6101','6102'], true) && !in_array($rule->recipient_profile, ['contributor','exempt'], true)) {
                $this->invalid('recipient_profile', 'O CFOP do catálogo exige regra específica para destinatário contribuinte.');
            }
            if ($managed && in_array($rule->cfop, ['6107','6108'], true) && $rule->recipient_profile !== 'non_contributor') {
                $this->invalid('recipient_profile', 'O CFOP do catálogo exige regra específica para destinatário não contribuinte.');
            }
            if ($managed) {
                $codes = \App\Support\FiscalCodeCatalog::all();
                foreach (['csosn'=>'csosn','icms_cst'=>'icms_cst','pis_cst'=>'pis_cofins_cst','cofins_cst'=>'pis_cofins_cst'] as $field => $kind) {
                    if ($rule->{$field} !== null && $rule->{$field} !== '' && !array_key_exists((string) $rule->{$field}, $codes[$kind])) $this->invalid($field, 'Código tributário não reconhecido no catálogo.');
                }
                $rule->fiscal_reviewed_at = $request->boolean('fiscal_review_confirmed') ? now() : null;
                $rule->fiscal_reviewed_by = $request->boolean('fiscal_review_confirmed') ? $request->user()?->id : null;
            }
            if ($managed && $rule->is_active && $rule->document_type === 'nfe') {
                $references = app(\App\Services\Fiscal\Nfe55TaxRuleApplicationService::class);
                $day = $rule->valid_from?->format('Y-m-d') ?? now()->toDateString();
                try { $references->assertCatalogCfop((string) $rule->cfop, (string) $rule->origin_uf, $day); }
                catch (\RuntimeException $error) { $this->invalid('cfop', $error->getMessage()); }
                if ($rule->fiscal_benefit_code) {
                    try { $references->assertCatalogBenefit((string) $rule->fiscal_benefit_code, (string) $rule->origin_uf,
                        (string) $rule->crt, $rule->icms_cst, $rule->csosn, $day, $rule->tax_regime); }
                    catch (\RuntimeException $error) { $this->invalid('fiscal_benefit_code', $error->getMessage()); }
                }
            }
            $conflicts = app(FiscalTaxRuleResolver::class)->conflicts($rule);
            if ($conflicts) throw ValidationException::withMessages([
                'priority' => 'Regra ativa conflitante na mesma vigência e precedência: '.implode(', ', $conflicts).'. Ajuste critérios, vigência ou prioridade.',
            ]);
            $rule->revision = $rule->exists ? (int) $rule->getOriginal('revision') + 1 : 1;
            $rule->save();
            $this->recordRevision($rule, $request->user()?->id);
        }, 3);
    }

    private function recordRevision(FiscalTaxRule $rule, ?int $userId): void
    {
        $trace = $rule->trace();
        $rule->revisions()->firstOrCreate(['revision' => $rule->revision], [
            'changed_by' => $userId, 'snapshot' => $trace['snapshot'],
            'snapshot_hash' => $trace['hash'], 'created_at' => $rule->updated_at ?? now(),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required','string','max:160'],
            'document_type' => ['required',Rule::in(['nfce','nfe'])],
            'origin_uf' => ['required',Rule::in(self::UFS)],
            'destination_uf' => ['required',Rule::in([...self::UFS,'EX'])],
            'crt' => ['required',Rule::in(['1','2','3','4'])],
            'product_id' => ['nullable','integer','exists:products,id'],
            'operation_nature_id' => ['nullable','integer','exists:operation_natures,id'],
            'fiscal_tax_group_id' => ['nullable','integer',Rule::exists('fiscal_tax_groups','id')->where('kind','products')],
            'origin_municipality' => ['nullable','regex:/^\d{7}$/D'],
            'destination_municipality' => ['nullable','regex:/^\d{7}$/D'],
            'recipient_icms_indicator' => ['nullable',Rule::in(['1','2','9'])],
            'catalog_version' => ['nullable','string','max:32','regex:/^[a-zA-Z0-9._-]+$/D'],
            'catalog_status' => ['nullable',Rule::in(['template','reviewed','custom'])],
            'fiscal_review_confirmed' => ['nullable','boolean'],
            'ncm_prefix' => ['nullable','regex:/^[0-9]{2,8}$/'],
            'cfop' => ['required','regex:/^[123567][0-9]{3}$/'],
            'input_cfop' => ['nullable','regex:/^[123567][0-9]{3}$/'],
            'csosn' => ['nullable','regex:/^[0-9]{3}$/'],
            'icms_cst' => ['nullable','regex:/^[0-9]{2}$/'],
            'pis_cst' => ['required','regex:/^[0-9]{2}$/'],
            'cofins_cst' => ['required','regex:/^[0-9]{2}$/'],
            'cest' => ['nullable','regex:/^[0-9]{7}$/'],
            'origin' => ['nullable','regex:/^[0-8]$/'],
            'operation_type' => ['nullable',Rule::in(['inbound','outbound'])],
            'fiscal_operation' => ['nullable',Rule::in(['sale','devolution','transfer','bonus','gift','sample','remittance','return_of_remittance','export','import'])],
            'purpose' => ['nullable',Rule::in(['normal','complementary','adjustment','return'])],
            'recipient_profile' => ['nullable',Rule::in(['contributor','exempt','non_contributor'])],
            'tax_regime' => ['nullable',Rule::in(['simples_nacional','lucro_presumido','lucro_real','mei','outro'])],
            'final_consumer' => ['nullable','boolean'],
            'fiscal_benefit_code' => ['nullable','regex:/^[A-Z0-9]{8,10}$/'],
            'priority' => ['required','integer','between:-100,100'],
            'valid_from' => ['nullable','date_format:Y-m-d'],
            'valid_until' => ['nullable','date_format:Y-m-d'],
            'notes' => ['nullable','string','max:2000'],
            'legal_source' => ['nullable','url:https','max:2000'],
            'legal_reference' => ['nullable','string','max:255'],
            'layout_version' => ['nullable','string','max:80'],
            'consulted_at' => ['nullable','date_format:Y-m-d','before_or_equal:today'],
            'application_conditions' => ['nullable','string','max:4000'],
            'tax_parameters_json' => ['nullable','string','max:10000'],
            'tax_parameters' => ['nullable','array','max:200'],
        ]);
        if ($data['document_type'] === 'nfce') {
            if ($data['origin_uf'] !== $data['destination_uf']) $this->invalid('destination_uf', 'A NFC-e deste fluxo admite somente operação interna: selecione a mesma UF de origem e destino.');
            if (!in_array($data['crt'], ['1','4'], true)) $this->invalid('crt', 'O cadastro NFC-e atual admite CRT 1 e 4.');
            if (!str_starts_with($data['cfop'], '5')) $this->invalid('cfop', 'A NFC-e exige CFOP de saída interna.');
            if (empty($data['csosn'])) $this->invalid('csosn', 'Informe o CSOSN da regra NFC-e.');
            if (!empty($data['icms_cst'])) $this->invalid('icms_cst', 'O cadastro NFC-e atual utiliza CSOSN. CST ICMS é parametrizado no fluxo NF-e 55.');
            foreach (['input_cfop','cest','origin','operation_type','fiscal_operation','purpose','recipient_profile','tax_regime','final_consumer','fiscal_benefit_code','operation_nature_id','fiscal_tax_group_id','origin_municipality','destination_municipality','recipient_icms_indicator','catalog_version','catalog_status'] as $field) {
                if (isset($data[$field]) && $data[$field] !== '') $this->invalid($field, 'Este critério adicional é exclusivo das regras NF-e modelo 55.');
            }
        } else {
            $nature = !empty($data['operation_nature_id']) ? OperationNature::query()->find($data['operation_nature_id']) : null;
            $group = !empty($data['fiscal_tax_group_id']) ? FiscalTaxGroup::query()->find($data['fiscal_tax_group_id']) : null;
            if (empty($data['catalog_version']) && ($nature?->catalog_version || $group?->catalog_version)) {
                $data['catalog_version'] = $nature?->catalog_version ?: $group?->catalog_version;
            }
            if (!empty($data['catalog_version']) && empty($data['catalog_status'])) $data['catalog_status'] = 'template';
            if (!empty($data['catalog_version']) && in_array($data['cfop'], ['6101','6102'], true)
                && !in_array($data['recipient_profile'] ?? null, ['contributor','exempt'], true)) $this->invalid('recipient_profile', 'Este CFOP do catálogo exige destinatário contribuinte; configure uma regra específica para não contribuinte.');
            if (!empty($data['catalog_version']) && in_array($data['cfop'], ['6107','6108'], true)
                && ($data['recipient_profile'] ?? null) !== 'non_contributor') $this->invalid('recipient_profile', 'Este CFOP do catálogo exige destinatário não contribuinte.');
            if (isset($data['recipient_icms_indicator']) && !empty($data['recipient_profile'])) {
                $expected = ['contributor'=>'1','exempt'=>'2','non_contributor'=>'9'][$data['recipient_profile']];
                if ($data['recipient_icms_indicator'] !== $expected) $this->invalid('recipient_icms_indicator', 'O indicador de ICMS conflita com o perfil do destinatário.');
            }
            if (in_array($data['crt'], ['1','4'], true)) {
                if (empty($data['csosn']) || !empty($data['icms_cst'])) $this->invalid('csosn', 'Informe somente CSOSN para este CRT.');
            } elseif (empty($data['icms_cst']) || !empty($data['csosn'])) {
                $this->invalid('icms_cst', 'Informe somente CST de ICMS para este CRT.');
            }
            foreach (['valid_from','legal_source','legal_reference','layout_version','consulted_at'] as $field) {
                if (empty($data[$field])) $this->invalid($field, 'Informe vigência e rastreabilidade normativa da regra NF-e.');
            }
            $sourceHost = strtolower((string) parse_url($data['legal_source'], PHP_URL_HOST));
            $sourcePath = strtolower((string) parse_url($data['legal_source'], PHP_URL_PATH));
            if (!(str_ends_with($sourceHost, '.gov.br') || str_ends_with($sourceHost, '.leg.br')
                || $sourceHost === 'acbr.sourceforge.io' || $sourceHost === 'projetoacbr.com.br'
                || str_ends_with($sourceHost, '.projetoacbr.com.br')) || str_contains($sourcePath, '/forum')) {
                $this->invalid('legal_source', 'Informe o endereço da fonte oficial desta regra fiscal.');
            }
            $prefix = substr($data['cfop'], 0, 1);
            if (($data['operation_type'] ?? null) === 'inbound' && !in_array($prefix, ['1','2','3'], true)) $this->invalid('cfop', 'Operação de entrada exige CFOP iniciado por 1, 2 ou 3.');
            if (($data['operation_type'] ?? null) === 'outbound' && !in_array($prefix, ['5','6','7'], true)) $this->invalid('cfop', 'Operação de saída exige CFOP iniciado por 5, 6 ou 7.');
            $geography = $data['destination_uf'] === 'EX' ? ['3','7'] : ($data['origin_uf'] === $data['destination_uf'] ? ['1','5'] : ['2','6']);
            if (!in_array($prefix, $geography, true)) $this->invalid('cfop', 'O CFOP não corresponde às UFs informadas.');
        }
        if (!empty($data['valid_from']) && !empty($data['valid_until']) && $data['valid_until'] < $data['valid_from']) $this->invalid('valid_until', 'O fim da vigência não pode ser anterior ao início.');
        $parameters = array_filter($data['tax_parameters'] ?? [], static fn ($value) => $value !== null && $value !== '');
        if (!empty($data['tax_parameters_json'])) {
            try { $parameters = json_decode($data['tax_parameters_json'], true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { $this->invalid('tax_parameters_json', 'Os parâmetros tributários estão inválidos. Revise a configuração.'); }
            if (!is_array($parameters) || array_is_list($parameters)) $this->invalid('tax_parameters_json', 'Revise os parâmetros tributários informados.');
        }
        foreach ($parameters as $key => $value) {
                if(in_array($key,['is_legal_source','rtc_regular_legal_source'],true)){
                    $host=is_string($value)?strtolower((string)parse_url($value,PHP_URL_HOST)):'';
                    if(!is_string($value)||!filter_var($value,FILTER_VALIDATE_URL)||parse_url($value,PHP_URL_SCHEME)!=='https'||!(str_ends_with($host,'.gov.br')||str_ends_with($host,'.leg.br'))) $this->invalid('tax_parameters_json','Informe fonte normativa governamental HTTPS.');
                    continue;
                }
                if(in_array($key,['is_legal_reference','rtc_regular_legal_reference'],true)){
                    if(!is_string($value)||trim($value)===''||mb_strlen($value)>255)$this->invalid('tax_parameters_json','Referência legal inválida.');
                    continue;
                }
                if(in_array($key,['is_valid_from','is_valid_until'],true)){
                    $date=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
                    if(!$date||$date->format('Y-m-d')!==$value)$this->invalid('tax_parameters_json','Vigência IS inválida.');
                    continue;
                }
                if($key==='rtc_adjustment_period'){
                    if(!is_string($value)||!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D',$value))$this->invalid('tax_parameters_json','Competência inválida.');
                    continue;
                }
                if($key==='is_unit'){
                    if(!is_string($value)||trim($value)===''||mb_strlen($value)>6)$this->invalid('tax_parameters_json','Unidade IS inválida.');
                    continue;
                }
                if($key==='is_calculation_type'){
                    if(!in_array($value,['percent','quantity','combined'],true))$this->invalid('tax_parameters_json','Modo de cálculo IS inválido.');
                    continue;
                }
                if(in_array($key,['is_cst','rtc_regular_cst'],true)&&(!is_string($value)||!preg_match('/^\d{3}$/D',$value)))$this->invalid('tax_parameters_json','CST deve conter três dígitos.');
                if(in_array($key,['is_class','rtc_regular_class'],true)&&(!is_string($value)||!preg_match('/^\d{6}$/D',$value)))$this->invalid('tax_parameters_json','Classificação deve conter seis dígitos.');

                if ($key === 'st_retained_amount_scope') {
                    if (!in_array($value, ['line','unit'], true)) $this->invalid('tax_parameters_json', 'Informe o escopo por item ou por unidade da retenção anterior.');
                    continue;
                }
                if (in_array($key, ['ipi_calculation_type','pis_calculation_type','cofins_calculation_type','pis_st_calculation_type','cofins_st_calculation_type'], true)) {
                    if (!in_array($value, ['percent','quantity'], true)) $this->invalid('tax_parameters_json', 'Forma de cálculo inválida para '.(\App\Support\FiscalPresentation::label($key) ?? 'o tributo informado').'.');
                    continue;
                }
                if (str_ends_with($key,'_st_add_to_total') && !in_array((string)$value,['0','1'],true)) $this->invalid('tax_parameters_json','Indicador de soma ST deve ser 0 ou 1.');
                if ($key === 'ipi_returned_percentage' && ((float)$value>100 || !preg_match('/^\d+(?:\.\d{1,2})?$/D',(string)$value))) $this->invalid('tax_parameters_json','Percentual de devolução: máximo 100 e duas casas decimais.');
                if ($key === 'ipi_cst' && (!is_string($value) || !preg_match('/^\d{2}$/', $value))) $this->invalid('tax_parameters_json', 'CST IPI deve conter dois dígitos.');
                if ($key === 'ipi_enq' && (!is_string($value) || !preg_match('/^\d{3}$/', $value))) $this->invalid('tax_parameters_json', 'Enquadramento IPI deve conter três dígitos.');
                if ($key === 'ibs_cbs_cst' && (!is_string($value) || !preg_match('/^\d{3}$/', $value))) $this->invalid('tax_parameters_json', 'CST IBS/CBS deve conter três dígitos.');
                if ($key === 'ibs_cbs_class' && (!is_string($value) || !preg_match('/^\d{6}$/', $value))) $this->invalid('tax_parameters_json', 'Classificação tributária IBS/CBS deve conter seis dígitos.');
                if ($key === 'icms_base_mode' && !in_array((string)$value,['0','1','2','3'],true)) $this->invalid('tax_parameters_json', 'Modalidade da base ICMS inválida.');
                if ($key === 'icms_st_base_mode' && !in_array((string)$value,['0','1','2','3','4','5','6'],true)) $this->invalid('tax_parameters_json', 'Modalidade da base ICMS ST inválida.');
                if ($key === 'icms_desoneration_deduct' && !in_array((string) $value, ['0','1'], true)) $this->invalid('tax_parameters_json', 'A dedução do ICMS desonerado deve ser Sim ou Não.');
                if (!in_array($key, self::TAX_PARAMETERS, true) || !is_scalar($value) || is_bool($value)
                    || !preg_match('/^\d{1,12}(?:\.\d{1,4})?$/', (string) $value)) $this->invalid('tax_parameters_json', 'Valor inválido para '.(\App\Support\FiscalPresentation::label($key) ?? 'o parâmetro tributário informado').'. Informe um valor não negativo.');
                if ($key!=='is_rate' && (str_ends_with($key, '_rate') && !str_ends_with($key, '_unit_rate') || str_ends_with($key, '_reduction')) && (float) $value > 100) $this->invalid('tax_parameters_json', 'O percentual de '.(\App\Support\FiscalPresentation::label($key) ?? 'tributação').' não pode ultrapassar 100.');
        }
        if ($data['document_type'] === 'nfce' && $parameters !== []) {
            $this->invalid('tax_parameters_json', 'Configure os tributos da NFC-e no grupo tributário correspondente.');
        }
        unset($data['tax_parameters_json'], $data['fiscal_review_confirmed']);
        $data['tax_parameters'] = $parameters ?: null;
        $data['csosn'] = $data['csosn'] ?? '';
        $data['ncm_prefix'] = trim((string) ($data['ncm_prefix'] ?? '')) ?: null;
        $data['is_active'] = $request->boolean('is_active');
        return $data;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
