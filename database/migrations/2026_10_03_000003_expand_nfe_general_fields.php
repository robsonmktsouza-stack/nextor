<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('nfe_drafts', function (Blueprint $table) {
            $table->string('substitute_state_registration',30)->nullable()->after('final_consumer');
            $table->boolean('has_referenced_document')->default(false)->after('substitute_state_registration');
            $table->boolean('inform_issue_datetime')->default(true)->after('has_referenced_document');
            $table->boolean('inform_exit_datetime')->default(false)->after('inform_issue_datetime');
            $table->boolean('inform_expected_delivery_date')->default(false)->after('inform_exit_datetime');
        });
    }

    public function down(): void
    {
        Schema::table('nfe_drafts', function (Blueprint $table) {
            $table->dropColumn([
                'substitute_state_registration',
                'has_referenced_document',
                'inform_issue_datetime',
                'inform_exit_datetime',
                'inform_expected_delivery_date',
            ]);
        });
    }
};
