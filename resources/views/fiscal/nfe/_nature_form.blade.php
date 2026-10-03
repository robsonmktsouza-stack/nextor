@php
  $nature=$nature ?? new \App\Models\OperationNature([
    'operation_type'=>'outbound',
    'purpose'=>'normal',
    'override_product_cfop'=>true,
    'move_stock'=>true,
    'is_active'=>true,
  ]);
@endphp

<div class="editor-panel">
  <h3>Dados da natureza</h3>
  <div class="editor-grid cols-12">
    <label class="field col-7">
      <span>Descrição *</span>
      <input name="name" value="{{ old('name',$nature->name) }}" required maxlength="160" placeholder="Ex.: Venda de mercadoria adquirida de terceiros">
    </label>

    <label class="field col-2">
      <span>Tipo padrão *</span>
      <select name="operation_type">
        <option value="outbound" @selected(old('operation_type',$nature->operation_type)==='outbound')>Saída</option>
        <option value="inbound" @selected(old('operation_type',$nature->operation_type)==='inbound')>Entrada</option>
      </select>
    </label>

    <label class="field col-3">
      <span>Finalidade da NF-e *</span>
      <select name="purpose">
        <option value="normal" @selected(old('purpose',$nature->purpose)==='normal')>1 - NF-e normal</option>
        <option value="complementary" @selected(old('purpose',$nature->purpose)==='complementary')>2 - NF-e complementar</option>
        <option value="adjustment" @selected(old('purpose',$nature->purpose)==='adjustment')>3 - NF-e de ajuste</option>
        <option value="return" @selected(old('purpose',$nature->purpose)==='return')>4 - Devolução de mercadoria</option>
        <option value="credit_note" @selected(old('purpose',$nature->purpose)==='credit_note')>5 - Nota de crédito</option>
        <option value="debit_note" @selected(old('purpose',$nature->purpose)==='debit_note')>6 - Nota de débito</option>
      </select>
    </label>
  </div>
</div>

<div class="editor-panel">
  <h3>CFOP da operação</h3>
  <div class="editor-grid cols-12">
    <label class="field col-3"><span>Saída dentro do estado</span><input name="cfop_internal" value="{{ old('cfop_internal',$nature->cfop_internal) }}" maxlength="10" placeholder="5102"></label>
    <label class="field col-3"><span>Saída para outro estado</span><input name="cfop_interstate" value="{{ old('cfop_interstate',$nature->cfop_interstate) }}" maxlength="10" placeholder="6102"></label>
    <label class="field col-3"><span>Entrada dentro do estado</span><input name="cfop_inbound_internal" value="{{ old('cfop_inbound_internal',$nature->cfop_inbound_internal) }}" maxlength="10" placeholder="1102"></label>
    <label class="field col-3"><span>Entrada de outro estado</span><input name="cfop_inbound_interstate" value="{{ old('cfop_inbound_interstate',$nature->cfop_inbound_interstate) }}" maxlength="10" placeholder="2102"></label>
    <label class="field col-3"><span>Exterior</span><input name="cfop_foreign" value="{{ old('cfop_foreign',$nature->cfop_foreign) }}" maxlength="10" placeholder="7102"></label>

    <div class="field col-4">
      <span>Sobrescrever CFOP do produto?</span>
      <label class="switch-field">
        <input type="hidden" name="override_product_cfop" value="0">
        <input type="checkbox" name="override_product_cfop" value="1" @checked(old('override_product_cfop',$nature->override_product_cfop))>
        <span class="switch-track"></span><strong data-switch-label>Sim</strong>
      </label>
    </div>

    <div class="field col-4">
      <span>Movimentar estoque?</span>
      <label class="switch-field">
        <input type="hidden" name="move_stock" value="0">
        <input type="checkbox" name="move_stock" value="1" @checked(old('move_stock',$nature->move_stock))>
        <span class="switch-track"></span><strong data-switch-label>Sim</strong>
      </label>
    </div>

    <div class="field col-4">
      <span>Natureza ativa?</span>
      <label class="switch-field">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active',$nature->is_active))>
        <span class="switch-track"></span><strong data-switch-label>Sim</strong>
      </label>
    </div>
  </div>
</div>

<div class="editor-panel notes-panel">
  <label class="field">
    <span>Informação adicional padrão</span>
    <textarea name="additional_info" rows="4" placeholder="Texto padrão que deve acompanhar esta natureza">{{ old('additional_info',$nature->additional_info) }}</textarea>
  </label>
</div>
