@extends('layouts.app')
@section('title','Nova recorrência')
@section('content')
@include('finance._nav')
<form method="post" action="{{ route('finance.recurrences.store') }}" class="finance-reference-form">@csrf
<section class="finance-entry-banner recurrence"><strong>Nova recorrência</strong><span>Geração automática de contas futuras</span></section>
<section class="editor-panel finance-entry-panel"><div class="editor-grid cols-12">
<label class="field col-3"><span>Tipo</span><select name="type" id="recType"><option value="receivable">Recebimento</option><option value="payable" @selected(old('type')==='payable')>Pagamento</option></select></label>
<label class="field col-6"><span>Descrição</span><input name="description" value="{{ old('description') }}" maxlength="190" required></label>
<label class="field col-3"><span>Valor</span><input type="number" data-number-kind="money" name="amount" step="0.01" min="0.01" value="{{ old('amount','0.00') }}" required></label>
<label class="field col-4"><span>Contato</span><select name="customer_id"><option value="">Não vincular</option>@foreach($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id')==$c->id)>{{ $c->name }}</option>@endforeach</select></label>
<label class="field col-4"><span>Categoria</span><select name="category_id" id="recCategory" required><option value="">Selecione</option>@foreach($categories as $c)<option value="{{ $c->id }}" data-category-type="{{ $c->type }}" @selected(old('category_id')==$c->id)>{{ $c->name }}</option>@endforeach</select></label>
<label class="field col-4"><span>Conta prevista</span><select name="financial_account_id"><option value="">Selecionar depois</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
<label class="field col-3"><span>Periodicidade</span><select name="frequency"><option value="monthly">Mensal</option><option value="weekly">Semanal</option><option value="yearly">Anual</option></select></label>
<label class="field col-2"><span>A cada</span><input type="number" name="interval_count" min="1" max="24" value="{{ old('interval_count',1) }}" required></label>
<label class="field col-3"><span>Início</span><input type="date" name="start_date" value="{{ old('start_date',today()->format('Y-m-d')) }}" required></label>
<label class="field col-4"><span>Fim (opcional)</span><input type="date" name="end_date" value="{{ old('end_date') }}"></label>
<label class="field col-6"><span>Palavras-chave</span><input name="keywords" value="{{ old('keywords') }}"></label>
<label class="field col-6"><span>Observações</span><input name="notes" value="{{ old('notes') }}"></label>
</div></section>
<div class="editor-savebar"><button class="btn btn-success">Salvar recorrência</button><a class="btn btn-secondary" href="{{ route('finance.recurrences.index') }}">Voltar</a></div>
</form>
@endsection
@push('scripts')
<script>document.addEventListener('DOMContentLoaded',()=>{const t=document.getElementById('recType'),c=document.getElementById('recCategory');const s=()=>{const w=t.value==='payable'?'expense':'income';[...c.options].forEach(o=>{if(!o.value)return;o.hidden=o.dataset.categoryType!==w;o.disabled=o.hidden;if(o.hidden&&o.selected)c.value='';});};t.addEventListener('change',s);s();});</script>
@endpush
