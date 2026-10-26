<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Histórico de caixa — Lumeron</title>
<link rel="stylesheet" href="{{ asset('css/pdv-cash-report-modal.css') }}?v={{ filemtime(public_path('css/pdv-cash-report-modal.css')) }}">
<style>
body{font:14px Arial,Helvetica,sans-serif;background:#e9ecef;color:#2c383e;margin:0}
main{max-width:1080px;margin:30px auto;background:white;padding:24px;border:1px solid #cbd2d6}
header{display:flex;justify-content:space-between;align-items:center;gap:12px}
h1{font-size:21px;margin:0 0 20px}a{color:#17566e}
table{width:100%;border-collapse:collapse}th{text-align:left;background:#e9eef1;font-size:12px}
th,td{border-bottom:1px solid #d9dee1;padding:10px 8px}
.num{text-align:right;font-variant-numeric:tabular-nums}
small{color:#667982}nav{padding:16px 0}
</style></head><body><main>
<header><h1>Histórico de caixas</h1><a href="{{ route('pdv.index') }}">Voltar ao PDV</a></header>
<table><thead><tr><th>Turno</th><th>Caixa físico</th><th>Operador</th><th>Abertura</th><th>Fechamento</th>
<th class="num">Esperado</th><th class="num">Contado</th><th class="num">Diferença</th><th>Situação</th></tr></thead>
<tbody>
@forelse($sessions as $session)
<tr>
<td><a href="{{ route('pdv.cash.report',$session) }}" data-cash-report-open
    data-cash-report-url="{{ route('pdv.cash.report',['cashSession'=>$session,'embed'=>1]) }}">#{{ $session->id }}</a></td>
<td>{{ $session->register?->name ?? 'Caixa não informado' }}</td>
<td>{{ $session->user?->name ?? '—' }}</td><td>{{ $session->opened_at?->format('d/m/Y H:i') }}</td>
<td>{{ $session->closed_at?->format('d/m/Y H:i') ?? 'Aberto' }}</td>
<td class="num">{{ $session->expected_amount===null ? '—' : number_format((float)$session->expected_amount,2,',','.') }}</td>
<td class="num">{{ $session->closing_amount===null ? '—' : number_format((float)$session->closing_amount,2,',','.') }}</td>
<td class="num">{{ $session->difference_amount===null ? '—' : number_format((float)$session->difference_amount,2,',','.') }}</td>
<td>{{ !$session->closed_at ? 'Em operação' : ($session->reviewed_at ? 'Conferido' : ((float)$session->difference_amount!==0.0 ? 'Aguardando conferência' : 'Fechado')) }}</td>
</tr>
@empty
<tr><td colspan="9">Nenhum turno registrado.</td></tr>
@endforelse
</tbody></table><nav>{{ $sessions->links() }}</nav>
<small>Cada fechamento registra a apuração do turno correspondente.</small>
</main>
@include('pdv.partials.cash-report-modal', [
  'autoCashReportId'=>0,
  'cashReportBackLabel'=>'Voltar ao histórico',
])
<script defer src="{{ asset('js/pdv-cash-report-modal.js') }}?v={{ filemtime(public_path('js/pdv-cash-report-modal.js')) }}"></script>
</body></html>
