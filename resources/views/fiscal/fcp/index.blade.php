@extends('layouts.app')
@section('title','Tabela FCP')
@section('titleMeta','Fundo de Combate à Pobreza · UF / NCM')
@section('actions')
  <a href="{{ route('fiscal.tax-groups.index') }}" class="btn btn-secondary">@include('partials.icon',['name'=>'arrow-left','size'=>16]) Grupos tributários</a>
@endsection
@section('content')
@php
$ufOptions=['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'];
@endphp
<section class="editor-panel settings-panel" style="margin-bottom:14px">
  <div class="settings-panel-head"><div><h2>Regra FCP por estado e NCM</h2><p>Cadastre apenas alíquotas revisadas. Uma UF pode ter várias regras por prefixo NCM, com vigências distintas.</p></div></div>
  <div class="inline-note">O cadastro não ativa o cálculo de FCP. No emissor NFC-e inicial, um FCP ativo aplicável bloqueia a emissão por regras fiscais até que exista cálculo validado para o caso.</div>
  <form method="post" action="{{ route('fiscal.fcp.store') }}" class="settings-editor">
    @csrf
    <div class="editor-grid cols-12 settings-grid" style="margin-top:12px">
      <label class="field col-2"><span>UF</span><select name="uf" required><option value="">Selecione</option>
        @foreach($ufOptions as $uf)<option value="{{ $uf }}" @selected(old('uf')===$uf)>{{ $uf }}</option>@endforeach
      </select></label>
      <label class="field col-2"><span>Prefixo NCM</span><input name="ncm_prefix" maxlength="8" inputmode="numeric" value="{{ old('ncm_prefix') }}" placeholder="Opcional"></label>
      <label class="field col-2"><span>Alíquota FCP (%)</span><input type="number" name="rate" step="0.0001" min="0" max="100" required value="{{ old('rate') }}" placeholder="0,0000"></label>
      <label class="field col-3"><span>Início vigência</span><input type="date" name="valid_from" value="{{ old('valid_from') }}"></label>
      <label class="field col-3"><span>Fim vigência</span><input type="date" name="valid_until" value="{{ old('valid_until') }}"></label>
      <div class="field col-4"><span>FCP próprio</span><label class="settings-switch"><input name="apply_to_own_fcp" value="1" type="checkbox" @checked(old('apply_to_own_fcp'))><span><strong>Usar também no FCP próprio quando disponível</strong></span></label></div>
      <div class="field col-3"><span>Aplicação</span><label class="settings-switch"><input name="is_active" value="1" type="checkbox" @checked(old('is_active'))><span><strong>Regra ativa</strong></span></label></div>
      <label class="field col-5"><span>Referência legal / observação</span><input name="notes" maxlength="2000" value="{{ old('notes') }}"></label>
    </div>
    <div class="editor-savebar settings-savebar"><button class="btn btn-success" type="submit">@include('partials.icon',['name'=>'plus','size'=>16]) Adicionar regra FCP</button></div>
  </form>
</section>

<section class="cms-card">
  <div class="card-header"><div><h2>Regras FCP cadastradas</h2><p>{{ $rules->count() }} regra(s). Estados sem alíquota configurada não recebem uma alíquota presumida.</p></div></div>
  <div class="table-scroll">
    <table class="cms-table">
      <thead><tr><th>UF</th><th>NCM</th><th>Alíquota</th><th>FCP próprio</th><th>Vigência</th><th>Situação</th><th></th></tr></thead>
      <tbody>
      @forelse($rules as $item)
        <tr>
          <td><strong>{{ $item->uf }}</strong></td>
          <td>{{ $item->ncm_prefix ? $item->ncm_prefix.'*' : 'Todos (requer revisão)' }}</td>
          <td>{{ number_format((float)$item->rate,4,',','.') }}%</td>
          <td>{{ $item->apply_to_own_fcp ? 'Sim' : 'Não' }}</td>
          <td>{{ $item->valid_from?->format('d/m/Y') ?? '—' }} → {{ $item->valid_until?->format('d/m/Y') ?? '—' }}</td>
          <td><span class="status {{ $item->is_active ? 'status-ok' : 'status-muted' }}">{{ $item->is_active ? 'Ativa' : 'Inativa' }}</span></td>
          <td><a class="table-link" href="#fcp-{{ $item->id }}">Editar</a></td>
        </tr>
        <tr id="fcp-{{ $item->id }}"><td colspan="7">
          <details><summary class="table-link" style="cursor:pointer">Editar FCP {{ $item->uf }} — #{{ $item->id }}</summary>
            <form method="post" action="{{ route('fiscal.fcp.update',$item) }}" class="settings-editor" style="padding:12px 0">
              @csrf @method('PUT')
              <div class="editor-grid cols-12 settings-grid">
                <label class="field col-2"><span>UF</span><select name="uf" required>
                  @foreach($ufOptions as $uf)<option value="{{ $uf }}" @selected($item->uf===$uf)>{{ $uf }}</option>@endforeach
                </select></label>
                <label class="field col-2"><span>Prefixo NCM</span><input name="ncm_prefix" maxlength="8" value="{{ $item->ncm_prefix }}"></label>
                <label class="field col-2"><span>Alíquota (%)</span><input name="rate" type="number" step="0.0001" min="0" max="100" required value="{{ $item->rate }}"></label>
                <label class="field col-3"><span>Início vigência</span><input type="date" name="valid_from" value="{{ $item->valid_from?->format('Y-m-d') }}"></label>
                <label class="field col-3"><span>Fim vigência</span><input type="date" name="valid_until" value="{{ $item->valid_until?->format('Y-m-d') }}"></label>
                <div class="field col-4"><span>FCP próprio</span><label class="settings-switch"><input name="apply_to_own_fcp" type="checkbox" value="1" @checked($item->apply_to_own_fcp)><span><strong>Usar quando disponível</strong></span></label></div>
                <div class="field col-3"><span>Situação</span><label class="settings-switch"><input name="is_active" type="checkbox" value="1" @checked($item->is_active)><span><strong>Ativa</strong></span></label></div>
                <label class="field col-5"><span>Observação</span><input name="notes" value="{{ $item->notes }}" maxlength="2000"></label>
              </div>
              <button type="submit" class="btn btn-success">Salvar regra FCP</button>
            </form>
          </details>
        </td></tr>
      @empty
        <tr><td colspan="7" class="empty-cell">Nenhuma regra FCP cadastrada.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection