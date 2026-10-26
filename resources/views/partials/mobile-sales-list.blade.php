<div class="lm-native-list lm-sales-list" aria-label="Vendas e orçamentos no celular">
  <div class="lm-list-summary"><span>{{ $sales->total() }} operação(ões)</span><span>{{ $monthLabel }}</span></div>
  @forelse($sales as $sale)
    <article class="lm-list-card">
      <a class="lm-card-main" href="{{ route('sales.show',$sale) }}">
        <span class="lm-card-identity">
          <span class="lm-list-avatar {{ $sale->operation_type==='quote' ? 'lm-avatar-quote' : '' }}">@include('partials.icon',['name'=>$sale->operation_type==='quote'?'receipt':'sales','size'=>19])</span>
          <span class="lm-card-copy"><strong>{{ $sale->customer?->name ?? 'Consumidor não identificado' }}</strong><small>#{{ str_pad($sale->id,5,'0',STR_PAD_LEFT) }} · {{ ($sale->operation_date ?? $sale->created_at)->format(\App\Models\AppSetting::dateFormat()) }}</small></span>
          @include('partials.icon',['name'=>'chevron','size'=>18])
        </span>
      </a>
      <div class="lm-card-facts lm-sale-facts">
        <span><small>{{ $sale->operation_type==='quote'?'Orçamento':'Venda' }}</small><strong>R$ {{ number_format((float)$sale->total,2,',','.') }}</strong></span>
        <span class="lm-sale-state"><small>Status</small><span class="lm-state {{ $sale->status==='completed'?'lm-state-ok':'lm-state-muted' }}">{{ match($sale->status){'converted'=>'Convertido','cancelled'=>'Cancelado',default=>$sale->operation_type==='quote'?'Em aberto':'Concluída'} }}</span></span>
      </div>
      <div class="lm-card-bottom">
        <span class="lm-customer-locale">{{ $sale->user?->name ?? '—' }}</span>
        @if($sale->operation_type==='quote')
          <a href="{{ route('sales.quote.pdf',$sale) }}" target="_blank" rel="noopener noreferrer">@include('partials.icon',['name'=>'print','size'=>15]) PDF</a>
        @else
          <a href="{{ route('sales.receipt',$sale) }}" target="_blank" rel="noopener noreferrer">@include('partials.icon',['name'=>'receipt','size'=>15]) Comprovante</a>
        @endif
      </div>
    </article>
  @empty
    <div class="lm-empty">Nenhuma venda ou orçamento encontrado.</div>
  @endforelse
</div>
