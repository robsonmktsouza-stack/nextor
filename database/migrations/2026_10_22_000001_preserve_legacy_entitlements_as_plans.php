<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time compatibility migration. From now on plans are authoritative.
 * Preserve previously granted per-company rights as archived legacy plans.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('plans') || !Schema::hasTable('companies')) {
            return;
        }

        DB::transaction(function (): void {
            $catalog = DB::table('plans')->get()->keyBy('code');
            foreach (DB::table('companies')->orderBy('id')->cursor() as $company) {
                $currentPlan = $catalog->get($company->plan);
                $old = $this->normalize(json_decode($company->modules ?? 'null', true));
                $expected = $currentPlan ? $this->normalize(json_decode($currentPlan->modules, true)) : [];

                // A null legacy snapshot used the plan defaults; no migration
                // of the company is needed.
                if ($company->modules === null && $currentPlan) {
                    continue;
                }
                if ($currentPlan && $old === $expected) {
                    continue;
                }

                $code = 'legacy-'.$company->id;
                if (DB::table('plans')->where('code', $code)->exists()) {
                    throw new RuntimeException('Conflito de código com plano legado: '.$code);
                }

                DB::table('plans')->insert([
                    'code' => $code,
                    'name' => 'Plano anterior #'.$company->id,
                    'description' => 'Acessos preservados na migração.',
                    'monthly_price' => null,
                    'period_months' => 1,
                    'modules' => json_encode($old, JSON_THROW_ON_ERROR),
                    'active' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('companies')->where('id', $company->id)->update([
                    'plan' => $code,
                    'updated_at' => now(),
                ]);
                DB::table('license_events')->insert([
                    'company_id' => $company->id,
                    'user_id' => null,
                    'event_type' => 'plan_migrated',
                    'description' => 'Plano anterior preservado.',
                    'metadata' => json_encode([
                        'former_plan' => $company->plan,
                        'new_plan' => $code,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }
        });
    }

    private function normalize(mixed $modules): array
    {
        if (!is_array($modules)) {
            return [];
        }
        $allowed = array_keys(config('license.modules'));
        $result = array_values(array_unique(array_filter($modules,
            fn ($module) => is_string($module) && in_array($module, $allowed, true))));
        sort($result, SORT_STRING);
        return $result;
    }

    public function down(): void
    {
        // Deliberately irreversible: do not silently destroy the user's
        // historic plan assignments and granted modules.
    }
};
