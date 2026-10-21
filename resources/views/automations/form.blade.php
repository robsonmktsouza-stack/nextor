@extends('layouts.main')
@section('title', $rule->exists ? 'Editar automação' : 'Nova automação')
@section('actions')
<a href="{{ route('automations.index') }}" class="btn subtle">Voltar às automações</a>
@endsection
@section('content')
<section class="panel control-settings-panel">
    <div class="panel-heading"><h2>Dados da automação</h2></div>
    <form method="post" action="{{ $rule->exists ? route('automations.update', $rule) : route('automations.store') }}">
        @csrf
        @if($rule->exists) @method('PUT') @endif
        <div class="control-settings-inner">
            @include('automations.partials.fields', ['rule' => $rule])
            <div class="control-form-actions">
                <a href="{{ route('automations.index') }}" class="btn subtle">Cancelar</a>
                <button type="submit" class="btn primary">@include('partials.icon',['name'=>'check','size'=>16])Salvar automação</button>
            </div>
        </div>
    </form>
</section>
@endsection
