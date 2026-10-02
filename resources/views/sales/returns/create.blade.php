@extends('layouts.app')
@section('title','Vendas')
@section('titleMeta','Nova devolução')
@section('content')
@include('sales._nav')

<section class="return-editor">
  <div class="return-editor-head">
    <strong>Nova devolução</strong>
    <a href="{{ route('sales.returns.index') }}" aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</a>
  </div>

  <div class="return-search-panel">
    <form method="get" action="{{ route('sales.returns.create') }}" class="return-sale-search">
      <label class="field">
        <span>Informe o código da venda para buscar os dados</span>
        <div class="return-search-row">
          <div class="input-icon-group">
            <span class="input-icon">@include('partials.icon',['name'=>'sales','size'=>17])</span>
            <input name="sale" value="{{ $raw }}" inputmode="numeric" autocomplete="off" placeholder="Ex.: 00025" autofocus>
          </div>
          <button class="btn btn-secondary" type="submit">Buscar</button>
        </div>
      </label>
    </form>

    @if($searchError)
      <div class="return-search-error">@include('partials.icon',['name'=>'alert','size'=>17]) {{ $searchError }}</div>
    @endif
  </div>

  @if($sale)
    <form method="post" action="{{ route('sales.returns.store') }}" class="return-form">
      @csrf
      <input type="hidden" name="sale_id" value="{{ $sale->id }}">

      <div class="return-sale-summary">
        <div><span>Venda</span><strong>#{{ str_pad((string)$sale->id,5,'0',STR_PAD_LEFT) }}</strong></div>
        <div><span>Cliente</span><strong>{{ $sale->customer?->name ?? 'Consumidor não identificado' }}</strong></div>
        <div><span>Data da venda</span><strong>{{ ($sale->operation_date ?? $sale->created_at)->format('d/m/Y') }}</strong></div>
        <div><span>Valor da venda</span><strong>R$ {{ number_format((float)$sale->total,2,',','.') }}</strong></div>
      </div>

      <div class="return-items-panel">
        <div class="return-section-title">
          <div>
            <h2>Itens da venda</h2>
            <p>Marque o que voltou e informe a quantidade devolvida.</p>
          </div>
          <label class="field return-date-field">
            <span>Data da devolução</span>
            <input type="date" name="return_date" value="{{ old('return_date',today()->toDateString()) }}" required>
          </label>
        </div>

        <div class="table-scroll">
          <table class="cms-table return-items-table">
            <thead>
              <tr>
                <th class="select-cell"></th>
                <th>Item</th>
                <th>Vendido</th>
                <th>Já devolvido</th>
                <th>Disponível</th>
                <th>Quantidade a devolver</th>
                <th>Motivo</th>
              </tr>
            </thead>
            <tbody>
            @foreach($sale->items as $item)
              @php
                $available=(float)($remaining[$item->id] ?? 0);
                $sold=(float)$item->quantity;
                $already=max(0,$sold-$available);
                $returnable=$available>0;
              @endphp
              <tr class="{{ !$returnable?'return-item-exhausted':'' }}">
                <td class="select-cell">
                  <input type="checkbox" data-return-item-toggle="{{ $item->id }}" @disabled(!$returnable) aria-label="Selecionar {{ $item->product_name }}">
                </td>
                <td>
                  <strong class="table-title">{{ $item->product_name }}</strong>
                  <small class="table-subtitle">{{ $item->product_sku ?: match($item->item_type){'service'=>'Serviço','freight'=>'Frete','expense'=>'Despesa',default=>'Produto'} }}</small>
                  <input type="hidden" name="items[{{ $item->id }}][sale_item_id]" value="{{ $item->id }}">
                </td>
                <td>{{ number_format($sold,3,',','.') }}</td>
                <td>{{ number_format($already,3,',','.') }}</td>
                <td class="{{ $returnable?'positive':'' }}">{{ number_format($available,3,',','.') }}</td>
                <td>
                  <input class="return-qty" type="number" name="items[{{ $item->id }}][quantity]" min="0" max="{{ number_format($available,3,'.','') }}" step="0.001" value="0" data-return-qty="{{ $item->id }}" disabled>
                </td>
                <td><input class="return-reason" name="items[{{ $item->id }}][reason]" maxlength="255" placeholder="Opcional" data-return-reason="{{ $item->id }}" disabled></td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>

        <label class="field return-notes">
          <span>Observações da devolução</span>
          <textarea name="notes" rows="3" maxlength="5000">{{ old('notes') }}</textarea>
        </label>
      </div>

      <div class="return-savebar">
        <span>O estoque dos produtos controlados será recomposto após confirmar.</span>
        <div>
          <a class="btn btn-secondary" href="{{ route('sales.returns.index') }}">Voltar</a>
          <button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'check','size'=>16]) Confirmar devolução</button>
        </div>
      </div>
    </form>
  @else
    <div class="return-empty-step">
      @include('partials.icon',['name'=>'return','size'=>34])
      <strong>Busque a venda original</strong>
      <span>Depois você poderá selecionar os itens e quantidades que estão sendo devolvidos.</span>
    </div>
  @endif
</section>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-return-item-toggle]').forEach(check=>{
    const id=check.dataset.returnItemToggle;
    const qty=document.querySelector('[data-return-qty="'+id+'"]');
    const reason=document.querySelector('[data-return-reason="'+id+'"]');
    const sync=()=>{
      const enabled=check.checked && !check.disabled;
      if(qty){
        qty.disabled=!enabled;
        if(enabled && Number(qty.value)<=0) qty.value=Math.min(1,Number(qty.max||1));
        if(!enabled) qty.value='0';
      }
      if(reason) reason.disabled=!enabled;
      check.closest('tr')?.classList.toggle('return-item-selected',enabled);
    };
    check.addEventListener('change',sync);
    sync();
  });
});
</script>
@endpush
