<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>DANFE NFC-e {{ $danfe['series'] }}/{{ $danfe['number'] }}</title>
  <link rel="stylesheet" href="{{ asset('css/nfce-danfe.css') }}">
  <script defer src="{{ asset('js/vendor/qrcode-generator.js') }}"></script>
</head>
<body data-paper="{{ $paper }}">
@php
  $money = static fn ($amount) => number_format((float) $amount,2,',','.');
  $qty = static function ($amount) {
      $value = number_format((float) $amount,4,',','.');
      return rtrim(rtrim($value,'0'),',');
  };
  $taxId = static function ($value) {
      $number = preg_replace('/\D/','',(string) $value);
      if (strlen($number) === 14) {
          return substr($number,0,2).'.'.substr($number,2,3).'.'.substr($number,5,3).'/'.substr($number,8,4).'-'.substr($number,12,2);
      }
      if (strlen($number) === 11) {
          return substr($number,0,3).'.'.substr($number,3,3).'.'.substr($number,6,3).'-'.substr($number,9,2);
      }
      return $value;
  };
@endphp
<div class="danfe-tools" aria-label="Ferramentas de impressão">
  <div class="danfe-tools-title">
    <strong>DANFE NFC-e</strong>
    <span>Série {{ $danfe['series'] }} · nº {{ $danfe['number'] }} · {{ $danfe['homologation'] ? 'Homologação' : 'Produção' }}</span>
  </div>
  <div class="danfe-tools-buttons">
    <a href="{{ route($printRoute,['fiscalDocumentJob'=>$fiscalDocument,'paper'=>'80']) }}"
       class="{{ $paper === '80' ? 'selected' : '' }}" aria-current="{{ $paper === '80' ? 'page' : 'false' }}">80 mm (72 mm úteis)</a>
    <a href="{{ route($printRoute,['fiscalDocumentJob'=>$fiscalDocument,'paper'=>'58']) }}"
       class="{{ $paper === '58' ? 'selected' : '' }}" aria-current="{{ $paper === '58' ? 'page' : 'false' }}">58 mm (48 mm úteis)</a>
    <button id="printDanfe" type="button" disabled>Preparando QR Code...</button>
    @if(auth()->user()->canAccess('fiscal'))
      <a href="{{ route('fiscal.show',$fiscalDocument) }}">Voltar à NFC-e</a>
    @else
      <a href="{{ route('pdv.index') }}">Voltar ao PDV</a>
    @endif
  </div>
  <p>
    @if($paper === '58')
      Use o tamanho de papel <strong>58(48) mm</strong> do driver. O DANFE ocupa os 48 mm imprimíveis.
    @else
      Use a bobina de <strong>80 mm</strong> do driver. O DANFE ocupa 72 mm imprimíveis.
    @endif
    No Chrome, escolha <strong>margens: nenhuma</strong>, <strong>escala: 100%</strong>
    e desative cabeçalhos/rodapés. O corte e a sobra de papel no fim dependem do tamanho de página
    e do driver instalado. A opção A4 serve apenas para salvar/imprimir em uma folha A4, não para simular a bobina.
  </p>
</div>

<main class="danfe-paper" id="danfePaper" aria-label="Documento auxiliar da NFC-e">
  <header class="danfe-issuer">
    <strong>{{ $danfe['issuer']['name'] }}</strong>
    <div>{{ strlen(preg_replace('/\D/','',$danfe['issuer']['tax_id']))===14 ? 'CNPJ' : 'CPF' }}: {{ $taxId($danfe['issuer']['tax_id']) }}</div>
    <div>{{ $danfe['issuer']['address'] }}</div>
    @if($danfe['issuer']['city'])
      <div>{{ $danfe['issuer']['city'] }}@if($danfe['issuer']['zip']) · CEP {{ $danfe['issuer']['zip'] }}@endif</div>
    @endif
    <div class="danfe-caption">Documento Auxiliar da Nota Fiscal de Consumidor Eletrônica</div>
  </header>

  <section class="danfe-section danfe-products" aria-label="Itens da venda">
    <div class="danfe-table-heading">
      <span>CÓDIGO / DESCRIÇÃO</span>
      <span>VALOR TOTAL</span>
    </div>
    @foreach($danfe['items'] as $item)
      <div class="danfe-item">
        <div class="danfe-item-head">
          <div><span class="danfe-item-code">{{ $item['code'] }}</span> {{ $item['description'] }}</div>
          <strong>{{ $money($item['total']) }}</strong>
        </div>
        <div class="danfe-item-measure">
          {{ $qty($item['qty']) }} {{ $item['unit'] }} × R$ {{ number_format($item['unit_price'],4,',','.') }}
        </div>
      </div>
    @endforeach
  </section>

  <section class="danfe-section danfe-totals" aria-label="Totais da nota">
    <div class="danfe-line"><span>Qtde. total de itens</span><strong>{{ $danfe['count'] }}</strong></div>
    <div class="danfe-line"><span>Valor total R$</span><strong>{{ $money($danfe['totals']['items']) }}</strong></div>
    @if($danfe['totals']['freight'] > 0)
      <div class="danfe-line"><span>Frete R$</span><strong>{{ $money($danfe['totals']['freight']) }}</strong></div>
    @endif
    @if($danfe['totals']['insurance'] > 0)
      <div class="danfe-line"><span>Seguro R$</span><strong>{{ $money($danfe['totals']['insurance']) }}</strong></div>
    @endif
    @if($danfe['totals']['other'] > 0)
      <div class="danfe-line"><span>Outras despesas R$</span><strong>{{ $money($danfe['totals']['other']) }}</strong></div>
    @endif
    @if($danfe['totals']['discount'] > 0)
      <div class="danfe-line"><span>Desconto R$</span><strong>- {{ $money($danfe['totals']['discount']) }}</strong></div>
    @endif
    <div class="danfe-line danfe-grand-total"><span>VALOR A PAGAR R$</span><strong>{{ $money($danfe['totals']['amount']) }}</strong></div>
    <div class="danfe-table-heading danfe-payment-heading"><span>FORMA DE PAGAMENTO</span><span>VALOR PAGO</span></div>
    @foreach($danfe['payments'] as $payment)
      <div class="danfe-line"><span>{{ $payment['type'] }}</span><span>{{ $money($payment['amount']) }}</span></div>
    @endforeach
    @if($danfe['totals']['change'] > 0)
      <div class="danfe-line"><span>Troco R$</span><strong>{{ $money($danfe['totals']['change']) }}</strong></div>
    @endif
    @if($danfe['totals']['taxes'] !== '')
      <div class="danfe-small">Valor aproximado dos tributos: R$ {{ $money($danfe['totals']['taxes']) }}</div>
    @endif
  </section>

  <section class="danfe-section danfe-lookup">
    <strong>Consulte pela Chave de Acesso em</strong>
    <div class="danfe-url">{{ $danfe['consultation_url'] }}</div>
    <div class="danfe-access-key">{{ $danfe['key_groups'] }}</div>
  </section>

  <section class="danfe-section danfe-qr-section">
    <div class="danfe-qr-title">Consulta via leitor de QR Code</div>
    <div id="danfeQr" class="danfe-qr" aria-label="QR Code de consulta pública da NFC-e">
      <div id="danfeQrStatus" class="danfe-qr-placeholder">Gerando QR Code...</div>
    </div>
    @if($danfe['consumer_id'])
      <div class="danfe-consumer">CONSUMIDOR {{ $danfe['consumer_type'] }}: {{ $taxId($danfe['consumer_id']) }}</div>
      @if($danfe['consumer_name'])<div class="danfe-center">{{ $danfe['consumer_name'] }}</div>@endif
      @if($danfe['delivery_address'])<div class="danfe-center">{{ $danfe['delivery_address'] }}</div>@endif
    @else
      <div class="danfe-consumer">CONSUMIDOR NÃO IDENTIFICADO</div>
    @endif
  </section>

  <section class="danfe-section danfe-authorization">
    <div><strong>NFC-e nº {{ str_pad((string)$danfe['number'],9,'0',STR_PAD_LEFT) }} Série {{ $danfe['series'] }}</strong></div>
    <div>{{ $danfe['issued_at'] }}</div>
    <div>Protocolo de autorização: {{ $danfe['protocol'] }}</div>
    <div>{{ $danfe['authorized_at'] }}</div>
  </section>

  @if($danfe['fiscal_message'])
    <section class="danfe-section danfe-notes">{{ $danfe['fiscal_message'] }}</section>
  @endif

  @if($danfe['homologation'])
    <section class="danfe-section danfe-homologation">
      EMITIDA EM AMBIENTE DE HOMOLOGAÇÃO – SEM VALOR FISCAL
    </section>
  @endif

  @if($danfe['additional_message'])
    <section class="danfe-section danfe-notes">{{ $danfe['additional_message'] }}</section>
  @endif
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const qrTarget=document.getElementById('danfeQr');
  const printButton=document.getElementById('printDanfe');
  const qrUrl=@json($danfe['qr_url']);
  try {
    if(typeof qrcode!=='function') throw new Error('Gerador local indisponível');
    const qr=qrcode(0,'M');
    qr.addData(qrUrl);
    qr.make();
    qrTarget.innerHTML=qr.createSvgTag({cellSize:5,margin:20,scalable:true});
    const svg=qrTarget.querySelector('svg');
    if(!svg) throw new Error('QR Code não renderizado');
    svg.setAttribute('role','img');
    svg.setAttribute('aria-label','QR Code de consulta da NFC-e');
    printButton.disabled=false;
    printButton.textContent='Imprimir DANFE NFC-e';
    printButton.addEventListener('click',()=>window.print());
  } catch(error) {
    qrTarget.textContent='Não foi possível gerar o QR Code. Impressão bloqueada.';
    printButton.textContent='QR Code indisponível';
    printButton.disabled=true;
  }
});
</script>
</body>
</html>
