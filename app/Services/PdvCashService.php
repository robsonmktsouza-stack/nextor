<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\PaymentMethod;
use App\Models\PdvCashMovement;
use App\Models\PdvCashRegister;
use App\Models\PdvCashSession;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PdvCashService
{
    public function current(int $userId, bool $lock = false): ?PdvCashSession
    {
        $query=PdvCashSession::query()->where('user_id',$userId)->open()->latest('id');
        return $lock ? $query->lockForUpdate()->first() : $query->first();
    }

    /**
     * An open shift belongs to its opening calendar day in the application's
     * timezone. Never silently roll it forward at midnight.
     */
    public function isOverdue(PdvCashSession $session): bool
    {
        return !$session->closed_at && $session->opened_at !== null
            && $session->opened_at->startOfDay()->lt(now()->startOfDay());
    }

    public function overdueSessions()
    {
        return PdvCashSession::query()->open()
            ->where('opened_at','<',now()->startOfDay())
            ->with(['user:id,name','register:id,name,code'])
            ->orderBy('opened_at')
            ->get();
    }

    private function guardFreshShift(PdvCashSession $session): void
    {
        if($this->isOverdue($session)) {
            throw ValidationException::withMessages([
                'cash_session'=>'O caixa foi aberto em dia anterior. Confira e feche o turno antes de continuar.',
            ]);
        }
    }

    /** Called within SalesService's transaction before writing the sale. */
    public function forSale(int $userId): ?PdvCashSession
    {
        $session=$this->current($userId,true);
        if($session) {
            $this->guardFreshShift($session);
        } elseif(PdvCashSession::query()->open()->where('opened_at','<',now()->startOfDay())->exists()) {
            // Optional cash opening must not become a bypass when a physical
            // register was abandoned across midnight.
            throw ValidationException::withMessages([
                'cash_session'=>'Há um caixa de dia anterior pendente de fechamento. Solicite a conferência antes de vender.',
            ]);
        }
        if(!$session && (bool)AppSetting::value('pdv','require_cash_opening',false)) {
            throw ValidationException::withMessages([
                'cash_session'=>'Abra o caixa antes de concluir uma venda no PDV.',
            ]);
        }
        return $session;
    }

    public function open(int $userId, string $amount, ?string $notes, ?int $registerId=null): PdvCashSession
    {
        return DB::transaction(function () use ($userId,$amount,$notes,$registerId) {
            // Lock the physical register before checking for an open shift.
            $register=$registerId===null
                ? null : PdvCashRegister::query()->whereKey($registerId)->lockForUpdate()->firstOrFail();
            if ($register && !$register->is_active) {
                throw ValidationException::withMessages(['register_id'=>'Este caixa está desativado.']);
            }
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            if ($this->current($userId,true)) {
                throw ValidationException::withMessages(['opening_amount'=>'Você já possui um turno aberto. Encerre-o antes de abrir outro.']);
            }
            if ($register && PdvCashSession::query()->where('register_id',$register->id)->open()->exists()) {
                throw ValidationException::withMessages(['register_id'=>'Este caixa já está aberto por outro operador.']);
            }
            return PdvCashSession::query()->create([
                'register_id'=>$register?->id,
                'user_id'=>$userId,'opening_amount'=>$amount,
                'opening_notes'=>$notes,'opened_at'=>now(),
            ]);
        },3);
    }

    /**
     * Transfer physical cash between two distinct, authenticated operators.
     * Both the previous close and new opening commit atomically.
     */
    public function handover(int $outgoingId,int $incomingId,string $counted,?string $notes): PdvCashSession
    {
        if ($incomingId===$outgoingId) {
            throw ValidationException::withMessages(['incoming_user_id'=>'Escolha outro operador para receber o turno.']);
        }
        return DB::transaction(function () use ($outgoingId,$incomingId,$counted,$notes) {
            $users=User::query()->whereIn('id',[$outgoingId,$incomingId])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $incoming=$users->get($incomingId);
            if (!$incoming || !$incoming->is_active || !$incoming->canAccess('pdv')) {
                throw ValidationException::withMessages(['incoming_user_id'=>'O próximo operador não está autorizado a usar o PDV.']);
            }
            $existing=$this->current($outgoingId,true);
            if (!$existing || !$existing->register_id) {
                throw ValidationException::withMessages(['cash_session'=>'Abra um caixa físico antes de transferir o turno.']);
            }
            $register=PdvCashRegister::query()->whereKey($existing->register_id)->lockForUpdate()->firstOrFail();
            $session=PdvCashSession::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail();
            $this->guardFreshShift($session);
            if ($session->closed_at || $session->user_id!==$outgoingId) {
                throw ValidationException::withMessages(['cash_session'=>'Este turno já foi encerrado.']);
            }
            if ($this->current($incomingId,true)) {
                throw ValidationException::withMessages(['incoming_user_id'=>'O próximo operador já possui caixa aberto.']);
            }
            $breakdown=$this->breakdown($session);
            $cents=(int)round((float)$counted*100);
            $difference=$cents-$breakdown['expected'];
            if ($difference!==0 && trim((string)$notes)==='') {
                throw ValidationException::withMessages(['notes'=>'Justifique a diferença antes de transferir o caixa.']);
            }
            $session->update([
                'closing_amount'=>self::currency($cents),
                'expected_amount'=>self::currency($breakdown['expected']),
                'difference_amount'=>self::currency($difference),
                'closing_breakdown'=>$breakdown,
                'closing_notes'=>$notes,
                'closed_by'=>$outgoingId,
                'closed_at'=>now(),
                'handover_to'=>$incomingId,
            ]);
            return PdvCashSession::query()->create([
                'register_id'=>$register->id,'previous_session_id'=>$session->id,
                'user_id'=>$incomingId,'opening_amount'=>self::currency($cents),
                'opening_notes'=>'Recebido do turno #'.$session->id.($notes ? ' · '.trim($notes) : ''),
                'opened_at'=>now(),
            ]);
        },3);
    }

    /**
     * SalePayment.amount is already the NET cash allocated to the sale,
     * not the notes tendered (cash_received). Never subtract change again.
     * All captured sales remain physical inflows even when later cancelled;
     * explicit refund movements account for the subsequent cash outflow.
     */
    public function breakdown(PdvCashSession $session): array
    {
        $end=$session->closed_at ?? now();
        $sales=Sale::query()
            ->with('payments')
            ->where('source','pdv')
            ->where('operation_type','sale')
            ->where(function ($q) use ($session,$end) {
                $q->where('pdv_cash_session_id',$session->id)
                  ->orWhere(function ($legacy) use ($session,$end) {
                      $legacy->whereNull('pdv_cash_session_id')
                          ->where('user_id',$session->user_id)
                          ->where('completed_at','>=',$session->opened_at)
                          ->where('completed_at','<=',$end);
                  });
            })->get();

        $methods=PaymentMethod::query()->pluck('kind','code');
        $cash=0;
        $byMethod=[];
        $cancelledCount=0;
        foreach($sales as $sale) {
            if($sale->status==='cancelled') $cancelledCount++;
            foreach($sale->payments as $payment) {
                $code=(string)($payment->payment_method ?: 'unclassified');
                $value=(int)round((float)$payment->amount*100);
                $byMethod[$code]=($byMethod[$code] ?? 0)+$value;
                if(($methods[$code] ?? null)==='cash' || $code==='cash') {
                    $cash+=$value;
                }
            }
        }
        $movementSums=['supply'=>0,'withdrawal'=>0,'refund'=>0];
        foreach(PdvCashMovement::query()->where('cash_session_id',$session->id)->get() as $movement) {
            if(array_key_exists($movement->type,$movementSums)) {
                $movementSums[$movement->type]+=(int)round((float)$movement->amount*100);
            }
        }

        $expected=(int)round((float)$session->opening_amount*100)
            +$cash+$movementSums['supply']
            -$movementSums['withdrawal']-$movementSums['refund'];

        return [
            'opening'=>(int)round((float)$session->opening_amount*100),
            'cash_sales'=>$cash,
            'sales_count'=>$sales->count(),
            'cancelled_count'=>$cancelledCount,
            'payments'=>$byMethod,
            'supply'=>$movementSums['supply'],
            'withdrawal'=>$movementSums['withdrawal'],
            'refund'=>$movementSums['refund'],
            'expected'=>$expected,
        ];
    }

    public static function currency(int $cents): string
    {
        return number_format($cents/100,2,'.','');
    }

    public function close(int $userId, string $counted, ?string $notes): PdvCashSession
    {
        return DB::transaction(function () use ($userId,$counted,$notes) {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $session=$this->current($userId,true);
            if(!$session) {
                throw ValidationException::withMessages([
                    'closing_amount'=>'Não há caixa aberto para fechar.',
                ]);
            }
            $breakdown=$this->breakdown($session);
            $cents=(int)round((float)$counted*100);
            $difference=$cents-$breakdown['expected'];
            if($difference!==0 && trim((string)$notes)==='') {
                throw ValidationException::withMessages([
                    'closing_notes'=>'Informe o motivo ou observação para a diferença apurada no caixa.',
                ]);
            }
            $session->update([
                'closing_amount'=>self::currency($cents),
                'expected_amount'=>self::currency($breakdown['expected']),
                'difference_amount'=>self::currency($difference),
                'closing_breakdown'=>$breakdown,
                'closing_notes'=>$notes,
                'closed_by'=>$userId,
                'closed_at'=>now(),
            ]);
            return $session->refresh();
        },3);
    }

    /**
     * Emergency, supervised blind close when yesterday's operator is absent.
     * Uses the original session, stocks/cash history untouched; closed_by
     * identifies the supervisor, and the reason remains on the report.
     */
    public function closeOverdueBySupervisor(int $sessionId, int $supervisorId, string $counted, string $reason): PdvCashSession
    {
        return DB::transaction(function () use ($sessionId,$supervisorId,$counted,$reason) {
            $reference=PdvCashSession::query()->findOrFail($sessionId);
            // Keep the same locking order as normal close(): owner, session.
            User::query()->whereKey($reference->user_id)->lockForUpdate()->firstOrFail();
            $session=PdvCashSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            if(!$this->isOverdue($session) || (int)$session->user_id===$supervisorId) {
                throw ValidationException::withMessages([
                    'cash_session'=>'Selecione um caixa de dia anterior pertencente a outro operador.',
                ]);
            }
            $supervisor=User::query()->findOrFail($supervisorId);
            if(!$supervisor->is_active || !in_array($supervisor->role,['admin','manager'],true)
                || !$supervisor->canAccess('pdv')) {
                throw ValidationException::withMessages([
                    'cash_session'=>'A regularização exige gerente ou administrador autorizado.',
                ]);
            }
            $reason=trim($reason);
            if(mb_strlen($reason)<5) {
                throw ValidationException::withMessages([
                    'reason'=>'Informe o motivo da regularização do caixa.',
                ]);
            }
            $breakdown=$this->breakdown($session);
            $cents=(int)round((float)$counted*100);
            $difference=$cents-$breakdown['expected'];
            $session->update([
                'closing_amount'=>self::currency($cents),
                'expected_amount'=>self::currency($breakdown['expected']),
                'difference_amount'=>self::currency($difference),
                'closing_breakdown'=>$breakdown,
                'closing_notes'=>'Regularização supervisionada: '.$reason,
                'closed_by'=>$supervisorId,
                'closed_at'=>now(),
            ]);
            return $session->refresh();
        },3);
    }

    public function movement(int $userId, string $type, string $amount, string $reason,
        ?int $saleId = null, ?int $returnId = null, ?int $approvedBy = null): PdvCashMovement
    {
        return DB::transaction(function () use ($userId,$type,$amount,$reason,$saleId,$returnId,$approvedBy) {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $session=$this->current($userId,true);
            if(!$session) {
                throw ValidationException::withMessages([
                    'amount'=>'Abra o caixa antes de movimentar dinheiro.',
                ]);
            }
            $this->guardFreshShift($session);
            $cents=(int)round((float)$amount*100);
            if($cents<=0) {
                throw ValidationException::withMessages(['amount'=>'Informe um valor positivo.']);
            }
            if($type==='refund') {
                if((bool)$saleId===(bool)$returnId) {
                    throw ValidationException::withMessages([
                        'amount'=>'Informe uma venda cancelada ou uma devolução, mas não ambas.',
                    ]);
                }
                if($returnId) {
                    $returnReference=SaleReturn::query()->findOrFail($returnId);
                    // A venda é o lock comum para reembolsos de devoluções
                    // diferentes; evita que duas saídas superem o total em espécie.
                    $sale=Sale::query()->lockForUpdate()->findOrFail($returnReference->sale_id);
                    $return=SaleReturn::query()->lockForUpdate()->findOrFail($returnId);
                    if($return->sale_id!==$sale->id || $return->status!=='completed' || $sale->source!=='pdv') {
                        throw ValidationException::withMessages(['amount'=>'Devolução inválida para reembolso do PDV.']);
                    }
                } else {
                    $sale=Sale::query()->lockForUpdate()->findOrFail($saleId);
                    if($sale->source!=='pdv' || $sale->status!=='cancelled') {
                        throw ValidationException::withMessages([
                            'amount'=>'Reembolso direto somente para vendas do PDV canceladas.',
                        ]);
                    }
                }
                $cashCodes=PaymentMethod::query()->where('kind','cash')->pluck('code')->push('cash')->unique();
                $cashPaid=(int)round((float)$sale->payments()
                    ->whereIn('payment_method',$cashCodes)->sum('amount')*100);
                $already=(int)round((float)PdvCashMovement::query()
                    ->where('sale_id',$sale->id)->where('type','refund')->sum('amount')*100);
                if($cents>$cashPaid-$already) {
                    throw ValidationException::withMessages(['amount'=>'Reembolso maior que o dinheiro disponível desta venda.']);
                }
                if($returnId) {
                    $refunded=(int)round((float)PdvCashMovement::query()
                        ->where('sale_return_id',$returnId)->where('type','refund')->sum('amount')*100);
                    if($cents>(int)round((float)$return->total*100)-$refunded) {
                        throw ValidationException::withMessages(['amount'=>'Reembolso maior que o saldo desta devolução.']);
                    }
                }
                $saleId=$sale->id;
            } elseif(!in_array($type,['supply','withdrawal'],true)) {
                throw ValidationException::withMessages(['type'=>'Tipo de movimento inválido.']);
            }
            if(in_array($type,['withdrawal','refund'],true)
                && $cents>$this->breakdown($session)['expected']) {
                throw ValidationException::withMessages([
                    'amount'=>'A saída de dinheiro não pode ultrapassar o saldo esperado do caixa.',
                ]);
            }
            return PdvCashMovement::query()->create([
                'cash_session_id'=>$session->id,'user_id'=>$userId,
                'type'=>$type,'amount'=>self::currency($cents),
                'reason'=>trim($reason),'sale_id'=>$type==='refund'?$saleId:null,
                'sale_return_id'=>$type==='refund'?$returnId:null,
                'approved_by'=>$approvedBy,
            ]);
        },3);
    }

    public function review(PdvCashSession $session, int $reviewer, string $notes): void
    {
        DB::transaction(function () use ($session,$reviewer,$notes) {
            $locked=PdvCashSession::query()->lockForUpdate()->findOrFail($session->id);
            if(!$locked->closed_at || $locked->reviewed_at) {
                throw ValidationException::withMessages(['review'=>'Fechamento ainda aberto ou já conferido.']);
            }
            $locked->update([
                'reviewed_by'=>$reviewer,'reviewed_at'=>now(),'review_notes'=>trim($notes),
            ]);
        },3);
    }
}
