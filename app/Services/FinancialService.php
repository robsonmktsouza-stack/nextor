<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\FinancialSettlement;
use App\Models\PaymentMethod;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialService
{
    public function syncSale(Sale $sale, int $userId): void
    {
        if($sale->operation_type!=='sale' || $sale->status!=='completed') {
            return;
        }

        $sale->loadMissing(['payments','customer']);
        $category=$this->salesCategory();
        $installments=max(1,$sale->payments->count());

        foreach($sale->payments as $payment) {
            $paymentMethod=$payment->payment_method
                ? PaymentMethod::query()->where('code',$payment->payment_method)->first()
                : null;
            $defaultAccount=$this->defaultAccount($payment->payment_method);
            $entry=FinancialEntry::query()->firstOrCreate(
                ['sale_payment_id'=>$payment->id],
                [
                    'type'=>'receivable',
                    'status'=>'open',
                    'category_id'=>$category->id,
                    'financial_account_id'=>$payment->receivable ? null : $defaultAccount->id,
                    'customer_id'=>$sale->customer_id,
                    'sale_id'=>$sale->id,
                    'created_by'=>$userId,
                    'description'=>'Venda #'.str_pad((string)$sale->id,5,'0',STR_PAD_LEFT)
                        .' - parcela '.$payment->installment.'/'.$installments,
                    'document_number'=>'VENDA-'.$sale->id,
                    'issue_date'=>($sale->operation_date ?? $sale->created_at)->toDateString(),
                    'competence_date'=>($sale->operation_date ?? $sale->created_at)->toDateString(),
                    'due_date'=>($payment->due_date ?? $sale->operation_date ?? $sale->created_at)->toDateString(),
                    'amount'=>$payment->amount,
                    'paid_amount'=>'0.00',
                    'payment_method'=>$payment->payment_method,
                    'notes'=>'Gerado automaticamente pela venda.',
                ]
            );

            if(!$payment->receivable && $entry->status!=='paid') {
                $locked=FinancialEntry::query()->lockForUpdate()->findOrFail($entry->id);

                if($locked->activeSettlements()->count()===0) {
                    $this->recordSettlement(
                        $locked,
                        (string)$locked->amount,
                        ($sale->operation_date ?? now())->toDateString(),
                        $payment->payment_method ?: 'other',
                        $defaultAccount,
                        $userId,
                        'Recebimento registrado automaticamente na venda.'
                    );
                }
            }

            if($paymentMethod) {
                $this->syncPaymentFee($sale,$payment,$paymentMethod,$defaultAccount,$userId);
            }
        }
    }

    public function settle(FinancialEntry $entry, array $data, int $userId): FinancialSettlement
    {
        return DB::transaction(function () use ($entry,$data,$userId) {
            $locked=FinancialEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if($locked->status==='cancelled') {
                throw ValidationException::withMessages(['amount'=>'Lançamento cancelado não pode receber baixa.']);
            }

            if($locked->status==='paid') {
                throw ValidationException::withMessages(['amount'=>'Este lançamento já está totalmente baixado.']);
            }

            $account=FinancialAccount::query()
                ->whereKey((int)$data['financial_account_id'])
                ->where('is_active',true)
                ->first();

            if(!$account) {
                throw ValidationException::withMessages(['financial_account_id'=>'Conta financeira inválida ou inativa.']);
            }

            return $this->recordSettlement(
                $locked,
                (string)$data['amount'],
                (string)$data['settled_at'],
                (string)$data['payment_method'],
                $account,
                $userId,
                $data['notes'] ?? null,
            );
        },3);
    }

    public function reverse(FinancialSettlement $settlement, ?string $reason=null): void
    {
        DB::transaction(function () use ($settlement,$reason) {
            $locked=FinancialSettlement::query()->lockForUpdate()->findOrFail($settlement->id);

            if($locked->reversed_at!==null) {
                throw ValidationException::withMessages(['settlement'=>'Esta baixa já foi estornada.']);
            }

            $entry=FinancialEntry::query()->lockForUpdate()->findOrFail($locked->financial_entry_id);

            if($entry->status==='cancelled') {
                throw ValidationException::withMessages(['settlement'=>'O lançamento está cancelado.']);
            }

            $locked->update([
                'reversed_at'=>now(),
                'reversal_reason'=>$reason ?: 'Estorno manual',
            ]);

            $this->recalculate($entry);
        },3);
    }

    public function cancelManual(FinancialEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $locked=FinancialEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if($locked->sale_id!==null) {
                throw ValidationException::withMessages([
                    'entry'=>'Lançamentos originados de venda devem ser tratados pelo cancelamento da própria venda.'
                ]);
            }

            if($locked->status==='cancelled') {
                throw ValidationException::withMessages(['entry'=>'Este lançamento já está cancelado.']);
            }

            if($locked->activeSettlements()->exists()) {
                throw ValidationException::withMessages([
                    'entry'=>'Estorne as baixas existentes antes de cancelar este lançamento.'
                ]);
            }

            $locked->update([
                'status'=>'cancelled',
                'paid_amount'=>'0.00',
                'cancelled_at'=>now(),
            ]);
        },3);
    }

    public function reopenManual(FinancialEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $locked=FinancialEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if($locked->sale_id!==null) {
                throw ValidationException::withMessages([
                    'entry'=>'Lançamentos originados de venda não podem ser reabertos diretamente.'
                ]);
            }

            if($locked->status!=='cancelled') {
                throw ValidationException::withMessages(['entry'=>'Apenas lançamentos cancelados podem ser reabertos.']);
            }

            $locked->update([
                'status'=>'open',
                'paid_amount'=>'0.00',
                'cancelled_at'=>null,
            ]);
        },3);
    }

    public function cancelSale(Sale $sale): void
    {
        $entries=FinancialEntry::query()
            ->where('sale_id',$sale->id)
            ->lockForUpdate()
            ->get();

        foreach($entries as $entry) {
            $entry->activeSettlements()->update([
                'reversed_at'=>now(),
                'reversal_reason'=>'Estorno automático pelo cancelamento da venda #'.$sale->id,
                'updated_at'=>now(),
            ]);

            $entry->update([
                'status'=>'cancelled',
                'paid_amount'=>'0.00',
                'cancelled_at'=>now(),
            ]);
        }
    }

    private function recordSettlement(
        FinancialEntry $entry,
        string $amount,
        string $settledAt,
        string $paymentMethod,
        FinancialAccount $account,
        int $userId,
        ?string $notes,
    ): FinancialSettlement {
        $amountCents=$this->cents($amount);
        $balanceCents=$this->cents($entry->amount)-$this->cents($entry->paid_amount);

        if($amountCents<=0) {
            throw ValidationException::withMessages(['amount'=>'Informe um valor de baixa maior que zero.']);
        }

        if($amountCents>$balanceCents) {
            throw ValidationException::withMessages([
                'amount'=>'O valor da baixa não pode ser maior que o saldo do lançamento.'
            ]);
        }

        $settlement=$entry->settlements()->create([
            'financial_account_id'=>$account->id,
            'user_id'=>$userId,
            'amount'=>$this->money($amountCents),
            'settled_at'=>$settledAt,
            'payment_method'=>$paymentMethod,
            'notes'=>$notes,
        ]);

        $this->recalculate($entry);

        return $settlement;
    }

    private function recalculate(FinancialEntry $entry): void
    {
        if($entry->status==='cancelled') {
            return;
        }

        $paidCents=$entry->activeSettlements()
            ->get(['amount'])
            ->sum(fn($settlement)=>$this->cents($settlement->amount));

        $totalCents=$this->cents($entry->amount);

        $entry->update([
            'financial_account_id'=>$entry->financial_account_id ?: $entry->activeSettlements()->value('financial_account_id'),
            'paid_amount'=>$this->money($paidCents),
            'status'=>$paidCents<=0 ? 'open' : ($paidCents>=$totalCents ? 'paid' : 'partial'),
        ]);
    }

    private function syncPaymentFee(
        Sale $sale,
        \App\Models\SalePayment $payment,
        PaymentMethod $method,
        FinancialAccount $account,
        int $userId,
    ): void {
        $paymentCents=$this->cents($payment->amount);
        $percent=(float)$method->fee_percent;
        $fixedCents=$this->cents($method->fee_fixed);
        $feeCents=(int)round($paymentCents*$percent/100)+$fixedCents;

        if($feeCents<=0) return;

        $issueDate=($sale->operation_date ?? $sale->created_at ?? now())->toDateString();
        $dueDate=($payment->due_date ?? $sale->operation_date ?? $sale->created_at ?? now())->toDateString();
        $sourceKey='payment-fee:'.$payment->id;

        $feeEntry=FinancialEntry::query()->firstOrCreate(
            ['source_key'=>$sourceKey],
            [
                'type'=>'payable',
                'status'=>'open',
                'category_id'=>$this->paymentFeeCategory()->id,
                'financial_account_id'=>$account->id,
                'customer_id'=>$sale->customer_id,
                'sale_id'=>$sale->id,
                'created_by'=>$userId,
                'description'=>'Taxa de '.$method->name.' - venda #'.str_pad((string)$sale->id,5,'0',STR_PAD_LEFT),
                'document_number'=>'TAXA-VENDA-'.$sale->id.'-'.$payment->installment,
                'issue_date'=>$issueDate,
                'competence_date'=>$issueDate,
                'due_date'=>$dueDate,
                'credit_date'=>$dueDate,
                'amount'=>$this->money($feeCents),
                'paid_amount'=>'0.00',
                'payment_method'=>$method->code,
                'notes'=>'Custo gerado automaticamente pela forma de pagamento ('.$method->name.').',
            ]
        );

        if(!$payment->receivable && $feeEntry->status!=='paid' && !$feeEntry->activeSettlements()->exists()) {
            $this->recordSettlement(
                $feeEntry,
                (string)$feeEntry->amount,
                $issueDate,
                $method->code,
                $account,
                $userId,
                'Taxa da forma de pagamento baixada automaticamente junto com a venda.'
            );
        }
    }

    private function paymentFeeCategory(): FinancialCategory
    {
        return FinancialCategory::query()->firstOrCreate(
            ['name'=>'Taxas de pagamento','type'=>'expense'],
            ['is_active'=>true],
        );
    }

    private function salesCategory(): FinancialCategory
    {
        $configuredId=(int)AppSetting::value('operations','default_income_category_id',0);
        if($configuredId>0) {
            $configured=FinancialCategory::query()
                ->whereKey($configuredId)
                ->where('type','income')
                ->where('is_active',true)
                ->first();
            if($configured) return $configured;
        }

        return FinancialCategory::query()->firstOrCreate(
            ['name'=>'Vendas','type'=>'income'],
            ['is_active'=>true],
        );
    }

    private function defaultAccount(?string $paymentMethodCode=null): FinancialAccount
    {
        if($paymentMethodCode) {
            $method=PaymentMethod::query()
                ->where('code',$paymentMethodCode)
                ->with('financialAccount')
                ->first();

            if($method?->financialAccount?->is_active) {
                return $method->financialAccount;
            }
        }

        $configuredId=(int)AppSetting::value('operations','default_financial_account_id',0);
        if($configuredId>0) {
            $configured=FinancialAccount::query()->whereKey($configuredId)->where('is_active',true)->first();
            if($configured) return $configured;
        }

        return FinancialAccount::query()->where('is_active',true)->orderBy('id')->first()
            ?? FinancialAccount::query()->create([
                'name'=>'Caixa principal',
                'type'=>'cash',
                'opening_balance'=>'0.00',
                'is_active'=>true,
            ]);
    }

    private function cents(mixed $value): int
    {
        return (int)round((float)$value*100);
    }

    private function money(int $cents): string
    {
        return number_format($cents/100,2,'.','');
    }
}
