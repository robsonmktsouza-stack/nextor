<?php

use App\Support\FiscalTaxPresetCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fiscal_tax_groups', function (Blueprint $table): void {
            $table->string('preset_key', 80)->nullable()->unique('fiscal_tax_groups_preset_unique');
            $table->char('target_crt', 1)->nullable()->index('fiscal_tax_groups_crt_index');
        });

        $now = now();
        foreach (FiscalTaxPresetCatalog::all() as $key => $preset) {
            // Nunca sobrescrever cadastro existente, nem alterar o grupo padrão.
            if (DB::table('fiscal_tax_groups')->where('preset_key', $key)->exists()) {
                continue;
            }
            $settings = $preset['tax_config'] ?? [];
            DB::table('fiscal_tax_groups')->insert([
                'preset_key' => $key,
                'target_crt' => $preset['target_crt'],
                'name' => $preset['name'],
                'kind' => 'products',
                'revision' => 1,
                'is_active' => (bool) $preset['is_active'],
                'is_default' => false,
                'cfop_pattern' => $preset['cfop_pattern'],
                'nfce_csosn' => $preset['nfce_csosn'] ?? null,
                'icms_csosn' => $preset['icms_csosn'] ?? null,
                'icms_cst' => $preset['icms_cst'] ?? null,
                'pis_cst' => $preset['pis_cst'],
                'cofins_cst' => $preset['cofins_cst'],
                'ipi_cst' => null,
                'iss_exigibility' => null,
                'tax_config' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'notes' => $preset['notes'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Uma migração revertida NÃO pode apagar grupos personalizados nem
        // desassociar produtos de um perfil fiscal usado por uma venda.
        foreach (FiscalTaxPresetCatalog::all() as $key => $preset) {
            $row = DB::table('fiscal_tax_groups')->where('preset_key', $key)->first();
            if (!$row) {
                continue;
            }
            if (DB::table('products')->where('fiscal_tax_group_id', $row->id)->exists()
                || DB::table('services')->where('fiscal_tax_group_id', $row->id)->exists()) {
                continue;
            }
            $expectedConfig = $preset['tax_config'] ?? [];
            $actualConfig = json_decode((string)$row->tax_config, true);
            $untouched = (int)$row->revision === 1
                && (bool)$row->is_default === false
                && (bool)$row->is_active === (bool)$preset['is_active']
                && $row->name === $preset['name']
                && $row->target_crt === $preset['target_crt']
                && $row->cfop_pattern === $preset['cfop_pattern']
                && $row->icms_csosn === ($preset['icms_csosn'] ?? null)
                && $row->icms_cst === ($preset['icms_cst'] ?? null)
                && $row->pis_cst === $preset['pis_cst']
                && $row->cofins_cst === $preset['cofins_cst']
                && $actualConfig === $expectedConfig
                && $row->notes === $preset['notes'];

            if ($untouched) {
                DB::table('fiscal_tax_groups')->where('id', $row->id)->delete();
            }
        }

        // Registros já utilizados/editados permanecem como grupos normais.
        Schema::table('fiscal_tax_groups', function (Blueprint $table): void {
            $table->dropUnique('fiscal_tax_groups_preset_unique');
            $table->dropIndex('fiscal_tax_groups_crt_index');
            $table->dropColumn(['preset_key', 'target_crt']);
        });
    }
};
