@extends('layouts.app')
@section('title', 'Corrigir rejeição — NFC-e '.$document->series.'/'.$document->document_number)
@section('titleMeta', 'Correção fiscal')
@section('content')
@include('fiscal._nav', ['tab'=>'nfce','tabs'=>[
    'nfe'=>['label'=>'NF-e'],'nfse'=>['label'=>'NFS-e'],
    'cte'=>['label'=>'CT-e'],'mdfe'=>['label'=>'MDF-e'],'nfce'=>['label'=>'NFC-e'],
]])

<section class="cms-card" style="max-width:1100px;margin:0 auto">
  <div class="card-header">
    <div>
      <h2>Corrigir rejeição da NFC-e {{ $document->series }}/{{ $document->document_number }}</h2>
      <p>SEFAZ {{ $rejection['code'] }} — {{ $rejection['reason'] }}</p>
    </div>
    <a class="btn btn-secondary" href="{{ route('fiscal.show', $document) }}">Voltar</a>
  </div>

  <div class="editor-panel">
    <p class="editor-help">
      A tentativa rejeitada, o XML assinado e o retorno da SEFAZ continuarão preservados.
      Ao salvar, o Lumeron cria uma NOVA versão preparada, com a mesma venda e numeração.
      A nova versão não será transmitida automaticamente.
    </p>
    <p class="editor-help"><strong>Estoque, valores comerciais, lançamentos financeiros e venda original não serão alterados.</strong>
      Para duplicidade de numeração (539), use Utilitários → Duplicar NFC-e, não esta tela.</p>
  </div>

  @if($errors->any())
    <section class="fiscal-error-card">
      <strong>Não foi possível preparar a correção</strong>
      <ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </section>
  @endif

  <form method="post" action="{{ route('fiscal.nfce.correct.store', $document) }}" autocomplete="off">
    @csrf
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
    @elseif($rejection['group']==='consumer')
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
    @elseif($rejection['group']==='payments')
      <div class="editor-panel">
        <h3>Informações fiscais de pagamento</h3>
        <p class="editor-help">
          Corrija apenas os meios e valores informados à SEFAZ. A soma deve ser igual
          a R$ {{ number_format((float)($snapshot['total'] ?? 0),2,',','.') }}.
          <strong>Esta correção não muda as parcelas nem os lançamentos financeiros da venda.</strong>
          Se houver divergência comercial, reconcilie a venda antes de transmitir.
        </p>
        @foreach(($snapshot['payments'] ?? []) as $index=>$payment)
          @php($field='payments.'.$index.'.')
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
    @endif

    <div class="editor-savebar settings-savebar">
      <div>
        <a class="btn btn-secondary" href="{{ route('fiscal.show', $document) }}">Cancelar</a>
        <button class="btn btn-success" type="submit">
          @include('partials.icon',['name'=>'check','size'=>16]) Salvar nova versão fiscal
        </button>
      </div>
    </div>
  </form>
</section>
@endsection
