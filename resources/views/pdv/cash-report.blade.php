<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fechamento de caixa #{{ $session->id }}</title>
<style>
@page{size:A4;margin:15mm}
*{box-sizing:border-box}
body{font:12px/1.45 Arial,Helvetica,sans-serif;background:#eceff1;color:#26343d;margin:0}
main{width:210mm;max-width:100%;margin:15px auto;background:white;padding:18mm;border:1px solid #c6ced3}
@if(request()->boolean('embed'))
@media screen{
  body{background:#f0f3f5}
  main{width:min(100%,860px);margin:0 auto;padding:28px 32px;border:0;min-height:100%}
}
@media screen and (max-width:640px){
  main{padding:17px 14px}
  .meta{flex-wrap:wrap;gap:12px}
  .meta div{min-width:40%}
}
@endif
nav{display:flex;gap:12px;justify-content:flex-end;margin-bottom:15px}
a,button{color:#17566e}button{cursor:pointer}h1{font-size:22px;margin:0}
h2{font-size:13px;text-transform:uppercase;margin:21px 0 7px;padding-bottom:5px;border-bottom:1px solid #b7c3c9}
.meta{display:flex;gap:28px;justify-content:space-between;margin:12px 0 20px}
.meta div{flex:1}.meta span{display:block;color:#5d6d77;font-size:11px}
table{border-collapse:collapse;width:100%}td,th{padding:7px 8px;border-bottom:1px solid #dbe1e5}
th{text-align:left;background:#edf1f3;font-size:11px}.number{text-align:right;font-variant-numeric:tabular-nums}
.total td{background:#e8eef0;font-size:14px;font-weight:bold;border-top:2px solid #7b929e}
section{break-inside:avoid}p{white-space:pre-wrap;overflow-wrap:anywhere}
footer{margin-top:24px;border-top:1px solid #aab7c0;padding-top:10px;color:#5e6a71}
@media print{body{background:white}main{width:auto;margin:0;padding:0;border:0}nav,form{display:none!important}}
</style></head><body><main>
@unless(request()->boolean('embed'))
<nav><a href="{{ route('pdv.cash.history') }}">Histórico</a><a href="{{ route('pdv.index') }}">PDV</a><button onclick="window.print()">Imprimir / Salvar PDF</button></nav>
@endunless
<h1>Fechamento de caixa #{{ $session->id }}</h1>
<div class="meta">
<div><span>Caixa</span><strong>{{ $session->register?->name ?? 'Histórico' }}</strong></div>
<div><span>Operador responsável</span><strong>{{ $session->user?->name }}</strong></div>
<div><span>Aberto em</span><strong>{{ $session->opened_at?->format('d/m/Y H:i') }}</strong></div>
<div><span>Fechado em</span><strong>{{ $session->closed_at?->format('d/m/Y H:i') ?? 'Em operação' }}</strong></div>
</div>
@if($session->previous_session_id)
<p>Turno recebido do fechamento #{{ $session->previous_session_id }}.</p>
@endif
@if($session->handover_to)
<p>Turno transferido para {{ $session->incomingOperator?->name }}.</p>
@endif
<section><h2>Apuração do dinheiro</h2>
<table>
<tr><td>Fundo de abertura</td><td class="number">R$ {{ number_format(($breakdown['opening'] ?? 0)/100,2,',','.') }}</td></tr>
<tr><td>Vendas recebidas em dinheiro (líquidas de troco)</td><td class="number">R$ {{ number_format(($breakdown['cash_sales'] ?? 0)/100,2,',','.') }}</td></tr>
<tr><td>Suprimentos (+)</td><td class="number">R$ {{ number_format(($breakdown['supply'] ?? 0)/100,2,',','.') }}</td></tr>
<tr><td>Sangrias (−)</td><td class="number">R$ {{ number_format(($breakdown['withdrawal'] ?? 0)/100,2,',','.') }}</td></tr>
<tr><td>Reembolsos em dinheiro (−)</td><td class="number">R$ {{ number_format(($breakdown['refund'] ?? 0)/100,2,',','.') }}</td></tr>
<tr class="total"><td>Saldo esperado</td><td class="number">R$ {{ number_format(($breakdown['expected'] ?? 0)/100,2,',','.') }}</td></tr>
@if($session->closed_at)
<tr><td>Dinheiro contado</td><td class="number">R$ {{ number_format((float)$session->closing_amount,2,',','.') }}</td></tr>
<tr class="total"><td>Diferença (sobra + / falta −)</td><td class="number">R$ {{ number_format((float)$session->difference_amount,2,',','.') }}</td></tr>
@endif
</table></section>
<section><h2>Vendas e meios de pagamento</h2>
<p>{{ $breakdown['sales_count'] ?? 0 }} vendas vinculadas ou históricas do turno; {{ $breakdown['cancelled_count'] ?? 0 }} canceladas. Valores de pagamentos recebidos, não somados integralmente ao numerário.</p>
<table><thead><tr><th>Meio</th><th class="number">Valor</th></tr></thead><tbody>
@forelse($breakdown['payments'] ?? [] as $code=>$cents)
<tr><td>{{ $methods[$code] ?? $code }}</td><td class="number">R$ {{ number_format($cents/100,2,',','.') }}</td></tr>
@empty<tr><td colspan="2">Sem pagamentos registrados.</td></tr>@endforelse
</tbody></table></section>
<section><h2>Movimentações manuais</h2><table><thead><tr><th>Horário</th><th>Tipo / Origem</th><th>Responsável / Motivo</th><th class="number">Valor</th></tr></thead><tbody>
@forelse($session->movements->sortBy('created_at') as $movement)
<tr><td>{{ $movement->created_at?->format('d/m H:i') }}</td>
<td>{{ match($movement->type){'supply'=>'Suprimento','withdrawal'=>'Sangria','refund'=>'Reembolso',default=>$movement->type} }}
@if($movement->sale_id) · Venda #{{ $movement->sale_id }}@endif
@if($movement->sale_return_id) · Devolução #{{ $movement->sale_return_id }}@endif</td>
<td>{{ $movement->user?->name }} · {{ $movement->reason }}
@if($movement->approved_by)<small> · Aprovado por {{ $movement->approver?->name }}</small>@endif</td>
<td class="number">R$ {{ number_format((float)$movement->amount,2,',','.') }}</td></tr>
@empty<tr><td colspan="4">Sem movimentações manuais.</td></tr>@endforelse
</tbody></table></section>
@if($session->closing_notes)<section><h2>Observação do fechamento</h2><p>{{ $session->closing_notes }}</p></section>@endif
@if($session->closed_at)
<section><h2>Conferência administrativa</h2>
@if($session->reviewed_at)
<p>Conferido por {{ $session->reviewer?->name }} em {{ $session->reviewed_at->format('d/m/Y H:i') }}.</p><p>{{ $session->review_notes }}</p>
@elseif(in_array(auth()->user()->role,['admin','manager'],true) && auth()->user()->canAccess('pdv'))
<form method="post" action="{{ route('pdv.cash.review',$session) }}{{ request()->boolean('embed') ? '?embed=1' : '' }}">@csrf
<label for="review_notes">Observação do responsável</label>
<textarea id="review_notes" name="review_notes" required minlength="5" maxlength="1000" rows="3" style="width:100%"></textarea>
<label for="credential">Senha ou PIN do administrador</label>
<input id="credential" type="password" name="credential" required autocomplete="off">
<button type="submit">Registrar conferência</button></form>
@else<p>Aguardando conferência administrativa.</p>@endif
</section>
@endif
<footer>Relatório operacional de caixa. Não substitui a escrituração contábil ou a documentação fiscal.</footer>
</main></body></html>