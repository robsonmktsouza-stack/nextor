@forelse($documents as $document)
  @php
    $cancelled=$document->status==='cancelled'
      || $document->cancellation_status==='authorized'
      || $document->cancelled_at!==null;
    $statusInfo=$cancelled?['Cancelada','status-muted']:match($document->status){
      'authorized'=>['Autorizada','status-ok'],
      'processing'=>['Processando','status-blue'],
      'pending'=>['Aguardando confirmação','status-blue'],
      'queued'=>['Aguardando envio','status-blue'],
      'rejected'=>['Rejeitada','status-danger'],
      'error','failed'=>['Falha','status-danger'],
      default=>['Preparada','status-blue']
    };
    if(!$cancelled && $document->cancellation_status){
      $statusInfo=match($document->cancellation_status){
        'pending'=>['Cancelamento solicitado','status-blue'],
        'processing'=>['Cancelamento em análise','status-blue'],
        'uncertain'=>['Verificar cancelamento','status-danger'],
        'rejected'=>['Cancelamento rejeitado','status-danger'],
        default=>$statusInfo
      };
    }
    $item=$document->items->first();
    $party=$document->customer?->name ?? data_get($document->recipient_snapshot,'name','—');
    $emissionDate=$document->issue_date ?? $document->authorized_at ?? $document->created_at;
    $total=data_get($document->totals,'total');
  @endphp
  <tr>
    <td class="select-cell"><input type="checkbox" disabled aria-label="Seleção de NF-e indisponível nesta listagem"></td>
    <td><a class="table-link" href="{{ route('fiscal.nfe.edit',$document) }}">{{ $document->document_number ?? '—' }}</a></td>
    <td>{{ $party }}</td>
    <td class="nowrap">{{ $document->series ?? '—' }}</td>
    <td>@if($item?->cfop)<span class="code-tag">{{ $item->cfop }}</span>@else — @endif</td>
    <td>—</td>
    <td class="nowrap">{{ $emissionDate?->format($dateFormat) ?? '—' }}</td>
    <td class="price-strong nowrap">{{ $total!==null ? 'R$ '.number_format((float)$total,2,',','.') : '—' }}</td>
    <td><span class="status {{ $statusInfo[1] }}">{{ $statusInfo[0] }}</span></td>
    <td class="action-cell">
      <div class="row-actions">
        <a class="btn-icon" href="{{ route('fiscal.nfe.edit',$document) }}" data-tooltip="Abrir NF-e" aria-label="Abrir NF-e">@include('partials.icon',['name'=>'chevron','size'=>17])</a>
        @if($document->xml_path)
          <a class="btn-icon" href="{{ route('fiscal.nfe.xml',$document) }}" data-tooltip="Baixar XML" aria-label="Baixar XML">@include('partials.icon',['name'=>'download','size'=>16])</a>
        @endif
        @if($document->xml_path && ($document->status==='authorized' || $cancelled))
          <a class="btn-icon" href="{{ route('fiscal.nfe.danfe',$document) }}" target="_blank" rel="noopener" data-tooltip="Abrir DANFE" aria-label="Abrir DANFE">@include('partials.icon',['name'=>'print','size'=>16])</a>
        @endif
      </div>
    </td>
  </tr>
@empty
  <tr><td colspan="10" class="empty-cell">
    <div class="fiscal-empty-state" role="status">
      @include('partials.icon',['name'=>'receipt','size'=>23])
      <strong>Nenhuma NF-e emitida ou em processamento encontrada neste período.</strong>
      <span>Experimente outro período ou ajuste os filtros.</span>
      <a href="{{ route('fiscal.nfe.drafts.index') }}">Ver rascunhos de NF-e</a>
    </div>
  </td></tr>
@endforelse
