<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_nfe_sync_states',function(Blueprint $table){
            $table->id();
            $table->string('cnpj',14);
            $table->string('environment',16)->default('production');
            $table->char('last_nsu',15)->default('000000000000000');
            $table->char('max_nsu',15)->default('000000000000000');
            $table->boolean('auto_sync')->default(false);
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_status',30)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['cnpj','environment']);
        });

        Schema::create('incoming_nfes',function(Blueprint $table){
            $table->id();
            $table->char('access_key',44)->unique();
            $table->char('nsu',15)->nullable()->index();
            $table->string('supplier_cnpj',14)->nullable();
            $table->string('supplier_name',190)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->decimal('total',15,2)->nullable();
            $table->string('status',24)->default('summary');
            $table->string('manifestation',32)->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->json('items')->nullable();
            $table->json('summary')->nullable();
            $table->string('xml_path')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['status','issued_at']);
            $table->index(['supplier_cnpj','issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_nfes');
        Schema::dropIfExists('incoming_nfe_sync_states');
    }
};
