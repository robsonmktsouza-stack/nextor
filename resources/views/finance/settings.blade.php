@extends('layouts.app')
@section('title','Configurações financeiras')
@section('actions')
<a class="btn btn-secondary" href="{{ route('finance.dashboard') }}">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Financeiro</a>
@endsection

@section('content')
<nav class="finance-nav">
  <a href="{{ route('finance.dashboard') }}">Visão geral</a>
  <a href="{{ route('finance.entries',['type'=>'receivable']) }}">Contas a receber</a>
  <a href="{{ route('finance.entries',['type'=>'payable']) }}">Contas a pagar</a>
  <a href="{{ route('finance.entries') }}">Todos os lançamentos</a>
  <a class="active" href="{{ route('finance.settings') }}">Configurações</a>
</nav>

<div class="finance-settings-grid">
  <section class="cms-card">
    <div class="card-header"><div><h2>Categorias</h2><p>Classificação de receitas e despesas</p></div></div>

    <form method="post" action="{{ route('finance.categories.store') }}" class="finance-settings-form">
      @csrf
      <label class="field"><span>Nome</span><input name="name" maxlength="120" required placeholder="Ex.: Aluguel"></label>
      <label class="field"><span>Tipo</span>
        <select name="type"><option value="income">Receita</option><option value="expense">Despesa</option></select>
      </label>
      <button class="btn btn-primary" type="submit">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar</button>
    </form>

    <div class="table-scroll">
      <table class="cms-table">
        <thead><tr><th>Categoria</th><th>Tipo</th><th>Status</th><th class="action-cell">Ação</th></tr></thead>
        <tbody>
        @foreach($categories as $category)
          <tr>
            <td><strong class="table-title">{{ $category->name }}</strong></td>
            <td>{{ $category->type==='income'?'Receita':'Despesa' }}</td>
            <td><span class="status {{ $category->is_active?'status-ok':'status-muted' }}">{{ $category->is_active?'Ativa':'Inativa' }}</span></td>
            <td class="action-cell">
              <form method="post" action="{{ route('finance.categories.toggle',$category) }}">@csrf
                <button class="btn-icon" type="submit" data-tooltip="{{ $category->is_active?'Desativar':'Ativar' }}">@include('partials.icon',['name'=>$category->is_active?'x':'check','size'=>16])</button>
              </form>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </section>

  <section class="cms-card">
    <div class="card-header"><div><h2>Caixas e contas</h2><p>Onde recebimentos e pagamentos são movimentados</p></div></div>

    <form method="post" action="{{ route('finance.accounts.store') }}" class="finance-settings-form finance-account-form">
      @csrf
      <label class="field"><span>Nome</span><input name="name" maxlength="120" required placeholder="Ex.: Banco Inter"></label>
      <label class="field"><span>Tipo</span>
        <select name="type">
          <option value="cash">Caixa</option><option value="bank">Banco</option><option value="digital">Conta digital</option><option value="other">Outra</option>
        </select>
      </label>
      <label class="field"><span>Saldo inicial</span><input type="number" data-number-kind="money" name="opening_balance" step="0.01" min="0" value="0.00" required></label>
      <button class="btn btn-primary" type="submit">@include('partials.icon',['name'=>'plus','size'=>15]) Adicionar</button>
    </form>

    <div class="table-scroll">
      <table class="cms-table">
        <thead><tr><th>Conta</th><th>Tipo</th><th>Saldo atual</th><th>Status</th><th class="action-cell">Ação</th></tr></thead>
        <tbody>
        @foreach($accounts as $account)
          <tr>
            <td><strong class="table-title">{{ $account->name }}</strong></td>
            <td>{{ match($account->type){'bank'=>'Banco','digital'=>'Conta digital','other'=>'Outra',default=>'Caixa'} }}</td>
            <td class="{{ $account->current_balance<0?'finance-negative':'price-strong' }}">R$ {{ number_format((float)$account->current_balance,2,',','.') }}</td>
            <td><span class="status {{ $account->is_active?'status-ok':'status-muted' }}">{{ $account->is_active?'Ativa':'Inativa' }}</span></td>
            <td class="action-cell">
              <form method="post" action="{{ route('finance.accounts.toggle',$account) }}">@csrf
                <button class="btn-icon" type="submit" data-tooltip="{{ $account->is_active?'Desativar':'Ativar' }}">@include('partials.icon',['name'=>$account->is_active?'x':'check','size'=>16])</button>
              </form>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </section>
</div>
@endsection
