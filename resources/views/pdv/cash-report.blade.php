<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fechamento de caixa #{{ $session->id }}</title>
<link rel="stylesheet" href="{{ asset('css/ui-tokens.css') }}?v={{ filemtime(public_path('css/ui-tokens.css')) }}" media="screen">
<link rel="stylesheet" href="{{ asset('css/erp-typography.css') }}?v={{ filemtime(public_path('css/erp-typography.css')) }}" media="screen">
<link rel="stylesheet" href="{{ asset('css/ui-refinement.css') }}?v={{ filemtime(public_path('css/ui-refinement.css')) }}" media="screen">
<link rel="stylesheet" href="{{ asset('css/pdv-cash-document.css') }}?v={{ filemtime(public_path('css/pdv-cash-document.css')) }}"></head><body class="pdv-cash-document {{ request()->boolean('embed') ? 'is-embedded' : '' }}"><main>
@unless(request()->boolean('embed'))
<nav><a class="btn btn-secondary" href="{{ route('pdv.cash.history') }}">Histórico</a><a class="btn btn-secondary" href="{{ route('pdv.index') }}">PDV</a><button class="btn btn-primary" onclick="window.print()">Imprimir / Salvar PDF</button></nav>
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
<p>{{ $breakdown['sales_count'] ?? 0 }} vendas no turno; {{ $breakdown['cancelled_count'] ?? 0 }} canceladas. Somente os pagamentos em dinheiro compõem o saldo do caixa.</p>
<table><thead><tr><th>Meio</th><th class="number">Valor</th></tr></thead><tbody>
@forelse($breakdown['payments'] ?? [] as $code=>$cents)
<tr><td>{{ $methods[$code] ?? 'Forma de pagamento não cadastrada' }}</td><td class="number">R$ {{ number_format($cents/100,2,',','.') }}</td></tr>
@empty<tr><td colspan="2">Sem pagamentos registrados.</td></tr>@endforelse
</tbody></table></section>
<section><h2>Movimentações manuais</h2><table><thead><tr><th>Horário</th><th>Tipo / Origem</th><th>Responsável / Motivo</th><th class="number">Valor</th></tr></thead><tbody>
@forelse($session->movements->sortBy('created_at') as $movement)
<tr><td>{{ $movement->created_at?->format('d/m H:i') }}</td>
<td>{{ match($movement->type){'supply'=>'Suprimento','withdrawal'=>'Sangria','refund'=>'Reembolso',default=>'Outra movimentação'} }}
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
<label for="review_notes" class="field"><span>Observação do responsável</span>
<textarea id="review_notes" name="review_notes" required minlength="5" maxlength="1000" rows="3"></textarea></label>
<label for="credential" class="field"><span>Senha ou PIN do administrador</span>
<input id="credential" type="password" name="credential" required autocomplete="off"></label>
<button class="btn btn-primary" type="submit">Registrar conferência</button></form>
@else<p>Aguardando conferência administrativa.</p>@endif
</section>
@endif
<footer>Relatório operacional de caixa. Não substitui a escrituração contábil ou a documentação fiscal.</footer>
</main></body></html>
