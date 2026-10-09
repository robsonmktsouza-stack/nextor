<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>DANFE NFC-e {{ $danfe['series'] }}/{{ $danfe['number'] }}</title>
  @if($pdfExport ?? false)
    <style>{!! file_get_contents(public_path('css/nfce-danfe.css')) !!}</style>
    {{-- Thermal PDF must declare its page size before Chrome prints it.
         Javascript refines its length after QR Code layout is complete. --}}
    <style id="nextorPdfPage">{!! '@page' !!}{size: {{ $paper==='a4' ? 'A4' : ($paper.'mm '.$pdfPageHeightMm.'mm') }};margin:0}</style>
    <script>{!! file_get_contents(public_path('js/vendor/qrcode-generator.js')) !!}</script>
  @else
    <link rel="stylesheet" href="{{ asset('css/nfce-danfe.css') }}">
    <script defer src="{{ asset('js/vendor/qrcode-generator.js') }}"></script>
  @endif
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
@for($copy=1;$copy<=(!empty($danfe['offline'])?2:1);$copy++)
<main class="danfe-paper" id="{{ $copy===1?'danfePaper':'danfePaper2' }}" aria-label="Documento auxiliar da NFC-e"
  @if(!empty($danfe['offline'])) style="break-after:{{ $copy===1?'page':'auto' }};page-break-after:{{ $copy===1?'always':'auto' }}" @endif>
  @if(!empty($danfe['offline']))
    <div class="danfe-section danfe-center"><strong>{{ $copy===1?'1ª VIA – CONSUMIDOR':'2ª VIA – ESTABELECIMENTO' }}</strong></div>
  @endif
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
    <div id="{{ $copy===1?'danfeQr':'danfeQr2' }}" class="danfe-qr" data-danfe-qr aria-label="QR Code de consulta pública da NFC-e">
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
    @if(!empty($danfe['offline']))
      <div><strong>EMITIDA EM CONTINGÊNCIA – PENDENTE DE AUTORIZAÇÃO</strong></div>
      <div><strong>Documento ainda não autorizado pela SEFAZ</strong></div>
      <div>Imprimir duas vias: consumidor e estabelecimento.</div>
    @else
      <div>Protocolo de autorização: {{ $danfe['protocol'] }}</div>
      <div>{{ $danfe['authorized_at'] }}</div>
    @endif
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
@endfor

<script>
document.addEventListener('DOMContentLoaded', () => {
  const qrTarget=document.getElementById('danfeQr');
  const qrTargets=document.querySelectorAll('[data-danfe-qr]');
  const qrUrl=@json($danfe['qr_url']);
  try {
    if(typeof qrcode!=='function') throw new Error('Gerador de QR Code indisponível');
    const qr=qrcode(0,'M');
    qr.addData(qrUrl);
    qr.make();
    qrTargets.forEach(target=>{target.innerHTML=qr.createSvgTag({cellSize:5,margin:20,scalable:true})});
    const svg=qrTarget.querySelector('svg');
    if(!svg) throw new Error('QR Code inválido');
    svg.setAttribute('role','img');
    svg.setAttribute('aria-label','QR Code de consulta da NFC-e');

    // Configuração da impressão vem do NEXTOR. A caixa nativa do Chrome
    // é aberta somente depois do QR Code ter sido renderizado.
    const preparePaper=()=>{
      const paper=document.body.dataset.paper;
      if(paper==='58'||paper==='80') {
        const receipt=document.getElementById('danfePaper');
        const height=receipt.getBoundingClientRect().height;
        const millimeters=Math.ceil(height*25.4/96)+8;
        const pageHeight=Math.max(100,Math.min(millimeters,1500));
        @if($pdfExport ?? false)
        // Replace the initial thermal @page declaration before printToPDF.
        document.getElementById('nextorPdfPage').textContent='@page{size:'+paper+'mm '+pageHeight+'mm;margin:0}';
        @else
        const sheet=document.createElement('style');
        sheet.textContent='@page{size:'+paper+'mm '+pageHeight+'mm;margin:0}';
        document.head.appendChild(sheet);
        @endif
      }
      @if($pdfExport ?? false)
      document.documentElement.dataset.pdfReady='true';
      @else
      window.print();
      @endif
    };
    @if($pdfExport ?? false)
    // The CLI may print before the next animation frame; size it synchronously.
    preparePaper();
    @else
    requestAnimationFrame(()=>requestAnimationFrame(preparePaper));
    @endif
  } catch(error) {
    document.body.classList.add('print-unavailable');
    qrTarget.textContent='Impressão indisponível: QR Code não gerado.';
  }
});
</script>
</body>
</html>
