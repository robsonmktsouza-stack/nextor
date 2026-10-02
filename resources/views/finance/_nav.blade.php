<nav class="finance-module-tabs">
  <a class="finance-tab-overview {{ request()->routeIs('finance.dashboard')?'active':'' }}" href="{{ route('finance.dashboard') }}">Visão geral</a>
  <a class="finance-tab-receivable {{ request()->routeIs('finance.entries*') && request('type')==='receivable'?'active':'' }}" href="{{ route('finance.entries',['type'=>'receivable']) }}">Recebimentos</a>
  <a class="finance-tab-payable {{ request()->routeIs('finance.entries*') && request('type')==='payable'?'active':'' }}" href="{{ route('finance.entries',['type'=>'payable']) }}">Pagamentos</a>
  <a class="finance-tab-transfer {{ request()->routeIs('finance.transfers.*')?'active':'' }}" href="{{ route('finance.transfers.index') }}">Transferências</a>
  <a class="finance-tab-recurrence {{ request()->routeIs('finance.recurrences.*')?'active':'' }}" href="{{ route('finance.recurrences.index') }}">Recorrência</a>
  <a class="finance-tab-receipt {{ request()->routeIs('finance.receipts.*')?'active':'' }}" href="{{ route('finance.receipts.index') }}">Recibos</a>
  <a class="finance-tab-reconciliation {{ request()->routeIs('finance.reconciliation.*')?'active':'' }}" href="{{ route('finance.reconciliation.index') }}">Conciliação</a>
  @if(auth()->user()->canAccess('settings'))
  <a class="finance-settings-tab finance-tab-settings {{ request()->routeIs('settings.*')?'active':'' }}" href="{{ route('settings.index',['tab'=>'chart']) }}" title="Configurações">@include('partials.icon',['name'=>'settings','size'=>16])</a>
  @endif
</nav>
