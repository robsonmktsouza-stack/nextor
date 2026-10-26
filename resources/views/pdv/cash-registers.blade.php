@extends('layouts.app')
@section('title','Caixas físicos')
@section('actions')
<a class="btn btn-secondary" href="{{ route('pdv.index') }}">Voltar ao PDV</a>
@endsection
@push('styles')
<link rel="stylesheet" href="{{ asset('css/pdv-cash-admin.css') }}?v={{ filemtime(public_path('css/pdv-cash-admin.css')) }}">
@endpush
@section('content')
<div class="pdv-register-list">
  @foreach($registers as $register)
  <section class="cms-card pdv-register-card">
    <form method="post" action="{{ route('pdv.cash.registers.update',$register) }}" class="pdv-register-form">
      @csrf @method('PUT')
      <label class="field" for="name{{ $register->id }}"><span>Caixa {{ $register->code }}</span>
        <input id="name{{ $register->id }}" name="name" value="{{ $register->name }}" maxlength="100" required>
      </label>
      <label class="field" for="status{{ $register->id }}"><span>Situação</span>
        <select name="is_active" id="status{{ $register->id }}">
          <option value="1" @selected($register->is_active)>Ativo</option>
          <option value="0" @selected(!$register->is_active)>Desativado</option>
        </select>
      </label>
      <label class="field" for="pass{{ $register->id }}"><span>Senha ou PIN do administrador</span>
        <input id="pass{{ $register->id }}" type="password" name="credential" required autocomplete="off">
      </label>
      <button class="btn btn-primary" type="submit">Salvar</button>
    </form>
    <p class="pdv-register-status">
      @if($register->sessions->isNotEmpty())
        Em operação: {{ $register->sessions->first()->user?->name ?? 'Operador' }}
      @else
        Sem turno aberto
      @endif
    </p>
  </section>
  @endforeach
  <section class="cms-card pdv-register-card">
    <h2>Novo caixa</h2>
    <form method="post" action="{{ route('pdv.cash.registers.store') }}" class="pdv-register-form">
      @csrf
      <label class="field"><span>Código</span><input name="code" placeholder="CX02" maxlength="30" required></label>
      <label class="field"><span>Descrição</span><input name="name" placeholder="Caixa 02" maxlength="100" required></label>
      <label class="field"><span>Senha ou PIN</span><input type="password" name="credential" required autocomplete="off"></label>
      <button class="btn btn-primary" type="submit">Cadastrar caixa</button>
    </form>
  </section>
</div>
@endsection
