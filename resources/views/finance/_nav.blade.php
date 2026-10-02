<nav class="finance-module-tabs">
  <a class="{{ request()->routeIs('finance.dashboard')?'active':'' }}" href="{{ route('finance.dashboard') }}">Visão geral</a>
  <a class="{{ request()->routeIs('finance.entries*') && request('type')==='receivable'?'active':'' }}" href="{{ route('finance.entries',['type'=>'receivable']) }}">Recebimentos</a>
  <a class="{{ request()->routeIs('finance.entries*') && request('type')==='payable'?'active':'' }}" href="{{ route('finance.entries',['type'=>'payable']) }}">Pagamentos</a>
  <a class="{{ request()->routeIs('finance.transfers.*')?'active':'' }}" href="{{ route('finance.transfers.index') }}">Transferências</a>
  <a class="{{ request()->routeIs('finance.recurrences.*')?'active':'' }}" href="{{ route('finance.recurrences.index') }}">Recorrência</a>
  <a class="{{ request()->routeIs('finance.receipts.*')?'active':'' }}" href="{{ route('finance.receipts.index') }}">Recibos</a>
  <a class="{{ request()->routeIs('finance.reconciliation.*')?'active':'' }}" href="{{ route('finance.reconciliation.index') }}">Conciliação</a>
  <a class="finance-settings-tab {{ request()->routeIs('finance.settings')?'active':'' }}" href="{{ route('finance.settings') }}" title="Configurações">@include('partials.icon',['name'=>'settings','size'=>16])</a>
</nav>
