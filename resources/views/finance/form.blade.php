@extends('layouts.app')
@section('title',$editing?'Editar lançamento':'Novo lançamento')
@section('actions')
<a class="btn btn-secondary" href="{{ $editing ? route('finance.entries.show',$entry) : route('finance.entries') }}">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Voltar</a>
@endsection

@section('content')
<form class="finance-editor" method="post" action="{{ $editing ? route('finance.entries.update',$entry) : route('finance.entries.store') }}">
@csrf
@if($editing) @method('put') @endif

<section class="editor-panel">
  <div class="editor-grid cols-12">
    <label class="field col-3">
      <span>Tipo</span>
      <select name="type" id="financeEntryType" required>
        <option value="receivable" @selected(old('type',$entry->type)==='receivable')>Conta a receber</option>
        <option value="payable" @selected(old('type',$entry->type)==='payable')>Conta a pagar</option>
      </select>
    </label>

    <label class="field col-5">
      <span>Descrição</span>
      <input name="description" maxlength="190" value="{{ old('description',$entry->description) }}" required autofocus>
    </label>

    <label class="field col-4">
      <span>Categoria</span>
      <select name="category_id" id="financeCategory" required>
        <option value="">Selecione</option>
        @foreach($categories as $category)
          <option value="{{ $category->id }}" data-category-type="{{ $category->type }}" @selected((int)old('category_id',$entry->category_id)===$category->id)>
            {{ $category->name }}
          </option>
        @endforeach
      </select>
    </label>

    <label class="field col-5">
      <span>Cliente / fornecedor / pessoa</span>
      <select name="customer_id">
        <option value="">Não vincular</option>
        @foreach($customers as $customer)
          <option value="{{ $customer->id }}" @selected((int)old('customer_id',$entry->customer_id)===$customer->id)>
            {{ $customer->name }}{{ $customer->document ? ' — '.$customer->document : '' }}
          </option>
        @endforeach
      </select>
    </label>

    <label class="field col-3">
      <span>Nº documento</span>
      <input name="document_number" maxlength="80" value="{{ old('document_number',$entry->document_number) }}" placeholder="NF, boleto, contrato...">
    </label>

    <label class="field col-2">
      <span>Emissão</span>
      <input type="date" name="issue_date" value="{{ old('issue_date',optional($entry->issue_date)->format('Y-m-d') ?? today()->format('Y-m-d')) }}" required>
    </label>

    <label class="field col-2">
      <span>Vencimento</span>
      <input type="date" name="due_date" value="{{ old('due_date',optional($entry->due_date)->format('Y-m-d') ?? today()->format('Y-m-d')) }}" required>
    </label>

    <label class="field col-3">
      <span>Valor</span>
      <input type="number" data-number-kind="money" name="amount" step="0.01" min="0.01" value="{{ old('amount',$entry->amount ?: '0.00') }}" required>
    </label>

    <label class="field col-4">
      <span>Forma prevista</span>
      <select name="payment_method">
        <option value="">Não informada</option>
        @foreach($paymentMethods as $value=>$label)
          <option value="{{ $value }}" @selected(old('payment_method',$entry->payment_method)===$value)>{{ $label }}</option>
        @endforeach
      </select>
    </label>

    <label class="field col-12">
      <span>Observações</span>
      <textarea name="notes" rows="4">{{ old('notes',$entry->notes) }}</textarea>
    </label>
  </div>
</section>

<div class="editor-savebar">
  <span class="editor-savebar-note">{{ $editing ? 'Alterações no lançamento financeiro' : 'Novo lançamento financeiro' }}</span>
  <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'check','size'=>16]) {{ $editing?'Salvar alterações':'Salvar lançamento' }}</button>
  <a class="btn btn-secondary" href="{{ $editing ? route('finance.entries.show',$entry) : route('finance.entries') }}">Cancelar</a>
</div>
</form>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const type=document.getElementById('financeEntryType');
  const category=document.getElementById('financeCategory');
  if(!type||!category) return;

  const sync=()=>{
    const wanted=type.value==='payable'?'expense':'income';
    [...category.options].forEach(option=>{
      if(!option.value) return;
      const visible=option.dataset.categoryType===wanted;
      option.hidden=!visible;
      option.disabled=!visible;
      if(!visible && option.selected) category.value='';
    });
    category.dispatchEvent(new Event('change',{bubbles:true}));
  };

  type.addEventListener('change',sync);
  sync();
});
</script>
@endpush
