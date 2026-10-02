@extends('layouts.app')
@section('title','Transferência entre contas')
@section('content')
@include('finance._nav')
<form method="post" action="{{ route('finance.transfers.store') }}" class="finance-reference-form">@csrf
<section class="finance-entry-banner transfer"><strong>Transferência entre contas</strong><span>Movimenta saldo sem gerar receita ou despesa</span></section>
<section class="editor-panel finance-entry-panel"><div class="editor-grid cols-12">
<label class="field col-4"><span>Valor</span><input type="number" data-number-kind="money" name="amount" step="0.01" min="0.01" value="{{ old('amount','0.00') }}" required></label>
<label class="field col-4"><span>Origem</span><select name="from_account_id" required><option value="">Selecione a origem</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected(old('from_account_id')==$a->id)>{{ $a->name }}</option>@endforeach</select></label>
<label class="field col-4"><span>Destino</span><select name="to_account_id" required><option value="">Selecione o destino</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected(old('to_account_id')==$a->id)>{{ $a->name }}</option>@endforeach</select></label>
<label class="field col-3"><span>Data</span><input type="date" name="transfer_date" value="{{ old('transfer_date',today()->format('Y-m-d')) }}" required></label>
<label class="field col-9"><span>Descrição</span><input name="description" maxlength="190" value="{{ old('description') }}" placeholder="Transferência entre contas"></label>
<label class="field col-12"><span>Outras opções / observações</span><textarea name="notes" rows="3">{{ old('notes') }}</textarea></label>
</div></section>
<div class="editor-savebar"><button class="btn btn-success">Salvar transferência</button><a class="btn btn-secondary" href="{{ route('finance.transfers.index') }}">Voltar</a></div>
</form>
@endsection
