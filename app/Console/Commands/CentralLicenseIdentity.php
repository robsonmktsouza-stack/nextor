<?php

namespace App\Console\Commands;

use App\Models\CompanySetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only identity/preflight for a server-owned local provisioning tool.
 * Prints only non-secret CNPJ so the central manager cannot bind a wrong ERP.
 */
final class CentralLicenseIdentity extends Command
{
    protected $signature = 'lumeron:license-identity';
    protected $description = 'Consulta CNPJ e disponibilidade do banco de licenças para provisionamento gerenciado.';

    public function handle(): int
    {
        if (!Schema::hasTable('company_settings')) {
            $this->error('Cadastro da empresa indisponível.');
            return self::FAILURE;
        }
        $cnpj = preg_replace('/\D/', '', (string) CompanySetting::query()->value('document'));
        if (!preg_match('/^[0-9]{14}$/', $cnpj)
            || (config('instance.cnpj') && config('instance.cnpj') !== $cnpj)) {
            $this->error('CNPJ da instalação não está configurado ou está inconsistente.');
            return self::FAILURE;
        }
        if (!Schema::hasTable('central_license_states')) {
            $this->error('Execute as migrations do ERP antes de vincular a instalação.');
            return self::FAILURE;
        }
        $this->line($cnpj);
        return self::SUCCESS;
    }
}
