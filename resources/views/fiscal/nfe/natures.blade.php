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
          <th>Natureza</th><th>Tipo</th><th>Finalidade</th><th>CFOP interno</th>
          <th>CFOP interestadual</th><th>Status</th><th class="action-cell">Detalhes</th>
        </tr>
      </thead>
      <tbody>
      @forelse($natures as $nature)
        <tr>
          <td><strong class="table-title">{{ $nature->name }}</strong></td>
          <td><span class="status status-blue">{{ $nature->operation_type==='outbound'?'Saída':'Entrada' }}</span></td>
          <td>{{ ['normal'=>'Normal','complementary'=>'Complementar','adjustment'=>'Ajuste','return'=>'Devolução'][$nature->purpose] ?? $nature->purpose }}</td>
          <td>{{ $nature->cfop_internal ?: '—' }}</td>
          <td>{{ $nature->cfop_interstate ?: '—' }}</td>
          <td><span class="status {{ $nature->is_active?'status-ok':'status-muted' }}">{{ $nature->is_active?'Ativa':'Inativa' }}</span></td>
          <td class="action-cell">
            <button class="btn-icon" type="button" data-dialog-open="nature-edit-{{ $nature->id }}" data-tooltip="Editar">
              @include('partials.icon',['name'=>'edit','size'=>16])
            </button>
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="empty-cell">Nenhuma natureza de operação cadastrada. Cadastre a primeira para iniciar uma NF-e.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</section>

<dialog class="erp-dialog nature-dialog" id="nature-create">
  <form method="post" action="{{ route('fiscal.nfe.natures.store') }}">@csrf
    <div class="dialog-header"><div><h2>Nova natureza de operação</h2><p>Regras padrão usadas no preenchimento da NF-e.</p></div><button type="button" data-dialog-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button></div>
    <div class="dialog-body">@include('fiscal.nfe._nature_form',['nature'=>new \App\Models\OperationNature()])</div>
    <div class="dialog-footer"><button type="button" data-dialog-close class="btn btn-secondary">Cancelar</button><button class="btn btn-success">Salvar</button></div>
  </form>
</dialog>

@foreach($natures as $nature)
<dialog class="erp-dialog nature-dialog" id="nature-edit-{{ $nature->id }}">
  <form method="post" action="{{ route('fiscal.nfe.natures.update',$nature) }}">@csrf @method('PUT')
    <div class="dialog-header"><div><h2>Editar natureza</h2><p>{{ $nature->name }}</p></div><button type="button" data-dialog-close class="close-dialog">@include('partials.icon',['name'=>'x'])</button></div>
    <div class="dialog-body">@include('fiscal.nfe._nature_form',['nature'=>$nature,'prefix'=>'nature-'.$nature->id])</div>
    <div class="dialog-footer"><button type="button" data-dialog-close class="btn btn-secondary">Cancelar</button><button class="btn btn-success">Salvar alterações</button></div>
  </form>
</dialog>
@endforeach
@endsection
