@extends('layouts.app')
@section('title',$tabMeta['label'].' '.($document->series!==null ? $document->series.'/' : '').($document->document_number ?? '#'.$document->id))
@section('titleMeta','Documento fiscal')
@php
  $nfceFiscalUser=$tab==='nfce' && auth()->user()->canAccess('fiscal');
  $canCancelNfce=$nfceFiscalUser
    && $document->status==='authorized'
    && !$document->cancellation_status
    && !$document->cancelled_at
    && preg_match('/^\d{44}$/',(string)$document->access_key)===1
    && preg_match('/^\d{15}$/',(string)$document->protocol)===1
    && $document->authorized_at
    && !$document->authorized_at->isFuture()
    && $document->authorized_at->copy()->addMinutes(30)->isFuture()
    && ($document->environment!=='production'
      || (bool)\App\Models\AppSetting::value('nfce','advanced_operations_production_approved',false));
@endphp
@section('actions')
  <a class="btn btn-secondary" href="{{ route('fiscal.index',['tab'=>$tab]) }}">
    @include('partials.icon',['name'=>'arrow-left','size'=>16]) Voltar
  </a>
  @if($nfceFiscalUser && $document->status==='prepared' && empty($nfcePreflightErrors))
    <form method="post" action="{{ route('fiscal.nfce.emit',$document) }}"
      data-confirm-submit="{{ $document->emission_mode==='offline'
        ? 'Preparar a NFC-e série '.$document->series.'/'.$document->document_number.' em contingência? O sistema assinará o XML e gerará o DANFE sem enviar à SEFAZ.'
        : 'Deseja transmitir a NFC-e série '.$document->series.'/'.$document->document_number.' para a SEFAZ em ambiente de '.($document->environment==='homologation'?'homologação':'produção').'?' }}"
      data-confirm-kind="action"
      data-confirm-title="{{ $document->emission_mode==='offline'?'Preparar NFC-e offline':'Confirmar emissão da NFC-e' }}"
      data-confirm-label="{{ $document->emission_mode==='offline'?'Preparar contingência':'Transmitir NFC-e' }}">
      @csrf
      <button type="submit" class="btn btn-success">
        @include('partials.icon',['name'=>'check','size'=>16])
        {{ $document->emission_mode==='offline'?'Preparar contingência':'Emitir NFC-e' }}
      </button>
    </form>
  @endif
  @if($nfceFiscalUser && $document->status==='pending' && $document->access_key)
    <form method="post" action="{{ route('fiscal.nfce.consult',$document) }}">
      @csrf
      <button type="submit" class="btn btn-secondary">@include('partials.icon',['name'=>'refresh','size'=>16]) Consultar SEFAZ</button>
    </form>
  @endif
  @if($nfceFiscalUser && $document->emission_mode==='offline' && $document->status==='offline_signed')
    <form method="post" action="{{ route('fiscal.nfce.offline.transmit',$document) }}"
      data-confirm-submit="A conectividade foi restabelecida? Transmitir a mesma NFC-e assinada à SEFAZ, sem gerar nova chave?"
      data-confirm-title="Transmitir NFC-e offline" data-confirm-kind="action">
      @csrf
      <button type="submit" class="btn btn-success">Transmitir NFC-e pendente</button>
    </form>
  @endif
  @if($nfceFiscalUser)
    <details class="fiscal-utilities fiscal-detail-utilities" data-fiscal-utilities>
      <summary class="btn btn-secondary" aria-label="Abrir utilitários da NFC-e">
        @include('partials.icon',['name'=>'layers','size'=>16])
        <span>Utilitários</span>
        @include('partials.icon',['name'=>'down','size'=>13])
      </summary>
      <div class="fiscal-utilities-dropdown">
        @if($document->xml_path)
          <a href="{{ route('fiscal.nfce.xml',$document) }}">
            @include('partials.icon',['name'=>'download','size'=>16])
            <span>{{ $document->status==='authorized'
              && (str_ends_with($document->xml_path,'authorized.xml') || str_ends_with($document->xml_path,'authorized-recovered.xml'))
              ? 'Baixar XML autorizado' : 'Baixar XML assinado' }}</span>
          </a>
        @endif
        @if($document->emission_mode==='offline'
          && in_array($document->status,['offline_signed','offline_print_pending','offline_sending','pending'],true)
          && $document->xml_path)
          <a target="_blank" rel="noopener noreferrer" href="{{ route('fiscal.nfce.danfe',$document) }}">
            @include('partials.icon',['name'=>'print','size'=>16]) <span>DANFE contingência (2 vias)</span>
          </a>
        @endif
        @if($document->status==='authorized'
          && (str_ends_with((string)$document->xml_path,'authorized.xml')
            || str_ends_with((string)$document->xml_path,'authorized-recovered.xml')))
          <a target="_blank" rel="noopener noreferrer" href="{{ route('fiscal.nfce.danfe',$document) }}">
            @include('partials.icon',['name'=>'print','size'=>16]) <span>Imprimir DANFE NFC-e</span>
          </a>
        @endif
        @if($document->status==='authorized'
          && !str_ends_with((string)$document->xml_path,'authorized-recovered.xml'))
          <form method="post" action="{{ route('fiscal.nfce.recover-xml',$document) }}"
            data-confirm-submit="Reconstruir somente o XML autorizado com os arquivos já salvos? A NFC-e não será retransmitida."
            data-confirm-kind="action" data-confirm-title="Recuperar XML autorizado" data-confirm-label="Recuperar XML">
            @csrf
            <button type="submit">
              @include('partials.icon',['name'=>'refresh','size'=>16]) <span>Recuperar XML autorizado</span>
            </button>
          </form>
        @endif
        @if($canCancelNfce)
          <button type="button" data-dialog-open="nfceCancelDialog">
            @include('partials.icon',['name'=>'x','size'=>16]) <span>Cancelamento</span>
          </button>
        @endif
        <a href="{{ route('fiscal.nfce.inutilizations') }}">
          @include('partials.icon',['name'=>'file-minus','size'=>16]) <span>Inutilização</span>
        </a>
        @if(auth()->user()->canAccess('settings'))
          <a href="{{ route('settings.index',['tab'=>$configuration['settings_tab']]) }}">
            @include('partials.icon',['name'=>'settings','size'=>16]) <span>Configurar</span>
          </a>
        @endif
      </div>
    </details>
  @elseif(auth()->user()->canAccess('settings'))
    <a class="btn btn-secondary" href="{{ route('settings.index',['tab'=>$configuration['settings_tab']]) }}">
      @include('partials.icon',['name'=>'settings','size'=>16]) Configurar
    </a>
  @endif
@endsection

@section('content')
@include('fiscal._nav')

@if($canCancelNfce)
<dialog class="erp-dialog small-dialog" id="nfceCancelDialog" aria-labelledby="nfceCancelTitle">
  <form method="post" action="{{ route('fiscal.nfce.cancel',$document) }}">
    @csrf
    <div class="dialog-header">
      <div>
        <h2 id="nfceCancelTitle">Cancelar NFC-e</h2>
        <p>Série {{ $document->series }} / número {{ $document->document_number }}</p>
      </div>
      <button type="button" class="close-dialog" data-dialog-close aria-label="Fechar">×</button>
    </div>
    <div class="dialog-body">
      <p>O cancelamento será solicitado à SEFAZ. A NFC-e só ficará cancelada quando o evento for confirmado.</p>
      <label class="field">
        <span>Justificativa do cancelamento</span>
        <textarea name="reason" required minlength="15" maxlength="255" rows="3"
          placeholder="Explique o motivo do cancelamento (15 a 255 caracteres)"></textarea>
      </label>
      <label class="field nfce-cancel-no-circulation">
        <input type="checkbox" name="no_circulation" value="1" required>
        <span>Confirmo que a mercadoria não circulou.</span>
      </label>
      <p class="nfce-cancel-deadline">Prazo normal: até {{ $document->authorized_at->copy()->addMinutes(30)->format($dateFormat.' H:i:s') }} (horário do sistema).</p>
    </div>
    <div class="dialog-footer">
      <button type="button" class="btn btn-secondary" data-dialog-close>Voltar</button>
      <button type="submit" class="btn btn-danger">Solicitar cancelamento</button>
    </div>
  </form>
</dialog>
@endif


@php
  $statusInfo=match($document->status){
    'authorized'=>['Autorizado','status-ok'],
    'processing'=>['Processando','status-blue'],
    'pending'=>['Pendente','status-blue'],
    'offline_signed'=>['Contingência impressa','status-blue'],
    'offline_print_pending'=>['Contingência: DANFE pendente','status-danger'],
    'offline_sending'=>['Transmitindo contingência','status-blue'],
    'rejected'=>['Rejeitado','status-danger'],
    'error'=>['Erro','status-danger'],
    'failed'=>['Falha','status-danger'],
    'cancelled'=>['Cancelado','status-muted'],
    default=>['Preparado','status-blue'],
  };
@endphp

<div class="fiscal-detail-status">
  <div><span>Status</span><strong class="status {{ $statusInfo[1] }}">{{ $statusInfo[0] }}</strong></div>
  <div><span>Ambiente</span><strong>{{ $document->environment==='production'?'Produção':'Homologação' }}</strong></div>
  <div><span>Emissão</span><strong>{{ $document->emission_mode==='offline'?'Contingência':'Normal' }}</strong></div>
  <div><span>Preparado em</span><strong>{{ ($document->prepared_at ?? $document->created_at)?->format($dateFormat.' H:i') }}</strong></div>
</div>

@if($tab==='nfce' && $document->status==='prepared')
  @if(count($nfcePreflightErrors))
    <section class="fiscal-error-card">
      @include('partials.icon',['name'=>'alert','size'=>20])
      <div>
        <strong>Dados pendentes antes da transmissão</strong>
        <ul>
          @foreach($nfcePreflightErrors as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
        @if(!$document->access_key && !$document->protocol && !$document->xml_path && !$document->response_path
          && !$document->authorized_at && auth()->user()->canAccess('fiscal'))
          <form method="post" action="{{ route('fiscal.nfce.refresh-data',$document) }}" style="margin-top:12px">
            @csrf
            <button class="btn btn-secondary" type="submit">
              @include('partials.icon',['name'=>'refresh-cw','size'=>16]) Recarregar configuração fiscal
            </button>
          </form>
        @endif
      </div>
    </section>
  @endif
@endif

<div class="fiscal-detail-grid">
  <section class="cms-card fiscal-detail-card">
    <div class="card-header"><div><h2>Documento</h2><p>Identificação e retorno fiscal registrado.</p></div></div>
    <dl class="fiscal-data-list">
      <div><dt>Modelo</dt><dd>{{ $tabMeta['label'] }}</dd></div>
      <div><dt>Série / número</dt><dd>{{ $document->series ?? '—' }} / {{ $document->document_number ?? '—' }}</dd></div>
      <div><dt>Chave de acesso</dt><dd class="fiscal-key">{{ $document->access_key ?: '—' }}</dd></div>
      <div><dt>Protocolo</dt><dd>{{ $document->protocol ?: '—' }}</dd></div>
      <div><dt>Autorizado em</dt><dd>{{ $document->authorized_at?->format($dateFormat.' H:i:s') ?? '—' }}</dd></div>
      <div><dt>Processado em</dt><dd>{{ $document->processed_at?->format($dateFormat.' H:i:s') ?? '—' }}</dd></div>
    </dl>
  </section>

  <section class="cms-card fiscal-detail-card">
    <div class="card-header"><div><h2>Origem</h2><p>Operação comercial relacionada ao documento.</p></div></div>
    <dl class="fiscal-data-list">
      <div><dt>Venda</dt><dd>
        @if($document->sale)
          @if(auth()->user()->canAccess('sales'))<a class="table-link" href="{{ route('sales.show',$document->sale) }}">#{{ $document->sale_id }}</a>@else #{{ $document->sale_id }} @endif
        @else — @endif
      </dd></div>
      <div><dt>Cliente</dt><dd>{{ $document->sale?->customer?->name ?? 'Consumidor não identificado' }}</dd></div>
      <div><dt>Responsável</dt><dd>{{ $document->sale?->user?->name ?? '—' }}</dd></div>
      <div><dt>Valor da operação</dt><dd>{{ $document->sale ? 'R$ '.number_format((float)$document->sale->total,2,',','.') : '—' }}</dd></div>
      <div><dt>Forma(s) de pagamento</dt><dd>{{ $document->sale?->payments?->pluck('payment_method')->filter()->unique()->implode(', ') ?: '—' }}</dd></div>
    </dl>
  </section>
</div>

@if($document->error_message)
  <section class="fiscal-error-card">
    @include('partials.icon',['name'=>'alert','size'=>20])
    <div><strong>Retorno com problema</strong><p>{{ $document->error_message }}</p></div>
  </section>
@endif

@if($document->contingency_reason)
  <section class="fiscal-info-card">
    <strong>Contingência</strong>
    <p>{{ $document->contingency_reason }}</p>
  </section>
@endif

@if($document->cancellation_status || $document->cancelled_at)
  <section class="cms-card fiscal-detail-card">
    <div class="card-header"><div><h2>Cancelamento</h2><p>Estado do evento de cancelamento.</p></div></div>
    <dl class="fiscal-data-list">
      <div><dt>Status</dt><dd>{{ $document->cancellation_status ?: ($document->cancelled_at?'Concluído':'—') }}</dd></div>
      <div><dt>Solicitado em</dt><dd>{{ $document->cancellation_requested_at?->format($dateFormat.' H:i:s') ?? '—' }}</dd></div>
      <div><dt>Cancelado em</dt><dd>{{ $document->cancelled_at?->format($dateFormat.' H:i:s') ?? '—' }}</dd></div>
      <div><dt>Motivo</dt><dd>{{ $document->cancellation_reason ?: '—' }}</dd></div>
    </dl>
  </section>
@endif
@endsection
