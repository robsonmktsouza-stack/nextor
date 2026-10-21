@extends('layouts.main')
@section('title','Conta #'.$company->id)
@section('actions')<a class="btn subtle" href="{{ route('clients.index') }}">Voltar aos clientes</a>@endsection
@section('content')
@include('clients.partials.details')
@endsection
