<?php

namespace App\Services;

use App\Models\FinancialEntry;
use App\Models\FinancialRecurrence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialRecurrenceService
{
    public function generateDue(?FinancialRecurrence $only=null, ?Carbon $through=null): int
    {
        $through=($through ?: today())->copy()->startOfDay();
        $generated=0;

        $query=FinancialRecurrence::query()->where('is_active',true);
        if($only) $query->whereKey($only->id);

        foreach($query->orderBy('id')->get() as $recurrence) {
            DB::transaction(function () use ($recurrence,$through,&$generated) {
                $locked=FinancialRecurrence::query()->lockForUpdate()->findOrFail($recurrence->id);
                $guard=0;

                while(
                    $locked->is_active
                    && $locked->next_date
                    && $locked->next_date->lte($through)
                    && (!$locked->end_date || $locked->next_date->lte($locked->end_date))
                    && $guard<120
                ) {
                    $occurrence=$locked->next_date->copy();

                    $entry=FinancialEntry::query()->firstOrCreate(
                        [
                            'recurrence_id'=>$locked->id,
                            'recurrence_occurrence_date'=>$occurrence->toDateString(),
                        ],
                        [
                            'type'=>$locked->type,
                            'status'=>'open',
                            'category_id'=>$locked->category_id,
                            'financial_account_id'=>$locked->financial_account_id,
                            'customer_id'=>$locked->customer_id,
                            'created_by'=>$locked->created_by,
                            'description'=>$locked->description,
                            'issue_date'=>$occurrence->toDateString(),
                            'competence_date'=>$occurrence->toDateString(),
                            'due_date'=>$occurrence->toDateString(),
                            'amount'=>$locked->amount,
                            'paid_amount'=>'0.00',
                            'payment_method'=>$locked->payment_method,
                            'keywords'=>$locked->keywords,
                            'notes'=>'Gerado pela recorrência #'.$locked->id.($locked->notes ? "\n".$locked->notes : ''),
                        ]
                    );

                    if($entry->wasRecentlyCreated) $generated++;

                    $locked->next_date=$this->advance($occurrence,$locked->frequency,$locked->interval_count);
                    $locked->last_generated_at=now();

                    if($locked->end_date && $locked->next_date->gt($locked->end_date)) {
                        $locked->is_active=false;
                    }

                    $locked->save();
                    $guard++;
                }
            },3);
        }

        return $generated;
    }

    private function advance(Carbon $date, string $frequency, int $interval): Carbon
    {
        $interval=max(1,$interval);

        return match($frequency) {
            'weekly'=>$date->copy()->addWeeks($interval),
            'yearly'=>$date->copy()->addYearsNoOverflow($interval),
            default=>$date->copy()->addMonthsNoOverflow($interval),
        };
    }
}
