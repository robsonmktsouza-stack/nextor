@extends('layouts.app')
@section('title','Grupos de tributação')
@section('titleMeta','Configuração fiscal')
@section('actions')
  <a class="btn btn-secondary" href="{{ route('settings.index',['tab'=>'tax']) }}">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Tributação</a>
  <a class="btn btn-secondary" href="{{ route('fiscal.tax-rules.index') }}">@include('partials.icon',['name'=>'layers','size'=>16]) Regras por NCM / produto</a>
  <a class="btn btn-secondary" href="{{ route('fiscal.fcp.index') }}">@include('partials.icon',['name'=>'layers','size'=>16]) Tabela FCP</a>
  <a class="btn btn-success" href="{{ route('fiscal.tax-groups.create') }}">@include('partials.icon',['name'=>'plus','size'=>16]) Novo grupo</a>
@endsection

@section('content')
<section class="cms-card">
  <div class="card-header">
    <div>
      <h2>Grupos fiscais</h2>
      <p>Organize os grupos de tributação dos seus produtos e serviços.</p>
    </div>
  </div>
  <div class="grid-actionbar" style="margin-bottom:12px">
    <div class="grid-actions-left">
      <a class="grid-primary-action" href="{{ route('fiscal.tax-groups.create',['kind'=>'products']) }}">@include('partials.icon',['name'=>'plus','size'=>16]) <span>Produtos</span></a>
      <a class="grid-tool grid-tool-wide" href="{{ route('fiscal.tax-groups.create',['kind'=>'services']) }}">@include('partials.icon',['name'=>'plus','size'=>16]) <span>Serviços</span></a>
    </div>
  </div>
  <div class="table-scroll">
    <table class="cms-table">
      <thead><tr>
        <th>Cód.</th><th>Descrição</th><th>Tipo</th><th>CFOP</th><th>ICMS / NFC-e</th><th>PIS / COFINS</th><th>Vínculos</th><th>Situação</th><th class="action-cell">Editar</th>
      </tr></thead>
      <tbody>
      @forelse($groups as $group)
        <tr>
          <td class="nowrap">{{ $group->id }}</td>
          <td><a class="table-link" href="{{ route('fiscal.tax-groups.edit',$group) }}">{{ $group->name }}</a>
            <small class="table-subtitle">Revisão {{ $group->revision }} @if($group->preset_key) @endif @if($group->is_default) · Padrão para {{ $group->kind==='products' ? 'produtos' : 'serviços' }} @endif</small>
          </td>
          <td><span class="code-tag">{{ $group->kind==='products' ? 'Produtos' : 'Serviços' }}</span></td>
          <td>{{ $group->cfop_pattern ?: '—' }}</td>
          <td>{{ $group->icms_csosn ?: ($group->icms_cst ?: '—') }} @if($group->nfce_csosn)<small class="table-subtitle">NFC-e: {{ $group->nfce_csosn }}</small>@endif</td>
          <td>{{ $group->pis_cst ?: '—' }} / {{ $group->cofins_cst ?: '—' }}</td>
          <td>{{ $group->kind==='products' ? $group->products_count : $group->services_count }}</td>
          <td><span class="status {{ $group->is_active ? 'status-ok' : 'status-muted' }}">{{ $group->is_active ? 'Ativo' : 'Inativo' }}</span></td>
          <td class="action-cell"><a class="btn-icon" href="{{ route('fiscal.tax-groups.edit',$group) }}" data-tooltip="Editar grupo">@include('partials.icon',['name'=>'edit','size'=>16])</a></td>
        </tr>
      @empty
        <tr><td colspan="9" class="empty-cell">Nenhum grupo tributário cadastrado. Crie um grupo de produtos ou de serviços.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
