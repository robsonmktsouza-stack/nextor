@if($catalogModel->catalog_version)
<section class="editor-panel settings-panel">
  <div class="settings-panel-head">
    <span>{{ $catalogModel->catalog_status==='reviewed' ? 'Configuração fiscal revisada' : 'Configuração fiscal pendente de revisão' }}</span>
  </div>
  <details @if($catalogModel->catalog_status!=='reviewed') open @endif>
    <summary>Condições e revisão fiscal</summary>
    
    @foreach(data_get($catalogModel->catalog_metadata,'sources',[]) as $source)
      <p><a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer">{{ $source['reference'] }}</a></p>
    @endforeach
    <div class="editor-grid cols-12 settings-grid">
      <label class="field col-6"><span>Fundamento aprovado para esta instalação</span><input name="fiscal_review_legal_reference" maxlength="2000" value="{{ old('fiscal_review_legal_reference',data_get($catalogModel->catalog_metadata,'review.legal_reference')) }}"></label>
      <label class="field col-6"><span>Fonte oficial</span><input type="url" name="fiscal_review_source" maxlength="2000" value="{{ old('fiscal_review_source',data_get($catalogModel->catalog_metadata,'review.source')) }}" placeholder="https://..."></label>
      <label class="field col-3"><span>Aplicação a partir de</span><input type="date" name="fiscal_review_valid_from" value="{{ old('fiscal_review_valid_from',data_get($catalogModel->catalog_metadata,'review.valid_from')) }}"></label>
      <label class="field col-3"><span>Aplicação até</span><input type="date" name="fiscal_review_valid_until" value="{{ old('fiscal_review_valid_until',data_get($catalogModel->catalog_metadata,'review.valid_until')) }}"></label>
      <label class="field col-12"><span>Condições e exceções verificadas</span><textarea name="fiscal_review_conditions" rows="2" maxlength="5000">{{ old('fiscal_review_conditions',data_get($catalogModel->catalog_metadata,'review.conditions')) }}</textarea></label>
      <label class="settings-switch col-12"><input type="checkbox" name="fiscal_review_confirmed" value="1"><span>Revisei os parâmetros e o enquadramento fiscal para ativar este modelo.</span></label>
    </div>
  </details>
</section>
@endif
@if($catalogModel->exists && $catalogModel->revisions->isNotEmpty())
<details class="editor-panel">
  <summary>Histórico de alterações</summary>
  <div class="table-scroll"><table class="cms-table">
    <thead><tr><th>Revisão</th><th>Data</th><th>Usuário</th><th>Parâmetros</th></tr></thead>
    <tbody>@foreach($catalogModel->revisions as $change)
      <tr><td>{{ $change->revision }}</td><td>{{ $change->created_at?->format('d/m/Y H:i') }}</td><td>{{ $change->user_id ?: 'Catálogo' }}</td>
        <td>Alteração registrada</td></tr>
    @endforeach</tbody>
  </table></div>
</details>
@endif
