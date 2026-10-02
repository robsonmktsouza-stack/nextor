<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\FinancialSettlement;
use App\Models\FinancialTransfer;

class FinancialBalanceService
{
    public function balance(FinancialAccount $account): float
    {
        $settlements=(float)FinancialSettlement::query()
            ->join('financial_entries','financial_entries.id','=','financial_settlements.financial_entry_id')
            ->where('financial_settlements.financial_account_id',$account->id)
            ->whereNull('financial_settlements.reversed_at')
            ->selectRaw("COALESCE(SUM(CASE WHEN financial_entries.type='receivable' THEN financial_settlements.amount ELSE -financial_settlements.amount END),0) total")
            ->value('total');

        $incoming=(float)FinancialTransfer::query()
            ->where('to_account_id',$account->id)->whereNull('cancelled_at')->sum('amount');

        $outgoing=(float)FinancialTransfer::query()
            ->where('from_account_id',$account->id)->whereNull('cancelled_at')->sum('amount');

        return round((float)$account->opening_balance+$settlements+$incoming-$outgoing,2);
    }

    public function accounts(bool $activeOnly=true)
    {
        $accounts=FinancialAccount::query()
            ->when($activeOnly,fn($q)=>$q->where('is_active',true))
            ->orderBy('name')->get();

        foreach($accounts as $account) $account->setAttribute('current_balance',$this->balance($account));

        return $accounts;
    }
}
