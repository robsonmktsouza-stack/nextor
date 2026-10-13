<?php

namespace Tests\Concerns;

use App\Models\CompanySetting;

/**
 * Fixture for feature tests that exercise modules after the ordinary
 * one-time identification of the company has been completed.
 */
trait WithConfiguredCompany
{
    protected function setUp(): void
    {
        parent::setUp();

        CompanySetting::current()->update([
            'document'=>'12345678000199',
            'legal_name'=>'Empresa de Teste Ltda',
            'state'=>'BA',
            'city'=>'Salvador',
        ]);
    }
}
