@extends('layouts.app')
@section('title','Regras fiscais')
@section('titleMeta','NFC-e · Simples Nacional')
@section('actions')
  <a class="btn btn-secondary" href="{{ route('settings.index',['tab'=>'tax']) }}">
    @include('partials.icon',['name'=>'arrow-left','size'=>16]) Tributação
  </a>
  <a class="btn btn-secondary" href="{{ route('fiscal.index',['tab'=>'nfce']) }}">
    @include('partials.icon',['name'=>'receipt','size'=>16]) NFC-e
  </a>
@endsection

@section('content')
<section class="editor-panel settings-panel" style="margin-bottom:14px">
  <div class="settings-panel-head">
    <div>
      <h2>Regras fiscais</h2>
      <p>Regras por produto ou NCM, regime, UF, prioridade e período. O cadastro não determina a tributação correta: os enquadramentos devem ser validados pela contabilidade.</p>
    </div>
  </div>
  <div class="inline-note">
    <strong>Primeira etapa:</strong> NFC-e de saída interna na Bahia, CRT 1 (Simples Nacional).
    O emissor atualmente aceita CFOP 5xxx, CSOSN 102 e CST 49 para PIS e COFINS.
    Os demais grupos fiscais ainda exigem desenvolvimento. Regras não são aplicadas retroativamente.
  </div>
  <form method="post" action="{{ route('fiscal.tax-rules.mode') }}" class="editor-grid cols-12 settings-grid" style="align-items:end;margin-top:12px">
    @csrf
    <label class="field col-8"><span>Aplicação de regras ao emitir</span>
      <select name="enabled">
        <option value="0" @selected(!$useFiscalRules)>Desativada — manter comportamento atual</option>
        <option value="1" @selected($useFiscalRules)>Ativada — exigir regra fiscal aplicada em NFC-e preparada</option>
      </select>
    </label>
    <div class="col-4">
      <button type="submit" class="btn btn-success">@include('partials.icon',['name'=>'check','size'=>15]) Salvar configuração</button>
    </div>
  </form>
  <p style="font-size:12px;color:var(--text-secondary,#667085);margin-top:9px">
    Com as regras ativadas, documentos sem uma regra aplicada serão bloqueados na pré-validação.
    Para cada NFC-e preparada, abra o documento e clique em <strong>Aplicar regras fiscais</strong>.
    Venda, estoque, pagamento e numeração permanecem intactos.
  </p>
</section>

<section class="editor-panel settings-panel" style="margin-bottom:14px">
  <div class="settings-panel-head"><div><h2>Nova regra</h2><p>Defina um critério específico e um enquadramento conferido. Deixe a regra inativa até revisar.</p></div></div>
  <form method="post" action="{{ route('fiscal.tax-rules.store') }}" class="settings-editor">
    @csrf
    <input type="hidden" name="document_type" value="nfce">
    <input type="hidden" name="origin_uf" value="BA">
    <input type="hidden" name="destination_uf" value="BA">
    <input type="hidden" name="crt" value="1">
    <div class="editor-grid cols-12 settings-grid">
      <label class="field col-6"><span>Nome</span><input required maxlength="160" name="name" value="{{ old('name') }}" placeholder="Ex.: Perfil revisado — mercadorias do NCM ..."></label>
      <label class="field col-3"><span>Produto (opcional)</span>
        <select name="product_id">
          <option value="">Todos os produtos</option>
          @foreach($products as $product)
            <option value="{{ $product->id }}" @selected((string)old('product_id')===(string)$product->id)>{{ $product->sku }} — {{ $product->name }}</option>
          @endforeach
        </select>
      </label>
      <label class="field col-3"><span>Prefixo NCM (opcional)</span><input name="ncm_prefix" inputmode="numeric" maxlength="8" placeholder="2 a 8 dígitos" value="{{ old('ncm_prefix') }}"></label>
      <label class="field col-2"><span>CFOP</span><input required name="cfop" maxlength="4" inputmode="numeric" placeholder="5xxx" value="{{ old('cfop') }}"></label>
      <label class="field col-2"><span>CSOSN</span><input required name="csosn" maxlength="3" inputmode="numeric" placeholder="102" value="{{ old('csosn') }}"></label>
      <label class="field col-2"><span>CST PIS</span><input required name="pis_cst" maxlength="2" inputmode="numeric" placeholder="49" value="{{ old('pis_cst') }}"></label>
      <label class="field col-2"><span>CST COFINS</span><input required name="cofins_cst" maxlength="2" inputmode="numeric" placeholder="49" value="{{ old('cofins_cst') }}"></label>
      <label class="field col-2"><span>Prioridade</span><input required name="priority" type="number" min="-100" max="100" value="{{ old('priority',0) }}"></label>
      <label class="field col-3"><span>Início da vigência</span><input type="date" name="valid_from" value="{{ old('valid_from') }}"></label>
      <label class="field col-3"><span>Fim da vigência</span><input type="date" name="valid_until" value="{{ old('valid_until') }}"></label>
      <div class="field col-6"><span>Conferência</span>
        <label class="settings-switch"><input type="checkbox" name="is_active" value="1" @checked(old('is_active'))>
          <span><strong>Regra ativa</strong><small>Ative somente após validar o enquadramento.</small></span>
        </label>
      </div>
      <label class="field col-12"><span>Observações / fundamento para conferência</span>
        <textarea name="notes" rows="2" placeholder="Anotações internas; a regra não é parecer fiscal">{{ old('notes') }}</textarea>
      </label>
    </div>
    <div class="editor-savebar settings-savebar"><button class="btn btn-success" type="submit">
      @include('partials.icon',['name'=>'check','size'=>16]) Cadastrar regra
    </button></div>
  </form>
</section>

<section class="cms-card">
  <div class="card-header"><div><h2>Regras cadastradas</h2><p>{{ $rules->count() }} registro(s)</p></div></div>
  <div class="table-scroll">
    <table class="cms-table">
      <thead>
        <tr><th>Regra</th><th>Critérios</th><th>CFOP</th><th>Tributação</th><th>Prioridade</th><th>Vigência</th><th>Situação</th><th></th></tr>
      </thead>
      <tbody>
      @forelse($rules as $rule)
        <tr>
          <td><strong>{{ $rule->name }}</strong><small class="table-subtitle">Revisão {{ $rule->revision }} · #{{ $rule->id }}</small></td>
          <td>
            BA → BA · CRT 1
            <small class="table-subtitle">
              @if($rule->product_id) Produto #{{ $rule->product_id }} @endif
              @if($rule->ncm_prefix) NCM {{ $rule->ncm_prefix }}* @endif
              @if(!$rule->product_id && !$rule->ncm_prefix) Todos os produtos (genérica) @endif
            </small>
          </td>
          <td><span class="code-tag">{{ $rule->cfop }}</span></td>
          <td>CSOSN {{ $rule->csosn }}<small class="table-subtitle">PIS {{ $rule->pis_cst }} · COFINS {{ $rule->cofins_cst }}</small></td>
          <td>{{ $rule->priority }}</td>
          <td>{{ $rule->valid_from?->format('d/m/Y') ?? 'Sem início' }}<small class="table-subtitle">até {{ $rule->valid_until?->format('d/m/Y') ?? 'indefinido' }}</small></td>
          <td><span class="status {{ $rule->is_active ? 'status-ok' : 'status-muted' }}">{{ $rule->is_active ? 'Ativa' : 'Inativa' }}</span></td>
          <td><a href="#regra-{{ $rule->id }}" class="table-link">Editar</a></td>
        </tr>
        <tr id="regra-{{ $rule->id }}">
          <td colspan="8">
            <details>
              <summary class="table-link" style="cursor:pointer">Editar {{ $rule->name }}</summary>
              <form method="post" action="{{ route('fiscal.tax-rules.update',$rule) }}" class="settings-editor" style="padding-top:12px">
                @csrf
                @method('PUT')
                <input type="hidden" name="document_type" value="nfce">
                <input type="hidden" name="origin_uf" value="BA">
                <input type="hidden" name="destination_uf" value="BA">
                <input type="hidden" name="crt" value="1">
                <div class="editor-grid cols-12 settings-grid">
                  <label class="field col-6"><span>Nome</span><input name="name" required maxlength="160" value="{{ $rule->name }}"></label>
                  <label class="field col-3"><span>Produto</span><select name="product_id">
                    <option value="">Todos os produtos</option>
                    @foreach($products as $product)<option value="{{ $product->id }}" @selected((int)$rule->product_id===(int)$product->id)>{{ $product->sku }} — {{ $product->name }}</option>@endforeach
                  </select></label>
                  <label class="field col-3"><span>Prefixo NCM</span><input name="ncm_prefix" maxlength="8" value="{{ $rule->ncm_prefix }}"></label>
                  <label class="field col-2"><span>CFOP</span><input name="cfop" required maxlength="4" value="{{ $rule->cfop }}"></label>
                  <label class="field col-2"><span>CSOSN</span><input name="csosn" required maxlength="3" value="{{ $rule->csosn }}"></label>
                  <label class="field col-2"><span>CST PIS</span><input name="pis_cst" required maxlength="2" value="{{ $rule->pis_cst }}"></label>
                  <label class="field col-2"><span>CST COFINS</span><input name="cofins_cst" required maxlength="2" value="{{ $rule->cofins_cst }}"></label>
                  <label class="field col-2"><span>Prioridade</span><input name="priority" type="number" required min="-100" max="100" value="{{ $rule->priority }}"></label>
                  <label class="field col-3"><span>Início</span><input type="date" name="valid_from" value="{{ $rule->valid_from?->format('Y-m-d') }}"></label>
                  <label class="field col-3"><span>Fim</span><input type="date" name="valid_until" value="{{ $rule->valid_until?->format('Y-m-d') }}"></label>
                  <div class="field col-6"><span>Situação</span>
                    <label class="settings-switch"><input type="checkbox" name="is_active" value="1" @checked($rule->is_active)>
                      <span><strong>Regra ativa</strong></span>
                    </label>
                  </div>
                  <label class="field col-12"><span>Observações</span><textarea name="notes" rows="2">{{ $rule->notes }}</textarea></label>
                </div>
                <button type="submit" class="btn btn-success">Salvar alterações</button>
              </form>
            </details>
          </td>
        </tr>
      @empty
        <tr><td colspan="8" class="empty-cell">Nenhuma regra cadastrada. Comece criando e validando um perfil tributário.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
