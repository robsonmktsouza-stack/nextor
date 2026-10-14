{{-- Editor de NFC-e rejeitada: revisão dos campos fiscais sem alterar a venda original. --}}
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
              <label class="field col-6"><span>Natureza da operação</span>
                <input name="nature" maxlength="60" required
                  value="{{ old('nature',$snapshot['nature'] ?? 'VENDA DE MERCADORIA') }}">
              </label>
              <label class="field col-12"><span>Informações adicionais</span>
                <textarea name="notes" rows="3" maxlength="2000">{{ old('notes',$snapshot['notes'] ?? '') }}</textarea>
              </label>
            </div>
          </div>
        </section>
        <section class="editor-tab-panel {{ $startTab==='consumer'?'active':'' }}" id="nfcePanel-consumer"
          role="tabpanel" aria-labelledby="nfceTab-consumer" data-nfce-panel="consumer"
          @if($startTab!=='consumer') hidden @endif>
          
      <div class="editor-panel">
        <h3>Consumidor</h3>
        <div class="editor-grid cols-12">
          <label class="field col-4"><span>CPF / CNPJ</span>
            <input name="consumer_document" maxlength="20" inputmode="numeric"
              value="{{ old('consumer_document',$snapshot['consumer_document'] ?? '') }}"></label>
          <label class="field col-8"><span>Nome do consumidor</span>
            <input name="consumer_name" maxlength="190"
              value="{{ old('consumer_name',$snapshot['consumer_name'] ?? '') }}"></label>
        </div>
      </div>        </section>
        <section class="editor-tab-panel {{ $startTab==='products'?'active':'' }}" id="nfcePanel-products"
          role="tabpanel" aria-labelledby="nfceTab-products" data-nfce-panel="products"
          @if($startTab!=='products') hidden @endif>
          
      <div class="cms-card nfce-correction-product-list">
        <div class="grid-actionbar">
          <div class="grid-actions-left"><strong>Produtos da NFC-e</strong></div>
          <span class="nfce-list-count">{{ count($snapshot['items'] ?? []) }} {{ count($snapshot['items'] ?? [])===1?'produto':'produtos' }}</span>
        </div>
        <div class="table-scroll nfce-item-table-wrap">
          <table class="cms-table nfce-item-table">
            <thead>
              <tr><th>Produto</th><th>CFOP</th><th>NCM</th><th>Qtd.</th><th>Unitário</th><th>Total</th><th class="action-cell">Editar</th></tr>
            </thead>
            <tbody>
            @foreach(($snapshot['items'] ?? []) as $index => $item)
              @php
                $rowTax=is_array($item['tax_defaults'] ?? null)?$item['tax_defaults']:[];
              @endphp
              <tr data-nfce-item-row="{{ $index }}">
                <td><strong>{{ $item['name'] ?? 'Produto' }}</strong>
                  @if($item['sku'] ?? null)<small>{{ $item['sku'] }}</small>@endif
                </td>
                <td><span data-nfce-item-cell="{{ $index }}-cfop">{{ $rowTax['cfop_outbound_internal'] ?? $rowTax['nfce_cfop'] ?? $rowTax['cfop'] ?? '—' }}</span></td>
                <td><span data-nfce-item-cell="{{ $index }}-ncm">{{ $item['ncm'] ?? '—' }}</span></td>
                <td>{{ number_format((float)($item['quantity'] ?? 0),3,',','.') }}</td>
                <td>R$ {{ number_format((float)($item['unit_price'] ?? 0),2,',','.') }}</td>
                <td>R$ {{ number_format((float)($item['line_total'] ?? 0),2,',','.') }}</td>
                <td class="action-cell">
                  <button type="button" class="btn btn-secondary nfce-item-edit-button"
                    data-nfce-item-select="{{ $index }}" aria-controls="nfceItemEdit{{ $index }}" aria-expanded="false">
                    Editar
                  </button>
                </td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
      </div>
      <div class="nfce-correction-item-details">
        @foreach(($snapshot['items'] ?? []) as $index => $item)
          @php
            $tax = is_array($item['tax_defaults'] ?? null) ? $item['tax_defaults'] : [];
            $field = 'items.'.$index.'.';
            $simple = in_array((string)$company->crt, ['1','4'], true);
          @endphp
          <div class="editor-panel nfce-correction-item-editor" id="nfceItemEdit{{ $index }}"
            data-nfce-item-editor="{{ $index }}" hidden>
            <div class="nfce-item-editor-heading">
              <h3>Editar: {{ $item['name'] ?? 'Produto' }}</h3>
              <button type="button" class="btn btn-secondary" data-nfce-item-close aria-label="Fechar edição do produto">Fechar</button>
            </div>
            <p class="editor-help">Quantidade {{ $item['quantity'] ?? '—' }} · R$ {{ $item['unit_price'] ?? '—' }} por unidade</p>
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
        </section>
        <section class="editor-tab-panel {{ $startTab==='payment'?'active':'' }}" id="nfcePanel-payment"
          role="tabpanel" aria-labelledby="nfceTab-payment" data-nfce-panel="payment"
          @if($startTab!=='payment') hidden @endif>
          
      <div class="editor-panel">
        <h3>Pagamento</h3>
        <p class="editor-help">Total dos pagamentos: R$ {{ number_format((float)($snapshot['total'] ?? 0),2,',','.') }}.</p>
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
      </div>        </section>
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
          </div>
        </section>
