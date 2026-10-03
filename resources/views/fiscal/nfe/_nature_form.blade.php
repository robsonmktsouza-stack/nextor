@php
  $nature=$nature ?? new \App\Models\OperationNature([
    'operation_type'=>'outbound',
    'purpose'=>'normal',
    'presence_default'=>'not_applicable',
    'override_product_cfop'=>true,
    'move_stock'=>true,
    'generate_finance'=>true,
    'allow_referenced_document'=>true,
    'is_active'=>true,
  ]);
  $prefix=$prefix ?? 'nature';
@endphp

<div class="editor-grid cols-12">
  <label class="field col-8"><span>Descrição *</span>
    <input name="name" value="{{ old('name',$nature->name) }}" required maxlength="160" placeholder="Ex.: Venda de mercadoria">
  </label>
  <label class="field col-2"><span>Tipo *</span>
    <select name="operation_type">
      <option value="outbound" @selected(old('operation_type',$nature->operation_type)==='outbound')>Saída</option>
      <option value="inbound" @selected(old('operation_type',$nature->operation_type)==='inbound')>Entrada</option>
    </select>
  </label>
  <label class="field col-2"><span>Finalidade *</span>
    <select name="purpose">
      <option value="normal" @selected(old('purpose',$nature->purpose)==='normal')>Normal</option>
      <option value="complementary" @selected(old('purpose',$nature->purpose)==='complementary')>Complementar</option>
      <option value="adjustment" @selected(old('purpose',$nature->purpose)==='adjustment')>Ajuste</option>
      <option value="return" @selected(old('purpose',$nature->purpose)==='return')>Devolução</option>
    </select>
  </label>

  <label class="field col-4"><span>CFOP interno</span><input name="cfop_internal" value="{{ old('cfop_internal',$nature->cfop_internal) }}" maxlength="10" placeholder="Ex.: 5102"></label>
  <label class="field col-4"><span>CFOP interestadual</span><input name="cfop_interstate" value="{{ old('cfop_interstate',$nature->cfop_interstate) }}" maxlength="10" placeholder="Ex.: 6102"></label>
  <label class="field col-4"><span>CFOP exterior</span><input name="cfop_foreign" value="{{ old('cfop_foreign',$nature->cfop_foreign) }}" maxlength="10" placeholder="Ex.: 7102"></label>

  <label class="field col-4"><span>Presença padrão</span>
    <select name="presence_default">
      <option value="not_applicable" @selected(old('presence_default',$nature->presence_default)==='not_applicable')>Não se aplica</option>
      <option value="presential" @selected(old('presence_default',$nature->presence_default)==='presential')>Presencial</option>
      <option value="internet" @selected(old('presence_default',$nature->presence_default)==='internet')>Internet</option>
      <option value="phone" @selected(old('presence_default',$nature->presence_default)==='phone')>Teleatendimento</option>
      <option value="outside_establishment" @selected(old('presence_default',$nature->presence_default)==='outside_establishment')>Presencial fora do estabelecimento</option>
      <option value="other" @selected(old('presence_default',$nature->presence_default)==='other')>Outros</option>
    </select>
  </label>

  <div class="field col-4"><span>Consumidor final padrão</span>
    <label class="switch-field">
      <input type="hidden" name="final_consumer_default" value="0">
      <input type="checkbox" name="final_consumer_default" value="1" @checked(old('final_consumer_default',$nature->final_consumer_default))>
      <span class="switch-track"></span><strong data-switch-label>Sim</strong>
    </label>
  </div>
  <div class="field col-4"><span>Sobrescrever CFOP do produto</span>
    <label class="switch-field">
      <input type="hidden" name="override_product_cfop" value="0">
      <input type="checkbox" name="override_product_cfop" value="1" @checked(old('override_product_cfop',$nature->override_product_cfop))>
      <span class="switch-track"></span><strong data-switch-label>Sim</strong>
    </label>
  </div>

  @foreach([
    'move_stock'=>'Movimentar estoque',
    'generate_finance'=>'Gerar financeiro',
    'allow_referenced_document'=>'Permitir documento referenciado',
    'require_transport'=>'Exigir transporte',
    'require_invoice'=>'Exigir fatura',
    'require_duplicates'=>'Exigir duplicatas',
    'is_active'=>'Natureza ativa',
  ] as $field=>$label)
    <div class="field col-3"><span>{{ $label }}</span>
      <label class="switch-field">
        <input type="hidden" name="{{ $field }}" value="0">
        <input type="checkbox" name="{{ $field }}" value="1" @checked(old($field,$nature->{$field}))>
        <span class="switch-track"></span><strong data-switch-label>Sim</strong>
      </label>
    </div>
  @endforeach

  <label class="field col-6"><span>Informações complementares padrão</span>
    <textarea name="additional_info" rows="3">{{ old('additional_info',$nature->additional_info) }}</textarea>
  </label>
  <label class="field col-6"><span>Informações para o Fisco</span>
    <textarea name="tax_authority_info" rows="3">{{ old('tax_authority_info',$nature->tax_authority_info) }}</textarea>
  </label>
</div>
