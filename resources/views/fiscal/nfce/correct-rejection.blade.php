@extends('layouts.app')
@section('title', 'NFC-e '.$document->series.'/'.$document->document_number)
@section('titleMeta','Documento fiscal')
@section('content')
@include('fiscal._nav',['tab'=>'nfce','tabs'=>[
    'nfe'=>['label'=>'NF-e'],'nfse'=>['label'=>'NFS-e'],
    'cte'=>['label'=>'CT-e'],'mdfe'=>['label'=>'MDF-e'],'nfce'=>['label'=>'NFC-e'],
]])
@php
  // Correção usa o editor de NFC-e, mas SEM recriar venda, estoque ou financeiro.
  // Somente o grupo de dados da rejeição comprovada admite edição.
  $startTab=match($rejection['group']) {
    'items'=>'products','consumer'=>'consumer','payments'=>'payment',default=>'general'
  };
  $total=(float)($snapshot['total'] ?? 0);
@endphp
<div class="nextor-modal-layer nfe-modal-layer is-open" id="nfceEditor" aria-hidden="false">
  <div class="erp-dialog nfe-workspace-dialog nextor-modal-window nfe-fixed-shell"
    role="dialog" aria-modal="true" aria-labelledby="nfceEditorTitle">
    <form method="post" action="{{ route('fiscal.nfce.correct.store',$document) }}"
      id="nfceEditorForm" data-readonly="0" data-correction="1" autocomplete="off" novalidate>
      @csrf
      <div class="dialog-header">
        <div><h2 id="nfceEditorTitle">NFC-e {{ $document->series }}/{{ $document->document_number }}</h2></div>
        <a class="close-dialog" href="{{ route('fiscal.index',['tab'=>'nfce']) }}"
          aria-label="Fechar">@include('partials.icon',['name'=>'x','size'=>18])</a>
      </div>
      <div class="dialog-body nfe-workspace-body">
        <div class="nfe-emitter-summary">
          <div class="nfe-emitter-logo">
            @if($company->logo_path)<img src="{{ route('company.logo') }}" alt="">@endif
          </div>
          <div class="nfe-emitter-identity">
            <div class="nfe-emitter-name">
              <strong>{{ $company->legal_name ?: 'Empresa não configurada' }}</strong>
              @if($company->trade_name)<span>{{ $company->trade_name }}</span>@endif
            </div>
            <div class="nfe-emitter-address">
              {{ collect([$company->address,$company->address_number,$company->district,$company->city,$company->state])->filter()->implode(' · ') }}
            </div>
            <div class="nfe-emitter-docs">
              <span><b>CNPJ:</b> {{ $company->document ?: '—' }}</span>
              <span><b>IE:</b> {{ $company->state_registration ?: '—' }}</span>
              <span><b>CRT:</b> {{ $company->crt ?: '—' }}</span>
            </div>
          </div>
          <div class="nfe-document-summary">
            <span>Número da NFC-e</span>
            <strong>{{ str_pad((string)$document->document_number,9,'0',STR_PAD_LEFT) }}</strong>
            <div>
              <span>Série <b>{{ str_pad((string)$document->series,3,'0',STR_PAD_LEFT) }}</b></span>
              <span>Ambiente <b>{{ $document->environment==='production'?'Produção':'Homologação' }}</b></span>
            </div>
          </div>
        </div>

        <div class="alert alert-danger" role="alert">
          <strong>Rejeitada pela SEFAZ — {{ $rejection['code'] }}:</strong> {{ $rejection['reason'] }}
          <p>Corrija os campos permitidos nesta NFC-e. Ao salvar, será criada uma versão fiscal
            auditada vinculada à mesma venda; a tentativa original, o XML e a resposta da
            SEFAZ ficam preservados. <strong>A nova versão não será enviada automaticamente.</strong></p>
        </div>
        @if($errors->any())
          <div class="alert alert-danger" role="alert">
            <strong>Não foi possível salvar a correção.</strong>
            <ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
          </div>
        @endif

        <div class="editor-tabs nfe-editor-tabs" role="tablist" aria-label="Seções da NFC-e" data-nfce-tabs>
          @foreach(['general'=>'Dados gerais','consumer'=>'Consumidor','products'=>'Produtos',
              'payment'=>'Pagamento','summary'=>'Resumo'] as $key=>$label)
            <button type="button" role="tab" id="nfceTab-{{ $key }}" aria-controls="nfcePanel-{{ $key }}"
              aria-selected="{{ $startTab===$key?'true':'false' }}"
              tabindex="{{ $startTab===$key?'0':'-1' }}"
              class="editor-tab {{ $startTab===$key?'active':'' }}"
              data-nfce-tab="{{ $key }}">{{ $label }}</button>
          @endforeach
        </div>
        <section class="editor-tab-panel {{ $startTab==='general'?'active':'' }}" id="nfcePanel-general"
          role="tabpanel" aria-labelledby="nfceTab-general" data-nfce-panel="general"
          @if($startTab!=='general') hidden @endif>
          <div class="nfe-general-card">
            <div class="nfe-section-title"><h3>Dados gerais</h3></div>
            <div class="editor-grid cols-12">
              <div class="field col-3"><span>Situação</span><strong>Rejeitada</strong></div>
              <div class="field col-3"><span>Emissão</span><strong>{{ $document->environment==='production'?'Produção':'Homologação' }}</strong></div>
              <div class="field col-3"><span>Venda vinculada</span><strong>#{{ $document->sale_id }}</strong></div>
              <div class="field col-3"><span>Chave de acesso</span><strong class="fiscal-key">{{ $document->access_key }}</strong></div>
            </div>
            <p class="nfce-muted">Número, série, quantidade, preço, desconto e operação comercial permanecem inalterados.</p>
          </div>
        </section>
        <section class="editor-tab-panel {{ $startTab==='consumer'?'active':'' }}" id="nfcePanel-consumer"
          role="tabpanel" aria-labelledby="nfceTab-consumer" data-nfce-panel="consumer"
          @if($startTab!=='consumer') hidden @endif>
          @if($rejection['group']==='consumer')
      <div class="editor-panel">
        <h3>Identificação do consumidor</h3>
        <p class="editor-help">Preencha um CPF/CNPJ válido ou mantenha o consumidor não identificado, quando permitido.</p>
        <div class="editor-grid cols-12">
          <label class="field col-4"><span>CPF / CNPJ</span>
            <input name="consumer_document" maxlength="20" inputmode="numeric"
              value="{{ old('consumer_document',$snapshot['consumer_document'] ?? '') }}"></label>
          <label class="field col-8"><span>Nome do consumidor</span>
            <input name="consumer_name" maxlength="190"
              value="{{ old('consumer_name',$snapshot['consumer_name'] ?? '') }}"></label>
        </div>
      </div>
          @else
          <div class="nfe-general-card">
            <div class="nfe-section-title"><h3>Consumidor</h3></div>
            <p>CPF/CNPJ: {{ $snapshot['consumer_document'] ?: 'Não identificado' }}</p>
            <p>Nome: {{ $snapshot['consumer_name'] ?: 'Consumidor não identificado' }}</p>
          </div>
          @endif
        </section>
        <section class="editor-tab-panel {{ $startTab==='products'?'active':'' }}" id="nfcePanel-products"
          role="tabpanel" aria-labelledby="nfceTab-products" data-nfce-panel="products"
          @if($startTab!=='products') hidden @endif>
          @if($rejection['group']==='items')
      <div class="editor-panel">
        <h3>Classificação fiscal dos produtos</h3>
        <p class="editor-help">Somente NCM, GTIN, origem, CFOP e códigos tributários podem ser alterados nesta revisão.
          Os cálculos continuam sujeitos às regras fiscais existentes do Lumeron.</p>
        @foreach(($snapshot['items'] ?? []) as $index => $item)
          @php
            $tax = is_array($item['tax_defaults'] ?? null) ? $item['tax_defaults'] : [];
            $field = 'items.'.$index.'.';
            $simple = in_array((string)$company->crt, ['1','4'], true);
          @endphp
          <div class="editor-panel">
            <h3>Item {{ $index+1 }} — {{ $item['name'] ?? 'Produto' }}</h3>
            <p class="editor-help">Quantidade {{ $item['quantity'] ?? '—' }} · R$ {{ $item['unit_price'] ?? '—' }} por unidade (somente leitura)</p>
            <div class="editor-grid cols-12">
              <label class="field col-3">
                <span>NCM (8 dígitos) *</span>
                <input name="items[{{ $index }}][ncm]"
                  value="{{ old($field.'ncm',$item['ncm'] ?? '') }}" required maxlength="15" inputmode="numeric">
              </label>
              <label class="field col-3">
                <span>CFOP interno *</span>
                <select name="items[{{ $index }}][cfop]" required>
                  @foreach(['5101'=>'5101 — Venda de produção própria',
                    '5102'=>'5102 — Venda de mercadoria adquirida',
                    '5103'=>'5103 — Venda de produção fora do estabelecimento',
                    '5104'=>'5104 — Venda de mercadoria fora do estabelecimento',
                    '5115'=>'5115 — Venda recebida anteriormente em consignação',
                    '5405'=>'5405 — Venda de mercadoria com ICMS-ST retido',
                    '5656'=>'5656 — Venda de combustível ao consumidor',
                    '5667'=>'5667 — Venda de combustível a consumidor'] as $code=>$label)
                    <option value="{{ $code }}"
                      @selected(old($field.'cfop',$tax['cfop_outbound_internal'] ?? '')===$code)>{{ $label }}</option>
                  @endforeach
                </select>
              </label>
              <label class="field col-3">
                <span>Origem *</span>
                <select name="items[{{ $index }}][origin]" required>
                  @foreach(range(0,8) as $code)
                    <option value="{{ $code }}" @selected((string)old($field.'origin',$item['origin'] ?? '0')===(string)$code)>{{ $code }}</option>
                  @endforeach
                </select>
              </label>
              <label class="field col-3">
                <span>GTIN (sem pontuação; opcional)</span>
                <input name="items[{{ $index }}][gtin]" maxlength="14" inputmode="numeric"
                  value="{{ old($field.'gtin',$item['gtin'] ?? '') }}">
              </label>

              @if($simple)
                <label class="field col-4">
                  <span>CSOSN *</span>
                  <select name="items[{{ $index }}][csosn]" required>
                    @foreach(['102'=>'102 — Sem permissão de crédito','103'=>'103 — Isenção por faixa',
                      '300'=>'300 — Imune','400'=>'400 — Não tributada','500'=>'500 — ICMS-ST retido'] as $code=>$label)
                      <option value="{{ $code }}" @selected(old($field.'csosn',$tax['icms_csosn'] ?? '')===$code)>{{ $label }}</option>
                    @endforeach
                  </select>
                </label>
              @else
                <label class="field col-4">
                  <span>CST ICMS *</span>
                  <select name="items[{{ $index }}][icms_cst]" required>
                    @foreach(['00'=>'00 — Tributada integralmente','20'=>'20 — Redução base de cálculo',
                      '40'=>'40 — Isenta','41'=>'41 — Não tributada'] as $code=>$label)
                      <option value="{{ $code }}" @selected(old($field.'icms_cst',$tax['icms_cst'] ?? '')===$code)>{{ $label }}</option>
                    @endforeach
                  </select>
                </label>
              @endif

              <label class="field col-4">
                <span>CST PIS *</span>
                <select name="items[{{ $index }}][pis_cst]" required>
                  @foreach(['01','02','03','04','06','07','08','09','49','99'] as $code)
                    <option value="{{ $code }}" @selected(old($field.'pis_cst',$tax['pis_cst'] ?? '')===$code)>{{ $code }}</option>
                  @endforeach
                </select>
              </label>
              <label class="field col-4">
                <span>CST COFINS *</span>
                <select name="items[{{ $index }}][cofins_cst]" required>
                  @foreach(['01','02','03','04','06','07','08','09','49','99'] as $code)
                    <option value="{{ $code }}" @selected(old($field.'cofins_cst',$tax['cofins_cst'] ?? '')===$code)>{{ $code }}</option>
                  @endforeach
                </select>
              </label>
            </div>
          </div>
        @endforeach
      </div>
          @else
          <div class="nfe-general-card">
            <div class="nfe-section-title"><h3>Produtos</h3></div>
            <div class="nfce-item-table-wrap">
              <table class="nfce-item-table">
                <thead><tr><th>Produto</th><th>Quantidade</th><th>Preço</th><th>Total</th></tr></thead>
                <tbody>
                  @foreach(($snapshot['items'] ?? []) as $item)
                    <tr>
                      <td>{{ $item['name'] ?? 'Produto' }}</td>
                      <td>{{ $item['quantity'] ?? '—' }}</td>
                      <td>R$ {{ number_format((float)($item['unit_price'] ?? 0),2,',','.') }}</td>
                      <td>R$ {{ number_format((float)($item['line_total'] ?? 0),2,',','.') }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          </div>
          @endif
        </section>
        <section class="editor-tab-panel {{ $startTab==='payment'?'active':'' }}" id="nfcePanel-payment"
          role="tabpanel" aria-labelledby="nfceTab-payment" data-nfce-panel="payment"
          @if($startTab!=='payment') hidden @endif>
          @if($rejection['group']==='payments')
      <div class="editor-panel">
        <h3>Informações fiscais de pagamento</h3>
        <p class="editor-help">
          Corrija apenas os meios e valores informados à SEFAZ. A soma deve ser igual
          a R$ {{ number_format((float)($snapshot['total'] ?? 0),2,',','.') }}.
          <strong>Esta correção não muda as parcelas nem os lançamentos financeiros da venda.</strong>
          Se houver divergência comercial, reconcilie a venda antes de transmitir.
        </p>
        @foreach(($snapshot['payments'] ?? []) as $index=>$payment)
          @php
            $field='payments.'.$index.'.';
          @endphp
          <div class="editor-grid cols-12">
            <label class="field col-7"><span>Forma de pagamento {{ $index+1 }}</span>
              <select name="payments[{{ $index }}][payment_method]" required>
                @foreach($paymentMethods as $method)
                  <option value="{{ $method->code }}"
                    @selected(old($field.'payment_method',$payment['payment_method'] ?? '')===$method->code)>
                    {{ $method->name }}</option>
                @endforeach
              </select>
            </label>
            <label class="field col-5"><span>Valor pago R$</span>
              <input type="number" step="0.01" min="0.01" required
                name="payments[{{ $index }}][amount]"
                value="{{ old($field.'amount',$payment['amount'] ?? '') }}">
            </label>
          </div>
        @endforeach
      </div>
          @else
          <div class="nfe-general-card">
            <div class="nfe-section-title"><h3>Pagamento</h3></div>
            @foreach(($snapshot['payments'] ?? []) as $payment)
              <p>{{ $payment['payment_method'] ?? 'Não informado' }} —
                R$ {{ number_format((float)($payment['amount'] ?? 0),2,',','.') }}</p>
            @endforeach
          </div>
          @endif
        </section>
        <section class="editor-tab-panel {{ $startTab==='summary'?'active':'' }}" id="nfcePanel-summary"
          role="tabpanel" aria-labelledby="nfceTab-summary" data-nfce-panel="summary"
          @if($startTab!=='summary') hidden @endif>
          <div class="nfe-general-card">
            <div class="nfe-section-title"><h3>Resumo da NFC-e</h3></div>
            <div class="nfce-summary-grid">
              <div><span>Subtotal</span><strong>R$ {{ number_format((float)($snapshot['subtotal'] ?? $total),2,',','.') }}</strong></div>
              <div><span>Desconto</span><strong>R$ {{ number_format((float)($snapshot['discount_total'] ?? 0),2,',','.') }}</strong></div>
              <div><span>Total original</span><strong>R$ {{ number_format($total,2,',','.') }}</strong></div>
            </div>
            <p class="nfce-muted">Os valores comerciais e financeiros são preservados e não são reprocessados.</p>
          </div>
        </section>
      </div>

      <div class="editor-savebar settings-savebar nfce-savebar">
        <div class="nfce-save-total">
          <span>Total da NFC-e</span>
          <strong>R$ {{ number_format($total,2,',','.') }}</strong>
        </div>
        <div class="nfce-save-buttons">
          <a href="{{ route('fiscal.index',['tab'=>'nfce']) }}" class="btn btn-secondary">Cancelar</a>
          <button type="submit" class="btn btn-success">
            @include('partials.icon',['name'=>'check','size'=>16])
            Salvar nova versão fiscal
          </button>
        </div>
      </div>
    </form>
  </div>
</div>
<script defer src="{{ asset('js/nfce-editor.js') }}?v={{ filemtime(public_path('js/nfce-editor.js')) }}"></script>
@endsection
