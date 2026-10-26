{{-- Mobile financial overview uses the same values as the existing FinanceController. --}}
<section class="lm-finance-home" aria-label="Financeiro no celular">
  <div class="lm-finance-hero">
    <span class="lm-overline">RECEBIMENTOS · {{ $monthLabel }}</span>
    <strong>R$ {{ number_format((float)$received,2,',','.') }}</strong>
    <a href="{{ route('finance.entries',['type'=>'receivable','status'=>'paid','month'=>$month]) }}">Ver recebimentos @include('partials.icon',['name'=>'chevron','size'=>16])</a>
  </div>
  <div class="lm-finance-grid">
    <a href="{{ route('finance.entries',['type'=>'payable','status'=>'paid','month'=>$month]) }}" class="lm-finance-stat">
      <small>Pago</small><strong>R$ {{ number_format((float)$paid,2,',','.') }}</strong>
    </a>
    <a href="{{ route('finance.entries',['type'=>'receivable','month'=>$month]) }}" class="lm-finance-stat">
      <small>A receber</small><strong>R$ {{ number_format((float)$receivable,2,',','.') }}</strong>
      @if((float)$overdueReceivable>0)<em>Há valores vencidos</em>@endif
    </a>
    <a href="{{ route('finance.entries',['type'=>'payable','month'=>$month]) }}" class="lm-finance-stat">
      <small>A pagar</small><strong>R$ {{ number_format((float)$payable,2,',','.') }}</strong>
      @if((float)$overduePayable>0)<em>Há valores vencidos</em>@endif
    </a>
    <a href="{{ route('finance.entries') }}" class="lm-finance-stat lm-finance-shortcut">
      <span class="lm-finance-icon">@include('partials.icon',['name'=>'receipt','size'=>22])</span>
      <strong>Lançamentos</strong><small>Consultar todos</small>
    </a>
  </div>
  <div class="lm-action-duo">
    <a href="{{ route('finance.entries.create',['type'=>'receivable']) }}">@include('partials.icon',['name'=>'plus','size'=>17]) Receber</a>
    <a href="{{ route('finance.entries.create',['type'=>'payable']) }}">@include('partials.icon',['name'=>'plus','size'=>17]) Pagar</a>
  </div>
  <div class="lm-section-title"><h3>Próximos 7 dias</h3><a href="{{ route('finance.entries') }}">Ver todos @include('partials.icon',['name'=>'chevron','size'=>14])</a></div>
  <div class="lm-feed">
    @forelse($upcoming as $entry)
      <a class="lm-feed-row" href="{{ route('finance.entries.show',$entry) }}">
        <span class="lm-feed-icon">@include('partials.icon',['name'=>'clock','size'=>18])</span>
        <span class="lm-feed-copy">
          <strong>{{ $entry->description }}</strong>
          <small>{{ $entry->due_date->format(\App\Models\AppSetting::dateFormat()) }} · {{ $entry->type_label }}</small>
        </span>
        <span class="lm-feed-amount">R$ {{ number_format((float)$entry->balance,2,',','.') }}</span>
      </a>
    @empty <p class="lm-empty">Nenhum vencimento nos próximos 7 dias.</p>
    @endforelse
  </div>
  <div class="lm-section-title"><h3>Contas financeiras</h3></div>
  <div class="lm-feed">
    @forelse($accounts as $account)
      <div class="lm-feed-row">
        <span class="lm-feed-icon">@include('partials.icon',['name'=>'money','size'=>18])</span>
        <span class="lm-feed-copy"><strong>{{ $account->name }}</strong><small>{{ match($account->type){'bank'=>'Banco','digital'=>'Conta digital','other'=>'Outra',default=>'Caixa'} }}</small></span>
        <span class="lm-feed-amount {{ $account->current_balance<0?'lm-negative':'' }}">R$ {{ number_format((float)$account->current_balance,2,',','.') }}</span>
      </div>
    @empty <p class="lm-empty">Nenhuma conta financeira ativa.</p>
    @endforelse
  </div>
</section>
