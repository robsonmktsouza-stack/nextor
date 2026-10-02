@extends('layouts.app')
@section('title',$entry->type==='payable'?'Conta a pagar':'Conta a receber')
@section('actions')
@if($entry->attachment_path)
<a class="btn btn-secondary" href="{{ route('finance.entries.attachment',$entry) }}">@include('partials.icon',['name'=>'download','size'=>15]) {{ $entry->attachment_name }}</a>
@endif
@endsection

@section('content')
<form class="finance-editor finance-reference-form" method="post" enctype="multipart/form-data" action="{{ $editing ? route('finance.entries.update',$entry) : route('finance.entries.store') }}">
@csrf
@if($editing) @method('put') @endif
<input type="hidden" name="type" id="financeEntryType" value="{{ old('type',$entry->type) }}">

<section class="finance-entry-banner {{ $entry->type==='payable'?'payable':'receivable' }}">
  <strong>{{ $entry->type==='payable'?'Conta a pagar':'Conta a receber' }}</strong>
  <span data-finance-attachment-label>{{ $entry->attachment_name ?: 'Nenhum arquivo anexado' }}</span>
</section>

<section class="editor-panel finance-entry-panel">
  <div class="editor-grid cols-12">
    <label class="field col-12"><span>Descrição</span><input name="description" maxlength="190" value="{{ old('description',$entry->description) }}" required autofocus></label>

    <label class="field col-4"><span>Valor</span><input type="number" data-number-kind="money" name="amount" step="0.01" min="0.01" value="{{ old('amount',$entry->amount ?: '0.00') }}" required></label>

    <div class="field col-2">
      <span>{{ $entry->type==='payable'?'Pagar agora':'Receber agora' }}</span>
      <label class="switch-field finance-now-switch">
        <input type="checkbox" name="settle_now" value="1" id="financeSettleNow" @checked(old('settle_now'))>
        <span class="switch-track"></span><strong data-switch-label>{{ old('settle_now')?'Sim':'Não' }}</strong>
      </label>
    </div>

    <label class="field col-3"><span>Data de vencimento</span><input type="date" name="due_date" value="{{ old('due_date',optional($entry->due_date)->format('Y-m-d') ?? today()->format('Y-m-d')) }}" required></label>

    @if($entry->type==='receivable')
    <label class="field col-3"><span>Data de crédito (previsão)</span><input type="date" name="credit_date" value="{{ old('credit_date',optional($entry->credit_date)->format('Y-m-d')) }}"></label>
    @else
    <label class="field col-3"><span>Data de emissão</span><input type="date" name="issue_date" value="{{ old('issue_date',optional($entry->issue_date)->format('Y-m-d') ?? today()->format('Y-m-d')) }}" required></label>
    @endif

    <label class="field col-12"><span>{{ $entry->type==='payable'?'Pago a':'Recebido de' }}</span>
      <select name="customer_id"><option value="">Não vincular contato</option>
      @foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((int)old('customer_id',$entry->customer_id)===$customer->id)>{{ $customer->name }}{{ $customer->document?' — '.$customer->document:'' }}</option>@endforeach
      </select>
    </label>

    <label class="field col-6"><span>Categoria</span><select name="category_id" required>
      <option value="">Selecione</option>
      @foreach($categories as $category)
        @if(($entry->type==='receivable' && $category->type==='income') || ($entry->type==='payable' && $category->type==='expense'))
        <option value="{{ $category->id }}" @selected((int)old('category_id',$entry->category_id)===$category->id)>{{ $category->name }}</option>
        @endif
      @endforeach
    </select></label>

    <label class="field col-6"><span>Palavras-chave</span><input name="keywords" maxlength="255" value="{{ old('keywords',$entry->keywords) }}" placeholder="Ex.: aluguel, fornecedor, mensalidade"></label>

    <label class="field col-4"><span>Data de competência</span><input type="date" name="competence_date" value="{{ old('competence_date',optional($entry->competence_date)->format('Y-m-d') ?? today()->format('Y-m-d')) }}"></label>
    @if($entry->type==='receivable')
    <input type="hidden" name="issue_date" value="{{ old('issue_date',optional($entry->issue_date)->format('Y-m-d') ?? today()->format('Y-m-d')) }}">
    @endif

    <label class="field col-4"><span>Conta</span><select name="financial_account_id">
      <option value="">Selecionar depois</option>
      @foreach($accounts as $account)<option value="{{ $account->id }}" @selected((int)old('financial_account_id',$entry->financial_account_id)===$account->id)>{{ $account->name }}</option>@endforeach
    </select></label>

    <label class="field col-4"><span>Forma de pagamento</span><select name="payment_method">
      <option value="">Não informada</option>
      @foreach($paymentMethods as $value=>$label)<option value="{{ $value }}" @selected(old('payment_method',$entry->payment_method)===$value)>{{ $label }}</option>@endforeach
    </select></label>

    <label class="field col-4 finance-settle-date" @if(!old('settle_now')) hidden @endif><span>Data da baixa</span><input type="date" name="settled_at" value="{{ old('settled_at',today()->format('Y-m-d')) }}"></label>

    <label class="field col-4"><span>Nº documento</span><input name="document_number" maxlength="80" value="{{ old('document_number',$entry->document_number) }}"></label>
    <label class="field col-4"><span>Anexo</span><input type="file" name="attachment" data-finance-attachment></label>

    <label class="field col-12"><span>Outras opções / observações</span><textarea name="notes" rows="3">{{ old('notes',$entry->notes) }}</textarea></label>
  </div>
</section>

<div class="editor-savebar">
  <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'check','size'=>16]) Salvar</button>
  <a class="btn btn-secondary" href="{{ route('finance.entries',['type'=>$entry->type]) }}">Voltar</a>
</div>
</form>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const toggle=document.getElementById('financeSettleNow');
 const date=document.querySelector('.finance-settle-date');
 const label=document.querySelector('[data-finance-attachment-label]');
 const file=document.querySelector('[data-finance-attachment]');
 const sync=()=>{if(date) date.hidden=!toggle?.checked;};
 toggle?.addEventListener('change',sync);sync();
 file?.addEventListener('change',()=>{if(label) label.textContent=file.files?.[0]?.name||'Nenhum arquivo anexado';});
});
</script>
@endpush
