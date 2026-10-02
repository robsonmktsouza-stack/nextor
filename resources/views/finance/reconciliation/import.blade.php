@extends('layouts.app')
@section('title','Enviar arquivo bancário')
@section('content')
@include('finance._nav')
<form method="post" enctype="multipart/form-data" action="{{ route('finance.reconciliation.store-import') }}" class="finance-reference-form">@csrf
<section class="finance-entry-banner reconciliation"><strong>Enviar arquivo bancário</strong><span>OFX, CSV ou XLSX</span></section>
<section class="editor-panel finance-entry-panel"><div class="editor-grid cols-12">
<label class="field col-6"><span>Arquivo bancário</span><input type="file" name="file" accept=".ofx,.csv,.xlsx" required><small>Prefira OFX exportado pelo banco. CSV e XLSX também são aceitos.</small></label>
<label class="field col-6"><span>Conta relacionada</span><select name="financial_account_id" required><option value="">Selecione a conta</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
</div></section><div class="editor-savebar"><button class="btn btn-success">Enviar arquivo</button><a class="btn btn-secondary" href="{{ route('finance.reconciliation.index') }}">Voltar</a></div></form>
@endsection
