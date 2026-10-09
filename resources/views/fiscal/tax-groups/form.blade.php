@extends('layouts.app')
@section('title',$editing ? 'Editar grupo tributário' : 'Novo grupo tributário')
@section('titleMeta','Cadastro tributário')
@section('actions')
  <a class="btn btn-secondary" href="{{ route('fiscal.tax-groups.index') }}">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Grupos de tributação</a>
@endsection

@section('content')
@php
  $values=old('tax_config',$group->tax_config ?? []);
  $val=fn(string $key)=>data_get($values,$key,'');
  $selectedKind=old('kind',$group->kind ?? 'products');
  $groupCrt=(string)($group->target_crt ?? '');
  $isSimplePreset=in_array($groupCrt,['1','4'],true);
  $isNormalPreset=in_array($groupCrt,['2','3'],true);
  $sections=[
    'ICMS'=>[
      ['icms_rate','Alíquota ICMS (%)'],['base_reduction_rate','Redução BC ICMS (%)'],['credit_rate','Crédito Simples (%)'],
      ['icms_st_rate','ICMS-ST (%)'],['mva_rate','MVA (%)'],
    ],
    'PIS / COFINS'=>[
      ['pis_rate','Alíquota PIS (%)'],['cofins_rate','Alíquota COFINS (%)'],
    ],
    'IBS / CBS'=>[
      ['cbs_rate','Alíquota CBS (%)'],['ibs_uf_rate','Alíquota IBS UF (%)'],
      ['ibs_municipal_rate','Alíquota IBS municipal (%)'],
      ['cbs_reduction_rate','Redução CBS (%)'],['ibs_reduction_rate','Redução IBS (%)'],
    ],
  ];
@endphp
<form method="post" action="{{ $editing ? route('fiscal.tax-groups.update',$group) : route('fiscal.tax-groups.store') }}" class="settings-editor">
  @csrf
  @if($editing) @method('PUT') @endif
  <section class="editor-panel settings-panel">
    <div class="settings-panel-head">
      <div><h2>Dados gerais</h2></div>
      @if($editing)<span class="status status-blue">Revisão {{ $group->revision }}</span>@endif
    </div>
    <div class="editor-grid cols-12 settings-grid">
      <label class="field col-6"><span>Descrição</span><input required maxlength="160" name="name" value="{{ old('name',$group->name) }}" placeholder="Ex.: Venda de mercadorias — revisão contábil"></label>
      <label class="field col-3"><span>Tipo de grupo</span><select name="kind">
          <option value="products" @selected($selectedKind==='products')>Tributação para produtos</option>
          <option value="services" @selected($selectedKind==='services')>Tributação para serviços</option>
        </select></label>
      <div class="field col-3">
        <span>Disponibilidade</span>
        <label class="settings-switch"><input type="checkbox" name="is_active" value="1" @checked(old('is_active',$group->is_active ?? false))>
          <span><strong>Grupo ativo</strong></span></label>
      </div>
      <div class="field col-4">
        <span>Grupo padrão</span>
        <label class="settings-switch"><input type="checkbox" name="is_default" value="1" @checked(old('is_default',$group->is_default ?? false))>
          <span><strong>Grupo padrão</strong></span>
        </label>
      </div>
      <label class="field col-4" data-group-kind="products" @if($selectedKind==='services') hidden @endif><span>CFOP base</span><input name="cfop_pattern" maxlength="4" value="{{ old('cfop_pattern',$group->cfop_pattern) }}" placeholder="Ex.: x102">
        
      </label>
      <label class="field col-4" data-group-kind="products" @if($selectedKind==='services') hidden @endif><span>Observação de aplicação na NFC-e</span><input name="tax_config[nfce_note]" maxlength="255" value="{{ $val('nfce_note') }}" placeholder="Ex.: conferir substituição tributária"></label>
    </div>
  </section>


  <section class="editor-panel settings-panel" data-group-kind="services" @if($selectedKind!=='services') hidden @endif>
    <div class="settings-panel-head"><div><h2>Tributação do serviço</h2></div></div>
    <div class="editor-grid cols-12 settings-grid">
      <label class="field col-4"><span>Código nacional do serviço</span>
        <input name="tax_config[national_tax_code]" maxlength="6" inputmode="numeric" value="{{ $val('national_tax_code') }}" placeholder="Ex.: 171901">
      </label>
      <label class="field col-4"><span>Item da lista de serviços</span>
        <input name="tax_config[service_list_item]" maxlength="5" value="{{ $val('service_list_item') }}" placeholder="Ex.: 17.19">
      </label>
      <label class="field col-4"><span>Exigibilidade do ISS</span>
        <select name="iss_exigibility">
          <option value="">Selecione</option>
          @foreach(\App\Support\FiscalCodeCatalog::all()['iss_exigibility'] as $code=>$description)
            <option value="{{ $code }}" @selected((string)old('iss_exigibility',$group->iss_exigibility)===(string)$code)>{{ $code }} — {{ $description }}</option>
          @endforeach
        </select>
      </label>
      <label class="field col-4"><span>Apuração do ISS</span>
        <select name="tax_config[iss_calculation]">
          <option value="municipal_or_simples" @selected($val('iss_calculation')==='municipal_or_simples')>Conforme o regime da empresa</option>
          <option value="municipal" @selected($val('iss_calculation')==='municipal')>Alíquota municipal</option>
          <option value="simples_effective" @selected($val('iss_calculation')==='simples_effective')>Alíquota efetiva do Simples</option>
          <option value="mei_fixed" @selected($val('iss_calculation')==='mei_fixed')>MEI — recolhimento fixo</option>
        </select>
      </label>
      <label class="field col-2"><span>ISS (%)</span>
        <input name="tax_config[iss_rate]" type="number" step="0.0001" min="0" max="100" value="{{ $val('iss_rate') }}">
      </label>
      <label class="field col-3"><span>Município (IBGE)</span>
        <input name="tax_config[iss_city_ibge]" maxlength="7" inputmode="numeric" value="{{ $val('iss_city_ibge') }}">
      </label>
      <label class="field col-3"><span>Legislação municipal</span>
        <input name="tax_config[iss_legal_reference]" maxlength="190" value="{{ $val('iss_legal_reference') }}">
      </label>
    </div>
  </section>

  <section class="editor-panel settings-panel" data-group-kind="products" @if($selectedKind==='services') hidden @endif>
    <div class="settings-panel-head"><div><h2>ICMS</h2></div></div>
    <div class="editor-grid cols-12 settings-grid">
      <label class="field col-4" @if($isNormalPreset) hidden @endif><span>CSOSN geral</span><input name="icms_csosn" maxlength="3" inputmode="numeric" value="{{ old('icms_csosn',$group->icms_csosn) }}" placeholder="Ex.: 101"></label>
      <label class="field col-4" @if($isNormalPreset) hidden @endif><span>Alternativa para NFC-e</span><input name="nfce_csosn" maxlength="3" inputmode="numeric" value="{{ old('nfce_csosn',$group->nfce_csosn) }}" placeholder="Ex.: 102"></label>
      <label class="field col-4" @if($isSimplePreset) hidden @endif><span>CST ICMS (regime normal)</span><input name="icms_cst" maxlength="2" inputmode="numeric" value="{{ old('icms_cst',$group->icms_cst) }}"></label>
      <label class="field col-4" @if($isSimplePreset) hidden @endif><span>Modalidade da base ICMS</span>
        <select name="tax_config[mod_bc]">
          <option value="">Selecione</option>
          <option value="0" @selected($val('mod_bc')==='0')>0 — Margem de valor agregado</option>
          <option value="1" @selected($val('mod_bc')==='1')>1 — Pauta</option>
          <option value="2" @selected($val('mod_bc')==='2')>2 — Preço máximo sugerido</option>
          <option value="3" @selected($val('mod_bc')==='3')>3 — Valor da operação</option>
        </select>
      </label>
    </div>
    <div class="editor-grid cols-12 settings-grid">
      @foreach($sections['ICMS'] as [$key,$label])
        <label class="field col-3" @if($isSimplePreset || ($isNormalPreset && !in_array($key,['icms_rate','base_reduction_rate']))) hidden @endif><span>{{ $label }}</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[{{ $key }}]" value="{{ $val($key) }}" placeholder="—"></label>
      @endforeach
    </div>
  </section>

  <section class="editor-panel settings-panel" data-group-kind="products" @if($selectedKind==='services') hidden @endif>
    <div class="settings-panel-head"><div><h2>PIS / COFINS</h2></div></div>
    <div class="editor-grid cols-12 settings-grid">
      <label class="field col-3"><span>CST PIS</span><input name="pis_cst" maxlength="2" inputmode="numeric" value="{{ old('pis_cst',$group->pis_cst) }}" placeholder="Ex.: 49"></label>
      <label class="field col-3"><span>CST COFINS</span><input name="cofins_cst" maxlength="2" inputmode="numeric" value="{{ old('cofins_cst',$group->cofins_cst) }}" placeholder="Ex.: 49"></label>
      @foreach($sections['PIS / COFINS'] as [$key,$label])
        <label class="field col-3" @if($groupCrt!=='' && ($key==='pis_rate' ? !in_array($group->pis_cst,['01','02'],true) : !in_array($group->cofins_cst,['01','02'],true))) hidden @endif><span>{{ $label }}</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[{{ $key }}]" value="{{ $val($key) }}"></label>
      @endforeach
    </div>
  </section>

  <details data-group-kind="products" @if($selectedKind==='services') hidden @endif style="margin-bottom:14px">
    <summary class="btn btn-secondary" style="cursor:pointer;display:inline-flex">Outros tributos</summary>
  <section class="editor-panel settings-panel" data-group-kind="products" @if($selectedKind==='services') hidden @endif>
    <div class="settings-panel-head"><div><h2>IBS / CBS</h2></div></div>
    <div class="editor-grid cols-12 settings-grid">
      <label class="field col-3"><span>CST IBS/CBS</span><input maxlength="3" inputmode="numeric" name="tax_config[ibs_cbs_cst]" value="{{ $val('ibs_cbs_cst') }}"></label>
      <label class="field col-3"><span>Classificação tributária</span><input maxlength="6" inputmode="numeric" name="tax_config[ibs_cbs_class]" value="{{ $val('ibs_cbs_class') }}"></label>
      @foreach($sections['IBS / CBS'] as [$key,$label])
        <label class="field col-3"><span>{{ $label }}</span><input type="number" step="0.0001" min="0" max="100" name="tax_config[{{ $key }}]" value="{{ $val($key) }}"></label>
      @endforeach
    </div>
  </section>

  <section class="editor-panel settings-panel" data-group-kind="products" @if($selectedKind==='services') hidden @endif>
    <div class="settings-panel-head"><div><h2>Imposto Seletivo, IPI e ISS</h2></div></div>
    <div class="editor-grid cols-12 settings-grid">
      <label class="field col-3"><span>CST IS</span><input name="tax_config[is_cst]" maxlength="3" value="{{ $val('is_cst') }}"></label>
      <label class="field col-3"><span>Classificação IS</span><input name="tax_config[is_class]" maxlength="6" value="{{ $val('is_class') }}"></label>
      <label class="field col-3"><span>CST IPI</span><input name="ipi_cst" maxlength="2" inputmode="numeric" value="{{ old('ipi_cst',$group->ipi_cst) }}"></label>
      @foreach([['is_rate','Alíquota IS (%)'],['ipi_rate','Alíquota IPI (%)'],['iss_rate','Alíquota ISS (%)']] as [$key,$label])
        <label class="field col-2"><span>{{ $label }}</span><input type="number" min="0" max="100" step="0.0001" name="tax_config[{{ $key }}]" value="{{ $val($key) }}"></label>
      @endforeach
      <label class="field col-3"><span>Exigibilidade ISS</span><input name="iss_exigibility" maxlength="2" value="{{ old('iss_exigibility',$group->iss_exigibility) }}" placeholder="Código conforme município"></label>
    </div>
  </section>

  <div data-group-kind="products" @if($selectedKind==='services') hidden @endif>
    @include('fiscal.tax-groups._advanced')
  </div>

  </details>

  <section class="editor-panel settings-panel">
    <div class="settings-panel-head"><div><h2>Observações</h2></div></div>
    <label class="field"><span>Informações adicionais</span>
      <textarea name="notes" rows="3" maxlength="3000" placeholder="Referência legal, orientação do contador, data da conferência...">{{ old('notes',$group->notes) }}</textarea>
    </label>
  </section>
  <script>
    (function () {
      const kind = document.querySelector('select[name="kind"]');
      if (!kind) return;
      const toggle = () => {
        document.querySelectorAll('[data-group-kind]').forEach(element => {
          element.hidden = element.dataset.groupKind !== kind.value;
          element.querySelectorAll('input,select,textarea').forEach(control => {
            control.disabled = element.hidden;
          });
        });
      };
      kind.addEventListener('change', toggle);
      toggle();
    })();
  </script>
  <div class="editor-savebar settings-savebar">
    <button type="submit" class="btn btn-success">@include('partials.icon',['name'=>'check','size'=>16]) {{ $editing ? 'Salvar alterações' : 'Cadastrar grupo' }}</button>
    <a href="{{ route('fiscal.tax-groups.index') }}" class="btn btn-secondary">Cancelar</a>
  </div>
</form>
@endsection
