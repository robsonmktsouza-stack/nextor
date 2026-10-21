<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Before the plan becomes authoritative, grandfather existing installations
 * whose individual module choices differ from their commercial plan.
 *
 * Each difference receives an archived, company-specific plan. The operator
 * can move that company to a current catalogue plan when ready. This avoids
 * silently revoking a currently used module or granting one that was disabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        $allowed = ['pdv', 'products', 'finance', 'fiscal', 'services'];

        DB::transaction(function () use ($allowed): void {
            $plans = DB::table('plans')->get()->keyBy('code');

            DB::table('companies')->orderBy('id')->chunkById(100, function ($companies) use ($plans, $allowed): void {
                foreach ($companies as $company) {
                    if ($company->modules === null) {
                        continue;
                    }

                    $original = $plans->get($company->plan);
                    if (!$original) {
                        // Do not silently reassign a plan if its original
                        // definition is missing: resolve that case explicitly.
                        continue;
                    }

                    $previous = json_decode((string) $company->modules, true);
                    $planModules = json_decode((string) $original->modules, true);
                    if (!is_array($previous) || !is_array($planModules)
                        || array_diff($previous, $allowed)) {
                        continue;
                    }
                    $previous = array_values(array_unique($previous));
                    $planModules = array_values(array_unique($planModules));
                    sort($previous);
                    sort($planModules);

                    if ($previous === $planModules) {
                        continue;
                    }

                    // One new plan per legacy company keeps its exact
                    // historical permissions, including an empty set.
                    $code = 'legacy_'.$company->id;
                    if (DB::table('plans')->where('code', $code)->exists()) {
                        continue; // never overwrite operator-managed plans
                    }

                    DB::table('plans')->insert([
                        'code' => $code,
                        'name' => 'Legado #'.$company->id,
                        'description' => 'Acessos preservados de '.$original->name,
                        'monthly_price' => $original->monthly_price,
                        'period_months' => $original->period_months,
                        'modules' => json_encode($previous, JSON_THROW_ON_ERROR),
                        'active' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('companies')->where('id', $company->id)
                        ->where('plan', $company->plan)->update([
                            'plan' => $code,
                            'updated_at' => now(),
                        ]);

                    DB::table('license_events')->insert([
                        'company_id' => $company->id,
                        'user_id' => null,
                        'event_type' => 'plan_migrated',
                        'description' => 'Plano anterior preservado.',
                        'metadata' => json_encode([
                            'previous_plan' => $company->plan,
                            'preserved_modules' => $previous,
                            'new_plan' => $code,
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                    ]);
                }
            });
        });
    }

    public function down(): void
    {
        // Deliberately do not roll back access rights or remove plan records.
        // A licence could have been changed by an administrator since up().
    }
};
