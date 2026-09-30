<!DOCTYPE html><html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar — {{ config('app.name') }}</title><link rel="stylesheet" href="{{ asset('css/erp.css') }}"></head>
<body class="login-body"><main class="login-card">
 <div class="login-mark">@include('partials.icon',['name'=>'shield','size'=>28])</div>
 <h1>ERP</h1><p class="login-subtitle">Gerenciamento de estoque e vendas</p>
 <div class="login-head"><h2>Acesse seu painel</h2><p>Informe suas credenciais para continuar.</p></div>
 @if($errors->any())<div class="alert danger">{{ $errors->first() }}</div>@endif
 <form method="POST" action="{{ route('login') }}" class="form-stack">@csrf
 <label class="field">E-mail<input name="email" value="{{ old('email') }}" type="email" autocomplete="username" required autofocus placeholder="seu@email.com"></label>
 <label class="field">Senha<input name="password" type="password" autocomplete="current-password" required placeholder="Sua senha"></label>
 <label class="checkline"><input type="checkbox" name="remember" value="1"> Manter conectado</label>
 <button class="btn btn-primary btn-block">Entrar no sistema</button>
 </form><div class="login-foot">Acesso restrito</div>
 </main></body></html>
