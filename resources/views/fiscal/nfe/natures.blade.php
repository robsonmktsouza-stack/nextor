@extends('layouts.app')
@section('title','Naturezas de Operação')
@section('titleMeta')NF-e
@section('content')
@include('fiscal._nav',['tab'=>'nfe','tabs'=>[
  'nfe'=>['label'=>'NF-e'],'nfse'=>['label'=>'NFS-e'],'cte'=>['label'=>'CT-e'],'nfce'=>['label'=>'NFC-e']
]])

<section class="cms-card sales-module-card">
  <div class="grid-actionbar">
    <div class="grid-actions-left">
      <button class="grid-primary-action" type="button" data-dialog-open="nature-create">
        @include('partials.icon',['name'=>'plus','size'=>17]) <span>Nova</span>
      </button>
    </div>
    <div class="grid-actions-right">
      <a class="btn btn-secondary" href="{{ route('fiscal.nfe.create') }}">Nova NF-e</a>
      <a class="btn btn-light" href="{{ route('fiscal.index',['tab'=>'nfe']) }}">Voltar</a>
    </div>
  </div>

  <div class="table-scroll">
    <table class="cms-table">
      <thead>
        <tr>
          <th>Natureza</th>
          <th>Finalidade</th>
          <th>CFOP saída</th>
          <th>CFOP entrada</th>
          <th>CFOP do produto</th>
          <th>Estoque</th>
          <th>Status</th>
          <th class="action-cell"></th>
        </tr>
      </thead>
      <tbody>
      @forelse($natures as $nature)
        <tr>
          <td>
            <strong class="table-title">{{ $nature->name }}</strong>
            <small class="table-subtitle">Padrão: {{ $nature->operation_type==='outbound'?'Saída':'Entrada' }}</small>
          </td>
          <td>{{ ['normal'=>'1 - Normal','complementary'=>'2 - Complementar','adjustment'=>'3 - Ajuste','return'=>'4 - Devolução','credit_note'=>'5 - Nota de crédito','debit_note'=>'6 - Nota de débito'][$nature->purpose] ?? $nature->purpose }}</td>
          <td>
            <span class="code-tag">{{ $nature->cfop_internal ?: '—' }}</span>
            <small class="table-subtitle">Interestadual: {{ $nature->cfop_interstate ?: '—' }}</small>
          </td>
          <td>
            <span class="code-tag">{{ $nature->cfop_inbound_internal ?: '—' }}</span>
            <small class="table-subtitle">Interestadual: {{ $nature->cfop_inbound_interstate ?: '—' }}</small>
          </td>
          <td>{{ $nature->override_product_cfop?'Sobrescreve':'Mantém quando informado' }}</td>
          <td>{{ $nature->move_stock?'Movimenta':'Não movimenta' }}</td>
          <td><span class="status {{ $nature->is_active?'status-ok':'status-muted' }}">{{ $nature->is_active?'Ativa':'Inativa' }}</span></td>
          <td class="action-cell">
            <button class="btn-icon" type="button" data-dialog-open="nature-edit-{{ $nature->id }}" data-tooltip="Editar">
              @include('partials.icon',['name'=>'edit','size'=>16])
            </button>
          </td>
        </tr>
      @empty
        <tr><td colspan="8" class="empty-cell">Nenhuma natureza de operação cadastrada.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</section>

<dialog class="erp-dialog nfe-wide-dialog" id="nature-create">
  <form method="post" action="{{ route('fiscal.nfe.natures.store') }}">
    @csrf
    <div class="dialog-header">
      <div><h2>Nova natureza de operação</h2><p>CFOPs e comportamento padrão usados pela NF-e.</p></div>
      <button type="button" data-dialog-close class="close-dialog" aria-label="Fechar">@include('partials.icon',['name'=>'x'])</button>
    </div>
    <div class="dialog-body">@include('fiscal.nfe._nature_form')</div>
    <div class="dialog-footer">
      <button type="button" data-dialog-close class="btn btn-secondary">Cancelar</button>
      <button class="btn btn-success">Salvar</button>
    </div>
  </form>
</dialog>

@foreach($natures as $nature)
<dialog class="erp-dialog nfe-wide-dialog" id="nature-edit-{{ $nature->id }}">
  <form method="post" action="{{ route('fiscal.nfe.natures.update',$nature) }}">
    @csrf @method('PUT')
    <div class="dialog-header">
      <div><h2>Editar natureza de operação</h2><p>{{ $nature->name }}</p></div>
      <button type="button" data-dialog-close class="close-dialog" aria-label="Fechar">@include('partials.icon',['name'=>'x'])</button>
    </div>
    <div class="dialog-body">@include('fiscal.nfe._nature_form',['nature'=>$nature])</div>
    <div class="dialog-footer">
      <button type="button" data-dialog-close class="btn btn-secondary">Cancelar</button>
      <button class="btn btn-success">Salvar alterações</button>
    </div>
  </form>
</dialog>
@endforeach
@endsection
