<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REPLACEMENTS = [
        'expiry_soon' => [
            'Registrar aviso interno quando faltarem até N dias para vencer.',
            'Avisar antes do vencimento da licença.',
        ],
        'expired' => [
            'Registrar uma ocorrência interna por período de licença vencido.',
            'Avisar sobre licenças vencidas.',
        ],
        'offline' => [
            'Registrar aviso interno quando uma instalação ficar N dias sem se comunicar.',
            'Avisar sobre empresas sem comunicação recente.',
        ],
    ];

    public function up(): void
    {
        foreach (self::REPLACEMENTS as $code => [$old, $new]) {
            DB::table('automation_rules')
                ->where('code', $code)
                ->where('description', $old)
                ->update(['description' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::REPLACEMENTS as $code => [$old, $new]) {
            DB::table('automation_rules')
                ->where('code', $code)
                ->where('description', $new)
                ->update(['description' => $old]);
        }
    }
};
