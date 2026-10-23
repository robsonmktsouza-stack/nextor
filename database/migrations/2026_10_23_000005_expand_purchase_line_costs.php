<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('purchases',function(Blueprint $t){
            $t->string('keyword',190)->nullable();
            $t->decimal('other_expenses',14,2)->default(0);
        });
        Schema::table('purchase_items',function(Blueprint $t){
            $t->decimal('discount',14,2)->default(0);
            $t->decimal('other_costs',14,2)->default(0);
            $t->decimal('external_costs',14,2)->default(0);
            $t->decimal('ipi',14,2)->default(0);
            $t->decimal('icms_st',14,2)->default(0);
        });
    }
    public function down(): void {
        Schema::table('purchase_items',fn(Blueprint $t)=>$t->dropColumn(['discount','other_costs','external_costs','ipi','icms_st']));
        Schema::table('purchases',fn(Blueprint $t)=>$t->dropColumn(['keyword','other_expenses']));
    }
};
