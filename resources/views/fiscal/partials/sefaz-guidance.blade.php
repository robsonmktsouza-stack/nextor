@if($guidance)
  @php
    $isNfceGuidance=(bool)($nfce ?? false);
    $guidanceCategory=(string)$guidance['category'];
    $guidanceInformational=in_array($guidanceCategory,[
      'authorized','authorization','cancelled','pending','processing','inutilized',
      'event_recorded','event_unlinked','event_pending','service_status',
      'registration_query','distribution','epec',
    ],true);
    $guidanceClass=$guidanceInformational ? 'fiscal-info-card' : ($isNfceGuidance ? 'nfce-sefaz-rejection' : 'fiscal-error-card');
  @endphp
  <div class="{{ $guidanceClass }}" role="{{ $guidanceInformational?'status':'alert' }}"
       data-sefaz-guidance data-sefaz-category="{{ $guidanceCategory }}" @if($isNfceGuidance && !$guidanceInformational) data-nfce-rejection @endif>
    <div class="sefaz-guidance-content">
      <strong>@if($guidance['code']){{ $guidance['code'] }} — @endif{{ $guidance['title'] }}</strong>
      <p>{{ $guidance['summary'] }}</p>
      @if($guidance['item'] || count($guidance['fields']))
        <p class="sefaz-guidance-fields">
          @if($guidance['item'])<span>Item {{ $guidance['item'] }}</span>@endif
          @if($guidance['item'] && count($guidance['fields'])) · @endif
          @if(count($guidance['fields']))<span>{{ implode(' · ',$guidance['fields']) }}</span>@endif
        </p>
      @endif
      @if(count($guidance['steps']))
        <ul class="sefaz-guidance-steps">
          @foreach($guidance['steps'] as $step)<li>{{ $step }}</li>@endforeach
        </ul>
      @endif
      <details class="sefaz-guidance-details">
        <summary>Detalhes técnicos</summary>
        @if($originalRejection ?? null)
          <p>{{ $originalRejection['code'] }} — {{ preg_replace('/^(?:\d+\s*[-—:]\s*)?Rejei(?:c|ç)(?:a|ã)o\s*:\s*/iu','Rejeição: ',(string)$originalRejection['reason']) }}</p>
        @endif
        <p class="sefaz-guidance-original">{{ $guidance['technical_message'] }}</p>
        @if(($originalStoredError ?? '')!=='' && trim((string)$originalStoredError)!==trim($guidance['technical_message']))
          <p>{{ $originalStoredError }}</p>
        @endif
        @if($guidance['catalog_version'])<p>Catálogo {{ $guidance['catalog_version'] }}</p>@endif
        @foreach($guidance['sources'] as $guidanceSource)
          @php
            $sourceUrl=(string)($guidanceSource['url'] ?? '');
            $sourceParts=parse_url($sourceUrl);
            $safeSource=is_array($sourceParts)
              && ($sourceParts['scheme'] ?? '')==='https'
              && preg_match('/(?:^|\.)gov\.br$/i',(string)($sourceParts['host'] ?? ''))===1
              && !isset($sourceParts['user']) && !isset($sourceParts['pass'])
              && (!isset($sourceParts['port']) || $sourceParts['port']===443);
          @endphp
          <p>@if($safeSource)<a href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">{{ $guidanceSource['title'] }}</a>@else{{ $guidanceSource['title'] }}@endif</p>
        @endforeach
      </details>
    </div>
  </div>
@endif
