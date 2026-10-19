<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upgrade only the two exact built-in codes from the original migration.
     * Never overwrite custom names, account links, fees, flags or sort order.
     */
    public function up(): void
    {
        if (!Schema::hasTable('payment_methods')) return;

        foreach ([
            'debit_card'=>['name'=>'Cartão de débito','kind'=>'debit_card','sort_order'=>30],
            'credit_card'=>['name'=>'Cartão de crédito','kind'=>'credit_card','sort_order'=>40],
        ] as $code=>$defaults) {
            $row=DB::table('payment_methods')->where('code',$code)->first();
            if (!$row) {
                DB::table('payment_methods')->insert([
                    'code'=>$code,
                    'name'=>$defaults['name'],
                    'kind'=>$defaults['kind'],
                    'sort_order'=>$defaults['sort_order'],
                    'is_active'=>true,
                    'pdv_enabled'=>true,
                    'fee_percent'=>0,
                    'fee_fixed'=>0,
                    'settlement_days'=>0,
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);
            } elseif ($row->kind==='card') {
                DB::table('payment_methods')
                    ->where('id',$row->id)
                    ->where('kind','card')
                    ->update(['kind'=>$defaults['kind'],'updated_at'=>now()]);
            }
        }
    }

    public function down(): void
    {
        // A reversible rewrite could silently make existing fiscal records
        // ambiguous or delete a method already used by a sale. No data loss.
    }
};
