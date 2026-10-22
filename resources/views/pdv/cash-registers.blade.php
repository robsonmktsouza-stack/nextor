@extends('layouts.app')
@section('content')
<div class="container" style="max-width:1020px;margin:0 auto;padding:24px">
  <header style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px">
    <div><h1 style="margin:0;font-size:23px">Caixas físicos</h1><p style="color:#687b8b">Cada terminal aceita um único turno aberto por vez.</p></div>
    <a class="btn btn-secondary" href="{{ route('pdv.index') }}">Voltar ao PDV</a>
  </header>
  @if(session('success'))<p role="status">{{ session('success') }}</p>@endif
  @if($errors->any())<p role="alert" style="color:#b42331">{{ $errors->first() }}</p>@endif
  <div style="display:grid;gap:16px">
    @foreach($registers as $register)
    <section class="card" style="padding:18px;border:1px solid #d5dfe8;background:white;border-radius:5px">
      <form method="post" action="{{ route('pdv.cash.registers.update',$register) }}" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
        @csrf @method('PUT')
        <div style="flex:1;min-width:160px">
          <label for="name{{ $register->id }}">Caixa {{ $register->code }}</label>
          <input class="form-control" id="name{{ $register->id }}" name="name" value="{{ $register->name }}" maxlength="100" required>
        </div>
        <div><label for="status{{ $register->id }}">Situação</label>
          <select name="is_active" id="status{{ $register->id }}" class="form-control">
            <option value="1" @selected($register->is_active)>Ativo</option>
            <option value="0" @selected(!$register->is_active)>Desativado</option>
          </select>
        </div>
        <div><label for="pass{{ $register->id }}">Senha ou PIN do administrador</label>
          <input class="form-control" id="pass{{ $register->id }}" type="password" name="credential" required autocomplete="off">
        </div>
        <button class="btn btn-primary" type="submit">Salvar</button>
      </form>
      <small style="color:#607587">
        @if($register->sessions->isNotEmpty())
          Em operação: {{ $register->sessions->first()->user?->name ?? 'Operador' }}
        @else
          Sem turno aberto
        @endif
      </small>
    </section>
    @endforeach
    <section class="card" style="padding:18px;border:1px solid #d5dfe8;background:white;border-radius:5px">
      <h2 style="font-size:16px">Novo caixa</h2>
      <form method="post" action="{{ route('pdv.cash.registers.store') }}" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">
        @csrf
        <label>Código<input class="form-control" name="code" placeholder="CX02" maxlength="30" required></label>
        <label>Descrição<input class="form-control" name="name" placeholder="Caixa 02" maxlength="100" required></label>
        <label>Senha ou PIN<input class="form-control" type="password" name="credential" required autocomplete="off"></label>
        <button class="btn btn-primary" type="submit">Cadastrar caixa</button>
      </form>
    </section>
  </div>
</div>
@endsection
