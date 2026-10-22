<dialog id="pdvCashReportModal" class="pdv-cash-report-modal" aria-labelledby="pdvCashReportTitle"
        data-auto-report-url="{{ $autoCashReportId ? route('pdv.cash.report',['cashSession'=>$autoCashReportId,'embed'=>1]) : '' }}">
  <div class="pdv-cash-report-toolbar">
    <div class="pdv-cash-report-heading">
      <span class="pdv-cash-report-kicker">PDV · CAIXA</span>
      <h2 id="pdvCashReportTitle">Fechamento de caixa</h2>
    </div>
    <div class="pdv-cash-report-actions">
      <button type="button" data-cash-report-back class="pdv-cash-report-back">
        <span aria-hidden="true">←</span> {{ $cashReportBackLabel ?? 'Voltar ao PDV' }}
      </button>
      <a href="{{ route('pdv.cash.history') }}" class="pdv-cash-report-history">Histórico</a>
      <button type="button" data-cash-report-print class="pdv-cash-report-print" disabled>
        Imprimir / Salvar PDF
      </button>
      <button type="button" data-cash-report-close class="pdv-cash-report-close" aria-label="Fechar relatório">×</button>
    </div>
  </div>
  <div class="pdv-cash-report-content">
    <p class="pdv-cash-report-loading" data-cash-report-loading>Carregando relatório...</p>
    <iframe title="Relatório de fechamento de caixa" data-cash-report-frame
            referrerpolicy="same-origin"></iframe>
  </div>
</dialog>
