@extends('layouts.app')
@section('title','Regras fiscais')
@section('titleMeta','NF-e 55 · NFC-e 65')
@section('actions')
  <a class="btn btn-secondary" href="{{ route('settings.index',['tab'=>'tax']) }}">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Tributação</a>
  <a class="btn btn-secondary" href="{{ route('fiscal.tax-groups.index') }}">@include('partials.icon',['name'=>'layers','size'=>16]) Grupos de tributação</a>
@endsection
@section('content')
<section class="editor-panel settings-panel" style="margin-bottom:14px">
  <form method="post" action="{{ route('fiscal.tax-rules.store') }}" class="settings-editor">
    @csrf
    @include('fiscal.rules._fields',['rule'=>null])
    <div class="editor-savebar settings-savebar"><button class="btn btn-success" type="submit">Cadastrar regra</button></div>
  </form>
</section>
<section class="cms-card">
  <div class="table-scroll"><table class="cms-table">
    <thead><tr><th>Regra</th><th>Critérios</th><th>CFOP</th><th>Tributação</th><th>Prioridade</th><th>Vigência</th><th>Situação</th><th></th></tr></thead>
    <tbody>@forelse($rules as $rule)
      <tr>
        <td><strong>{{ $rule->name }}</strong><small class="table-subtitle">{{ strtoupper($rule->document_type) }}</small></td>
        <td>{{ $rule->origin_uf }} → {{ $rule->destination_uf }} · CRT {{ $rule->crt }}<small class="table-subtitle">@if($rule->product_id)Produto #{{ $rule->product_id }} @endif @if($rule->ncm_prefix)NCM {{ $rule->ncm_prefix }}* @endif {{ $rule->operation_type }} {{ $rule->purpose }} {{ $rule->recipient_profile }}</small></td>
        <td>{{ $rule->cfop }}</td>
        <td>{{ $rule->icms_cst ? 'CST '.$rule->icms_cst : 'CSOSN '.$rule->csosn }}<small class="table-subtitle">PIS {{ $rule->pis_cst }} · COFINS {{ $rule->cofins_cst }}</small></td>
        <td>{{ $rule->priority }}</td>
        <td>{{ $rule->valid_from?->format('d/m/Y') ?? 'Sem início' }}<small class="table-subtitle">até {{ $rule->valid_until?->format('d/m/Y') ?? 'indefinido' }}</small></td>
        <td>{{ $rule->is_active ? 'Ativa' : 'Inativa' }}</td><td><a class="table-link" href="#regra-{{ $rule->id }}">Editar / histórico</a></td>
      </tr>
      <tr id="regra-{{ $rule->id }}"><td colspan="8"><details>
        <summary class="table-link">Editar {{ $rule->name }}</summary>
        <form method="post" action="{{ route('fiscal.tax-rules.update',$rule) }}" class="settings-editor" style="padding-top:12px">
          @csrf @method('PUT')
          <input type="hidden" name="revision" value="{{ $rule->revision }}">
          @include('fiscal.rules._fields',['rule'=>$rule])
          <button type="submit" class="btn btn-success">Salvar alterações</button>
        </form>
        <details><summary>Histórico de alterações</summary>
          @forelse($rule->revisions as $revision)<details><summary>Alteração em {{ $revision->created_at?->format('d/m/Y H:i') }}</summary><p>Registro de alteração preservado.</p></details>@empty<p>Nenhuma alteração anterior registrada.</p>@endforelse
        </details>
      </details></td></tr>
    @empty<tr><td colspan="8" class="empty-cell">Nenhuma regra cadastrada.</td></tr>@endforelse</tbody>
  </table></div>
</section>
@endsection
