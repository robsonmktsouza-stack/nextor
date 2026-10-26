<div class="lm-native-list lm-customers-list" aria-label="Clientes no celular">
  <div class="lm-list-summary"><span>{{ $customers->total() }} cliente(s)</span><span>Toque para editar</span></div>
  @forelse($customers as $customer)
    <article class="lm-list-card">
      <a class="lm-card-main" href="{{ route('customers.edit',$customer) }}">
        <span class="lm-card-identity">
          <span class="lm-list-avatar lm-avatar-customer">@include('partials.icon',['name'=>'customers','size'=>19])</span>
          <span class="lm-card-copy"><strong>{{ $customer->name }}</strong><small>{{ $customer->trade_name ?: ($customer->document ?: 'Sem documento') }}</small></span>
          @include('partials.icon',['name'=>'chevron','size'=>18])
        </span>
      </a>
      <div class="lm-card-bottom">
        <span class="lm-customer-locale">{{ $customer->city ? $customer->city.($customer->state?'/'.$customer->state:'') : 'Localidade não informada' }}</span>
        @if($customer->phone)
          <a href="tel:{{ preg_replace('/[^0-9+]/','',$customer->phone) }}">@include('partials.icon',['name'=>'customers','size'=>15]) {{ $customer->phone }}</a>
        @endif
      </div>
    </article>
  @empty
    <div class="lm-empty">Nenhum cliente encontrado.</div>
  @endforelse
</div>
