@php
  $value = fn($field, $default = '') => $rule ? ($rule->{$field} ?? $default) : old($field, $default);
  $parameters = $rule?->tax_parameters ?? old('tax_parameters', []);
@endphp
<div class="editor-grid cols-12 settings-grid">
  <label class="field col-2"><span>Documento</span><select name="document_type" required>
    @foreach(['nfce'=>'NFC-e 65','nfe'=>'NF-e 55'] as $code=>$label)<option value="{{ $code }}" @selected($value('document_type','nfce')===$code)>{{ $label }}</option>@endforeach
  </select></label>
  <label class="field col-4"><span>Nome</span><input name="name" maxlength="160" required value="{{ $value('name') }}"></label>
  <label class="field col-2"><span>UF origem</span><select name="origin_uf" required>
    <option value="">Selecione</option>@foreach($ufs as $uf)<option value="{{ $uf }}" @selected($value('origin_uf',$issuerUf)===$uf)>{{ $uf }}</option>@endforeach
  </select></label>
  <label class="field col-2"><span>UF destino</span><select name="destination_uf" required>
    <option value="">Selecione</option>@foreach([...$ufs,'EX'] as $uf)<option value="{{ $uf }}" @selected($value('destination_uf',$issuerUf)===$uf)>{{ $uf }}</option>@endforeach
  </select></label>
  <label class="field col-2"><span>CRT</span><select name="crt" required>
    @foreach(['1'=>'Simples Nacional','2'=>'Simples excesso sublimite','3'=>'Regime normal','4'=>'MEI'] as $code=>$label)<option value="{{ $code }}" @selected((string)$value('crt',$issuerCrt ?: '1')===(string)$code)>{{ $code }} — {{ $label }}</option>@endforeach
  </select></label>
  <label class="field col-4"><span>Produto</span><select name="product_id"><option value="">Todos os produtos</option>
    @foreach($products as $product)<option value="{{ $product->id }}" @selected((string)$value('product_id')===(string)$product->id)>{{ $product->sku }} — {{ $product->name }}</option>@endforeach
  </select></label>
  <label class="field col-2"><span>Prefixo NCM</span><input name="ncm_prefix" maxlength="8" value="{{ $value('ncm_prefix') }}"></label>
  <label class="field col-2"><span>CFOP resultante</span><input name="cfop" required maxlength="4" value="{{ $value('cfop') }}"></label>
  <label class="field col-2"><span>CSOSN</span><input name="csosn" maxlength="3" value="{{ $value('csosn') }}"></label>
  <label class="field col-2"><span>CST ICMS</span><input name="icms_cst" maxlength="2" value="{{ $value('icms_cst') }}"></label>
  <label class="field col-2"><span>CST PIS</span><input name="pis_cst" required maxlength="2" value="{{ $value('pis_cst') }}"></label>
  <label class="field col-2"><span>CST COFINS</span><input name="cofins_cst" required maxlength="2" value="{{ $value('cofins_cst') }}"></label>
  <label class="field col-2"><span>Prioridade</span><input name="priority" required type="number" min="-100" max="100" value="{{ $value('priority',0) }}"></label>
  <label class="field col-2"><span>Início da vigência</span><input name="valid_from" type="date" value="{{ $rule?->valid_from?->format('Y-m-d') ?? old('valid_from') }}"></label>
  <label class="field col-2"><span>Fim da vigência</span><input name="valid_until" type="date" value="{{ $rule?->valid_until?->format('Y-m-d') ?? old('valid_until') }}"></label>
  <div class="field col-2"><span>Situação</span><label class="settings-switch"><input type="checkbox" name="is_active" value="1" @checked($value('is_active',false))><span><strong>Ativa</strong></span></label></div>
</div>
<details style="margin-top:12px"><summary>Critérios adicionais da NF-e 55</summary>
  <div class="editor-grid cols-12 settings-grid" style="margin-top:10px">
    <label class="field col-4"><span>Natureza de operação</span><select name="operation_nature_id"><option value="">Qualquer natureza</option>@foreach($operationNatures as $nature)<option value="{{ $nature->id }}" @selected((string)$value('operation_nature_id')===(string)$nature->id)>{{ $nature->name }}</option>@endforeach</select></label>
    <label class="field col-4"><span>Grupo tributário do produto</span><select name="fiscal_tax_group_id"><option value="">Qualquer grupo</option>@foreach($fiscalTaxGroups as $group)<option value="{{ $group->id }}" @selected((string)$value('fiscal_tax_group_id')===(string)$group->id)>{{ $group->name }}</option>@endforeach</select></label>
    <label class="field col-2"><span>Município origem (IBGE)</span><input name="origin_municipality" maxlength="7" value="{{ $value('origin_municipality') }}"></label>
    <label class="field col-2"><span>Município destino (IBGE)</span><input name="destination_municipality" maxlength="7" value="{{ $value('destination_municipality') }}"></label>
    <label class="field col-3"><span>Indicador ICMS destinatário</span><select name="recipient_icms_indicator"><option value="">Qualquer</option>@foreach(['1'=>'Contribuinte','2'=>'Contribuinte isento de IE','9'=>'Não contribuinte'] as $code=>$label)<option value="{{ $code }}" @selected((string)$value('recipient_icms_indicator')===(string)$code)>{{ $code }} — {{ $label }}</option>@endforeach</select></label>
    <label class="field col-2"><span>CFOP do item</span><input name="input_cfop" maxlength="4" value="{{ $value('input_cfop') }}"></label>
    <label class="field col-2"><span>CEST</span><input name="cest" maxlength="7" value="{{ $value('cest') }}"></label>
    <label class="field col-2"><span>Origem mercadoria</span><select name="origin"><option value="">Qualquer</option>@foreach(range(0,8) as $origin)<option value="{{ $origin }}" @selected((string)$value('origin')===(string)$origin)>{{ $origin }}</option>@endforeach</select></label>
    <label class="field col-2"><span>Operação</span><select name="operation_type"><option value="">Qualquer</option>@foreach(['inbound'=>'Entrada','outbound'=>'Saída'] as $code=>$label)<option value="{{ $code }}" @selected($value('operation_type')===$code)>{{ $label }}</option>@endforeach</select></label>
    <label class="field col-3"><span>Tipo fiscal da operação</span><select name="fiscal_operation"><option value="">Qualquer</option>@foreach(['sale'=>'Venda / compra','devolution'=>'Devolução','transfer'=>'Transferência','bonus'=>'Bonificação','gift'=>'Brinde','sample'=>'Amostra grátis','remittance'=>'Remessa para conserto','return_of_remittance'=>'Retorno de conserto','export'=>'Exportação direta','import'=>'Importação direta'] as $code=>$label)<option value="{{ $code }}" @selected($value('fiscal_operation')===$code)>{{ $label }}</option>@endforeach</select></label>
    <label class="field col-2"><span>Finalidade</span><select name="purpose"><option value="">Qualquer</option>@foreach(['normal'=>'Normal','complementary'=>'Complemento','adjustment'=>'Ajuste','return'=>'Devolução'] as $code=>$label)<option value="{{ $code }}" @selected($value('purpose')===$code)>{{ $label }}</option>@endforeach</select></label>
    <label class="field col-2"><span>Destinatário</span><select name="recipient_profile"><option value="">Qualquer</option>@foreach(['contributor'=>'Contribuinte','exempt'=>'Contribuinte isento de IE','non_contributor'=>'Não contribuinte'] as $code=>$label)<option value="{{ $code }}" @selected($value('recipient_profile')===$code)>{{ $label }}</option>@endforeach</select></label>
    <label class="field col-2"><span>Consumidor final</span><select name="final_consumer"><option value="">Qualquer</option><option value="1" @selected($value('final_consumer',null)===true)>Sim</option><option value="0" @selected($value('final_consumer',null)===false)>Não</option></select></label>
    <label class="field col-4"><span>Regime tributário cadastral</span><select name="tax_regime"><option value="">Qualquer</option>@foreach(['simples_nacional'=>'Simples Nacional','lucro_presumido'=>'Lucro Presumido','lucro_real'=>'Lucro Real','mei'=>'MEI','outro'=>'Outro'] as $code=>$label)<option value="{{ $code }}" @selected($value('tax_regime')===$code)>{{ $label }}</option>@endforeach</select></label>
    <label class="field col-2"><span>Benefício fiscal (cBenef)</span><input name="fiscal_benefit_code" maxlength="10" value="{{ $value('fiscal_benefit_code') }}"></label>
  </div>
</details>
<details style="margin-top:12px"><summary>Parâmetros tributários aprovados</summary>
  <div class="editor-grid cols-12 settings-grid" style="margin-top:10px">
    @foreach(['icms_rate'=>'ICMS %','icms_base'=>'BC ICMS (R$)','icms_base_mode'=>'Modalidade BC ICMS','icms_base_reduction'=>'Redução BC ICMS %','icms_deferment_rate'=>'Diferimento ICMS %','fcp_rate'=>'FCP %','fcp_base'=>'BC FCP (R$)','icms_credit_rate'=>'Crédito SN %','icms_st_base'=>'BC ICMS ST (R$)','icms_st_rate'=>'ICMS ST %','icms_st_base_mode'=>'Modalidade BC ST','icms_st_deduction'=>'Dedução ICMS ST (R$)','icms_st_mva'=>'MVA ST %','icms_st_base_reduction'=>'Redução BC ST %','fcp_st_rate'=>'FCP ST %','ipi_cst'=>'CST IPI','ipi_enq'=>'Enquadramento IPI','ipi_rate'=>'IPI %','ipi_base'=>'BC IPI (R$)','ipi_quantity'=>'Quantidade IPI','ipi_unit_rate'=>'IPI por unidade (R$)','pis_rate'=>'PIS %','pis_base'=>'BC PIS (R$)','pis_quantity'=>'Quantidade PIS','pis_unit_rate'=>'PIS por unidade (R$)','cofins_rate'=>'COFINS %','cofins_base'=>'BC COFINS (R$)','cofins_quantity'=>'Quantidade COFINS','cofins_unit_rate'=>'COFINS por unidade (R$)'] as $field=>$label)
    <label class="field col-3"><span>{{ $label }}</span><input name="tax_parameters[{{ $field }}]" inputmode="decimal" value="{{ $parameters[$field] ?? '' }}"></label>
    @endforeach
    @foreach(['ipi'=>'IPI','pis'=>'PIS','cofins'=>'COFINS'] as $tax=>$label)<label class="field col-3"><span>Cálculo {{ $label }}</span><select name="tax_parameters[{{ $tax }}_calculation_type]"><option value="">Conforme CST</option><option value="percent" @selected(($parameters[$tax.'_calculation_type']??'')==='percent')>Percentual</option><option value="quantity" @selected(($parameters[$tax.'_calculation_type']??'')==='quantity')>Por quantidade</option></select></label>@endforeach
  </div>
  <p>Campos vazios permanecem sem parametrização. Taxas e enquadramentos dependem de aprovação fiscal; cadastrar uma regra não comprova suporte ou homologação do cenário.</p>
</details>
@include('fiscal.nfe._additional-federal-taxes',['ruleMode'=>true,'federalParameters'=>$parameters])
@include('fiscal.nfe._rtc-extra-fields',['ruleMode'=>true,'extraParameters'=>$parameters])
<details style="margin-top:12px"><summary>Retenção anterior, desoneração e DIFAL</summary>
  <div class="editor-grid cols-12 settings-grid" style="margin-top:10px">
    @foreach(['icms_st_retained_base'=>'BC ST retida (R$)','st_retained_rate'=>'ST retida %','icms_substitutor_value'=>'ICMS substituto (R$)','icms_st_retained_value'=>'ICMS ST retido (R$)','fcp_st_retained_base'=>'BC FCP retido (R$)','fcp_st_retained_rate'=>'FCP retido %','fcp_st_retained_value'=>'FCP retido (R$)','icms_effective_base'=>'BC ICMS efetivo (R$)','icms_effective_rate'=>'ICMS efetivo %','icms_effective_value'=>'ICMS efetivo (R$)','icms_effective_reduction'=>'Redução ICMS efetivo %','icms_desoneration_amount'=>'ICMS desonerado (R$)','icms_desoneration_reason'=>'Motivo desoneração','difal_base'=>'BC DIFAL (R$)','difal_fcp_base'=>'BC FCP destino (R$)','difal_internal_rate'=>'Alíquota interna destino %','difal_interstate_rate'=>'Alíquota interestadual %','difal_share_rate'=>'Partilha destino %','difal_fcp_rate'=>'FCP destino %'] as $field=>$label)
    <label class="field col-3"><span>{{ $label }}</span><input name="tax_parameters[{{ $field }}]" inputmode="decimal" value="{{ $parameters[$field] ?? '' }}"></label>
    @endforeach
    <label class="field col-3"><span>Escopo retenção anterior</span><select name="tax_parameters[st_retained_amount_scope]"><option value="">Não informado</option><option value="line" @selected(($parameters['st_retained_amount_scope']??'')==='line')>Valores do item</option><option value="unit" @selected(($parameters['st_retained_amount_scope']??'')==='unit')>Valores por unidade</option></select></label>
    <label class="field col-3"><span>Deduzir desoneração do total</span><select name="tax_parameters[icms_desoneration_deduct]"><option value="">Não informado</option><option value="0" @selected((string)($parameters['icms_desoneration_deduct']??'')==='0')>Não</option><option value="1" @selected((string)($parameters['icms_desoneration_deduct']??'')==='1')>Sim</option></select></label>
  </div>
</details>
<details style="margin-top:12px"><summary>IBS estadual, IBS municipal e CBS</summary>
  <div class="editor-grid cols-12 settings-grid" style="margin-top:10px">
    @foreach(['ibs_cbs_cst'=>'CST IBS/CBS','ibs_cbs_class'=>'Classificação tributária','ibs_cbs_base'=>'Base final IBS/CBS (R$)','ibs_uf_rate'=>'IBS estadual %','ibs_municipal_rate'=>'IBS municipal %','cbs_rate'=>'CBS %','ibs_uf_reduction_rate'=>'Redução IBS estadual %','ibs_municipal_reduction_rate'=>'Redução IBS municipal %','cbs_reduction_rate'=>'Redução CBS %'] as $field=>$label)
    <label class="field col-3"><span>{{ $label }}</span><input name="tax_parameters[{{ $field }}]" inputmode="decimal" value="{{ $parameters[$field] ?? '' }}"></label>
    @endforeach
  </div>
</details>
<details style="margin-top:12px" @if(!$rule) open @endif><summary>Fundamento e condições de aplicação</summary>
  <div class="editor-grid cols-12 settings-grid" style="margin-top:10px">
    <label class="field col-3"><span>Versão do catálogo</span><input name="catalog_version" maxlength="32" value="{{ $value('catalog_version') }}"></label>
    <label class="field col-3"><span>Revisão do modelo</span><select name="catalog_status"><option value="">Configuração existente</option>@foreach(['template'=>'Modelo para revisão','reviewed'=>'Revisada','custom'=>'Exceção personalizada revisada'] as $code=>$label)<option value="{{ $code }}" @selected($value('catalog_status')===$code)>{{ $label }}</option>@endforeach</select></label>
    <div class="field col-6"><span>Aplicação automática do catálogo</span><label class="settings-switch"><input type="checkbox" name="fiscal_review_confirmed" value="1"><span><strong>Revisei os critérios e o fundamento desta regra</strong></span></label>@if($rule?->fiscal_reviewed_at)<small>Última revisão {{ $rule->fiscal_reviewed_at->format('d/m/Y H:i') }}</small>@endif</div>
    <label class="field col-6"><span>Fonte oficial (URL HTTPS)</span><input name="legal_source" type="url" maxlength="2000" value="{{ $value('legal_source') }}"></label>
    <label class="field col-6"><span>Norma ou tabela</span><input name="legal_reference" maxlength="255" value="{{ $value('legal_reference') }}"></label>
    <label class="field col-4"><span>Versão do leiaute / norma</span><input name="layout_version" maxlength="80" value="{{ $value('layout_version') }}"></label>
    <label class="field col-2"><span>Data da consulta</span><input name="consulted_at" type="date" value="{{ $rule?->consulted_at?->format('Y-m-d') ?? old('consulted_at') }}"></label>
    <label class="field col-6"><span>Condições e limitações</span><textarea name="application_conditions" rows="2" maxlength="4000">{{ $value('application_conditions') }}</textarea></label>
    <label class="field col-12"><span>Observações internas</span><textarea name="notes" rows="2" maxlength="2000">{{ $value('notes') }}</textarea></label>
  </div>
</details>
