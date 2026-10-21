<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->decimal('monthly_price', 12, 2)->nullable();
            $table->unsignedSmallInteger('period_months')->default(1);
            $table->json('modules');
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        // Only the migration seeds the original catalogue. No plans stay
        // hard-coded in the application configuration or request validators.
        $now = now();
        foreach ([
            ['standard', 'Padrão', ['pdv', 'products']],
            ['advanced', 'Avançado', ['pdv', 'products', 'finance']],
            ['full', 'Completo', ['pdv', 'products', 'finance', 'fiscal', 'services']],
        ] as [$code, $name, $modules]) {
            DB::table('plans')->insert([
                'code' => $code,
                'name' => $name,
                'description' => null,
                'monthly_price' => null,
                'period_months' => 1,
                'modules' => json_encode($modules, JSON_THROW_ON_ERROR),
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::create('automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->string('description', 255);
            $table->unsignedSmallInteger('days')->default(7);
            $table->boolean('enabled')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        foreach ([
            ['expiry_soon', 'Licenças próximas do vencimento', 'Registrar aviso interno quando faltarem até N dias para vencer.', 7],
            ['expired', 'Licenças vencidas', 'Registrar uma ocorrência interna por período de licença vencido.', 0],
            ['offline', 'Instalações sem comunicação', 'Registrar aviso interno quando uma instalação ficar N dias sem se comunicar.', 2],
        ] as [$code, $name, $description, $days]) {
            DB::table('automation_rules')->insert([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'days' => $days,
                'enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::create('automation_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('fingerprint', 120);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['automation_rule_id', 'company_id', 'fingerprint'], 'automation_once_per_license');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_deliveries');
        Schema::dropIfExists('automation_rules');
        Schema::dropIfExists('plans');
    }
};
