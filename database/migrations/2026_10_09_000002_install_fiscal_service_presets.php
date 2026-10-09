<?php

use App\Support\FiscalServicePresetCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $time = now();

        foreach (FiscalServicePresetCatalog::all() as $key => $info) {
            $presetKey = 'service_'.$key;
            if (DB::table('fiscal_tax_groups')->where('preset_key', $presetKey)->exists()) {
                continue;
            }
            $taxConfig = FiscalServicePresetCatalog::groupFields($info['national_code']);

            DB::table('fiscal_tax_groups')->insert([
                'preset_key' => $presetKey,
                'target_crt' => null,
                'name' => $info['label'],
                'kind' => 'services',
                'is_active' => true,
                'is_default' => false,
                'revision' => 1,
                'cfop_pattern' => null,
                'nfce_csosn' => null,
                'icms_csosn' => null,
                'icms_cst' => null,
                'pis_cst' => null,
                'cofins_cst' => null,
                'ipi_cst' => null,
                'iss_exigibility' => '1',
                'tax_config' => json_encode($taxConfig, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'notes' => null,
                'created_at' => $time,
                'updated_at' => $time,
            ]);
        }
    }

    public function down(): void
    {
        foreach (FiscalServicePresetCatalog::all() as $key => $info) {
            $presetKey = 'service_'.$key;
            $row = DB::table('fiscal_tax_groups')->where('preset_key', $presetKey)->first();
            if (!$row || DB::table('services')->where('fiscal_tax_group_id', $row->id)->exists()) {
                continue;
            }

            // Apenas o modelo original e sem vínculos pode ser descartado.
            // Personalizações e atribuições nunca são perdidas.
            $expected = FiscalServicePresetCatalog::groupFields($info['national_code']);
            if ($row->name === $info['label']
                && $row->kind === 'services'
                && $row->iss_exigibility === '1'
                && (bool)$row->is_active
                && !(bool)$row->is_default
                && (int)$row->revision === 1
                && json_decode((string)$row->tax_config, true) === $expected
            ) {
                DB::table('fiscal_tax_groups')->where('id', $row->id)->delete();
            }
        }
    }
};
