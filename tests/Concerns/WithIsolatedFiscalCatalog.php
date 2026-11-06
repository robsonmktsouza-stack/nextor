<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use LogicException;

/** Explicit fixtures for tests of pre-provisioning/import states, never production. */
trait WithIsolatedFiscalCatalog
{
    protected function isolateFiscalCatalog(): void
    {
        if (!app()->environment('testing') || DB::connection()->getDriverName() !== 'sqlite') {
            throw new LogicException('Fiscal catalog isolation is restricted to SQLite tests.');
        }

        // The automatic provisioning migration is covered independently. These
        // scenarios own their reference snapshots while legacy presets remain.
        $ruleIds=DB::table('fiscal_tax_rules')->whereNotNull('catalog_version')->pluck('id');
        $natureIds=DB::table('operation_natures')->whereNotNull('catalog_version')->pluck('id');
        $groupIds=DB::table('fiscal_tax_groups')->whereNotNull('catalog_version')->pluck('id');
        DB::table('fiscal_tax_rule_revisions')->whereIn('fiscal_tax_rule_id',$ruleIds)->delete();
        DB::table('fiscal_tax_rules')->whereIn('id',$ruleIds)->delete();
        DB::table('fiscal_operation_nature_revisions')->whereIn('operation_nature_id',$natureIds)->delete();
        DB::table('fiscal_tax_group_revisions')->whereIn('fiscal_tax_group_id',$groupIds)->delete();
        DB::table('operation_natures')->whereIn('id',$natureIds)->delete();
        DB::table('fiscal_tax_groups')->whereIn('id',$groupIds)->delete();
        DB::table('fiscal_catalog_entries')->delete();
        DB::table('fiscal_catalog_releases')->delete();
    }
}
