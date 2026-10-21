@extends('layouts.main')
@section('title', $licenses ? 'Licenças' : 'Clientes')
@section('content')
@include('licenses.table')
@endsection
