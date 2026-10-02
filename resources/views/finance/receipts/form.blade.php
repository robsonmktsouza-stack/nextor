@extends('layouts.app')
@section('title','Novo recibo')
@section('content')
@include('finance._nav')
<form method="post" enctype="multipart/form-data" action="{{ route('finance.receipts.store') }}" class="finance-reference-form">@csrf
<section class="finance-entry-banner receipt"><strong>Novo recibo</strong><span data-finance-attachment-label>Nenhum arquivo anexado</span></section>
<section class="editor-panel finance-entry-panel"><div class="editor-grid cols-12">
<label class="field col-4"><span>Quem emite o recibo</span><select name="issuer_mode"><option value="company">Empresa emite recibo</option><option value="person">Pessoa emite recibo</option></select></label>
<label class="field col-8"><span>Vias a imprimir</span><select name="copies"><option value="1">Apenas uma via</option><option value="2">Duas vias</option></select></label>
<label class="field col-4"><span>Recebi de</span><input name="recipient_name" id="receiptName" value="{{ old('recipient_name',$sourceEntry?->customer?->name) }}" required></label>
<label class="field col-4"><span>CPF ou CNPJ</span><input name="recipient_document" data-mask="cpf-cnpj" id="receiptDocument" value="{{ old('recipient_document',$sourceEntry?->customer?->document) }}"></label>
<label class="field col-4"><span>O valor de</span><input type="number" data-number-kind="money" name="amount" step="0.01" min="0.01" value="{{ old('amount',$sourceEntry?->paid_amount ?: $sourceEntry?->amount ?: '0.00') }}" required></label>
<label class="field col-4"><span>Na data</span><input type="date" name="receipt_date" value="{{ old('receipt_date',today()->format('Y-m-d')) }}" required></label>
<label class="field col-8"><span>Referente a</span><input name="reference" value="{{ old('reference',$sourceEntry?->description) }}" maxlength="500" required></label>
<label class="field col-6"><span>Vincular contato (opcional)</span><select name="customer_id" id="receiptCustomer"><option value="">Não vincular</option>@foreach($customers as $c)<option value="{{ $c->id }}" data-name="{{ $c->name }}" data-document="{{ $c->document }}" @selected((string)old('customer_id',$sourceEntry?->customer_id)===(string)$c->id)>{{ $c->name }}</option>@endforeach</select></label>
<label class="field col-6"><span>Anexo</span><input type="file" name="attachment" data-finance-attachment></label>
</div></section>
<div class="editor-savebar"><button class="btn btn-success">Salvar e visualizar</button><a class="btn btn-secondary" href="{{ route('finance.receipts.index') }}">Voltar</a></div>
</form>
@endsection
@push('scripts')
<script>document.addEventListener('DOMContentLoaded',()=>{const c=document.getElementById('receiptCustomer'),n=document.getElementById('receiptName'),d=document.getElementById('receiptDocument');c?.addEventListener('change',()=>{const o=c.options[c.selectedIndex];if(o?.value){n.value=o.dataset.name||'';d.value=o.dataset.document||'';d.dispatchEvent(new Event('input',{bubbles:true}));}});});</script>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const file=document.querySelector('[data-finance-attachment]');
 const label=document.querySelector('[data-finance-attachment-label]');
 file?.addEventListener('change',()=>{if(label) label.textContent=file.files?.[0]?.name||'Nenhum arquivo anexado';});
});
</script>
@endpush
