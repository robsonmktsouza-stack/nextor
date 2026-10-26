{{-- Lumeron Mobile: presentation only; all metrics originate from DashboardController. --}}
<section class="lm-home" aria-label="Resumo para celular">
  <div class="lm-home-welcome">
    <div>
      <span class="lm-overline">RESUMO DO NEGÓCIO</span>
      <h2>Olá, {{ explode(' ', trim(auth()->user()->name))[0] }}</h2>
      <p>{{ now()->translatedFormat('l, d \d\e F') }}</p>
    </div>
    <span class="lm-home-mark"><img src="{{ asset('images/lumeron/elephant.png') }}" alt="" loading="lazy"></span>
  </div>

  @if($licensedPdv && auth()->user()->canAccess('sales'))
    <a class="lm-primary-metric" href="{{ route('sales.index') }}">
      <span class="lm-metric-label">@include('partials.icon',['name'=>'sales','size'=>18]) Vendas concluídas</span>
      <strong>R$ {{ number_format((float)$salesTotal,2,',','.') }}</strong>
      <span class="lm-metric-foot">{{ number_format($salesCount,0,',','.') }} venda(s) registrada(s)
        @include('partials.icon',['name'=>'chevron','size'=>16])
      </span>
    </a>
  @endif

  <div class="lm-metric-grid">
    @if($licensedProducts && auth()->user()->canAccess('products'))
      <a href="{{ route('products.index') }}" class="lm-mini-metric">
        <span class="lm-mini-icon">@include('partials.icon',['name'=>'products','size'=>20])</span>
        <strong>{{ number_format($productsCount,0,',','.') }}</strong>
        <span>Produtos ativos</span>
      </a>
    @endif
    @if($licensedProducts && auth()->user()->canAccess('stock'))
      <a href="{{ route('stock.index') }}" class="lm-mini-metric {{ (int)$lowStock>0 ? 'lm-mini-warning' : '' }}">
        <span class="lm-mini-icon">@include('partials.icon',['name'=>'alert','size'=>20])</span>
        <strong>{{ number_format($lowStock,0,',','.') }}</strong>
        <span>Estoque baixo</span>
      </a>
    @endif
  </div>

  <div class="lm-section-title"><h3>Acesso rápido</h3></div>
  <div class="lm-action-grid">
    @if($licensedPdv && auth()->user()->canAccess('sales'))
      <a data-company-required href="{{ route('sales.create') }}" class="lm-action">
        <span class="lm-action-icon">@include('partials.icon',['name'=>'plus','size'=>23])</span>
        <strong>Nova venda</strong>
        <small>Venda ou orçamento</small>
      </a>
    @endif
    @if($licensedPdv && auth()->user()->canAccess('pdv'))
      <a data-company-required href="{{ route('pdv.index') }}" class="lm-action">
        <span class="lm-action-icon">@include('partials.icon',['name'=>'pdv','size'=>23])</span>
        <strong>Abrir PDV</strong>
        <small>Operação de caixa</small>
      </a>
    @endif
    @if(auth()->user()->canAccess('customers'))
      <a href="{{ route('customers.create') }}" class="lm-action">
        <span class="lm-action-icon">@include('partials.icon',['name'=>'customers','size'=>23])</span>
        <strong>Novo cliente</strong>
        <small>Cadastro rápido</small>
      </a>
    @endif
    @if($licensedProducts && auth()->user()->canAccess('stock'))
      <a href="{{ route('stock.index') }}" class="lm-action">
        <span class="lm-action-icon">@include('partials.icon',['name'=>'stock','size'=>23])</span>
        <strong>Estoque</strong>
        <small>Consultar e movimentar</small>
      </a>
    @endif
    @if(\App\Support\CommercialModuleAccess::visible(request()->attributes->get('lumeron_commercial_license', []),'finance') && auth()->user()->canAccess('finance'))
      <a data-company-required href="{{ route('finance.dashboard') }}" class="lm-action">
        <span class="lm-action-icon">@include('partials.icon',['name'=>'money','size'=>23])</span>
        <strong>Financeiro</strong>
        <small>Contas e saldos</small>
      </a>
    @endif
    @if($licensedProducts && auth()->user()->canAccess('products'))
      <a href="{{ route('products.create') }}" class="lm-action">
        <span class="lm-action-icon">@include('partials.icon',['name'=>'products','size'=>23])</span>
        <strong>Novo produto</strong>
        <small>Cadastrar item</small>
      </a>
    @endif
  </div>

  @if($licensedPdv && auth()->user()->canAccess('sales'))
    <div class="lm-section-title">
      <h3>Últimas vendas</h3>
      <a href="{{ route('sales.index') }}">Ver todas @include('partials.icon',['name'=>'chevron','size'=>14])</a>
    </div>
    <div class="lm-feed">
      @forelse($recentSales as $sale)
        <a href="{{ route('sales.show',$sale) }}" class="lm-feed-row">
          <span class="lm-feed-icon">@include('partials.icon',['name'=>'receipt','size'=>20])</span>
          <span class="lm-feed-copy">
            <strong>{{ $sale->customer?->name ?? 'Consumidor não identificado' }}</strong>
            <small>#{{ str_pad($sale->id,5,'0',STR_PAD_LEFT) }} · {{ $sale->status==='completed'?'Concluída':'Cancelada' }}</small>
          </span>
          <span class="lm-feed-amount">R$ {{ number_format((float)$sale->total,2,',','.') }}</span>
        </a>
      @empty
        <p class="lm-empty">Ainda não há vendas registradas.</p>
      @endforelse
    </div>
  @endif

  @if($licensedProducts && auth()->user()->canAccess('stock') && (int)$lowStock>0)
    <div class="lm-section-title">
      <h3>Repor estoque</h3>
      <a href="{{ route('stock.index') }}">Ver estoque @include('partials.icon',['name'=>'chevron','size'=>14])</a>
    </div>
    <div class="lm-feed">
      @foreach($lowProducts as $product)
        <a href="{{ route('stock.index',['product_id'=>$product->id,'open'=>1]) }}" class="lm-feed-row">
          <span class="lm-feed-icon lm-feed-alert">@include('partials.icon',['name'=>'alert','size'=>19])</span>
          <span class="lm-feed-copy"><strong>{{ $product->name }}</strong><small>{{ $product->sku }}</small></span>
          <span class="lm-feed-amount lm-stock-low">{{ number_format((float)$product->stock_quantity,3,',','.') }} {{ $product->unit }}</span>
        </a>
      @endforeach
    </div>
  @endif
</section>
