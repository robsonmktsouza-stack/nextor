<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('incoming_nfe_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('cnpj',14)->unique();
            $table->string('last_nsu',15)->default('000000000000000');
            $table->string('max_nsu',15)->default('000000000000000');
            $table->boolean('enabled')->default(false);
            $table->string('status',24)->default('not_started');
            $table->string('message',500)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_allowed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('incoming_nfe_documents', function (Blueprint $table) {
            $table->id();
            $table->string('cnpj_dest',14);
            $table->string('nsu',15);
            $table->string('access_key',44)->nullable();
            $table->string('issuer_cnpj',14)->nullable();
            $table->string('issuer_name',190)->nullable();
            $table->string('document_number',12)->nullable();
            $table->string('series',6)->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->decimal('total',16,2)->nullable();
            $table->string('document_status',20)->default('summary');
            $table->string('xml_path')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['cnpj_dest','nsu']);
            $table->unique(['cnpj_dest','access_key']);
            $table->index(['cnpj_dest','issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_nfe_documents');
        Schema::dropIfExists('incoming_nfe_sync_states');
    }
};
