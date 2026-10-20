@extends('layouts.main')
@section('title','Conta #'.$company->id)
@section('actions')<a class="btn subtle" href="{{ route('clients.index') }}">Voltar aos clientes</a>@endsection
@section('content')
@if(session('new_secret'))
<div class="secret-once"><strong>Chave privada da instalação — exibida somente agora</strong><p>Copie e armazene em local seguro. Ela não poderá ser consultada novamente no painel; será necessário gerar outra se for perdida.</p>
<div class="secret-value"><code id="licenseSecret">{{ session('new_secret') }}</code><button type="button" class="btn primary small" data-copy-target="licenseSecret">Copiar</button></div>
</div>
@endif
@include('clients.partials.details')
@endsection
