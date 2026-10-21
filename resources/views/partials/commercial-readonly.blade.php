@php
    $licenseContext = request()->attributes->get('lumeron_commercial_license', []);
    $isCommercialReadOnly = (bool) ($licenseContext['readonly'] ?? false);
@endphp
@if($isCommercialReadOnly)
<button type="button"
        class="commercial-license-chip"
        data-commercial-readonly
        data-license-chip
        data-license-status="{{ $licenseContext['status'] }}"
        data-denied-message="{{ $licenseContext['denied'] }}"
        aria-label="Modo consulta — {{ $licenseContext['title'] }}. Ver detalhes da restrição"
        title="{{ $licenseContext['title'] }} — Ver detalhes">
    <svg class="commercial-license-chip-lock" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8.5 10V7a3.5 3.5 0 0 1 7 0v3"/></svg>
    <span>Modo consulta</span>
    <span class="commercial-license-chip-dot" aria-hidden="true"></span>
</button>

<dialog id="commercialReadOnlyDialog" class="commercial-readonly-dialog"
        aria-labelledby="commercialReadOnlyTitle"
        aria-describedby="commercialReadOnlyDescription">
    <div class="commercial-readonly-dialog-head">
        <span class="commercial-readonly-dialog-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="5" y="10" width="14" height="11" rx="2"/>
                <path d="M8.5 10V7a3.5 3.5 0 0 1 7 0v3"/>
            </svg>
        </span>
        <button type="button" class="commercial-readonly-close" data-license-dialog-close aria-label="Fechar janela">×</button>
    </div>
    <div class="commercial-readonly-dialog-body">
        <h2 id="commercialReadOnlyTitle">Operação temporariamente indisponível</h2>
        <p id="commercialReadOnlyDescription">{{ $licenseContext['denied'] }}</p>
        <div class="commercial-readonly-dialog-note">Você pode continuar consultando cadastros, movimentações e documentos. Nenhum dado foi alterado.</div>
    </div>
    <div class="commercial-readonly-dialog-footer">
        <button type="button" class="commercial-readonly-understood" data-license-dialog-close>Entendi</button>
    </div>
</dialog>
@endif
