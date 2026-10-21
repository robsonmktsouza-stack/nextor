<?php

namespace App\Console\Commands;

use App\Models\CompanySetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only identity/preflight for a trusted deployment operator.
 * Success output is ONLY the 14-digit issuer CNPJ (machine-readable).
 * Failure output is a short, actionable non-secret diagnostic.
 */
final class CentralLicenseIdentity extends Command
{
    protected $signature = 'lumeron:license-identity';
    protected $description = 'Confere identidade empresarial para vinculação segura ao Lumeron Control.';

    public function handle(): int
    {
        if (!Schema::hasTable('company_settings')) {
            $this->error('Cadastro da empresa indisponível neste banco. Confira a conexão do Nextor.');
            return self::FAILURE;
        }

        if (!Schema::hasTable('central_license_states')) {
            $this->error('Migração de licenciamento ainda não aplicada neste banco do Nextor.');
            return self::FAILURE;
        }

        $company = CompanySetting::query()->orderBy('id')->first();
        if (!$company) {
            $this->error('Dados da empresa não foram cadastrados em Configurações > Geral do Nextor.');
            return self::FAILURE;
        }

        $cnpj = preg_replace('/\D/', '', (string) $company->document) ?? '';
        if ($cnpj === '') {
            $this->error('O CNPJ da própria empresa está vazio em Configurações > Geral do Nextor. Salve o CNPJ do emitente antes de vincular.');
            return self::FAILURE;
        }

        if (!preg_match('/^[0-9]{14}$/', $cnpj)) {
            $this->error('O documento em Configurações > Geral não contém 14 dígitos de CNPJ. Confira o cadastro da empresa no Nextor.');
            return self::FAILURE;
        }

        $expected = preg_replace('/\D/', '', (string) config('instance.cnpj', '')) ?? '';
        if ($expected !== '' && !hash_equals($expected, $cnpj)) {
            $this->error('O CNPJ cadastrado em Configurações > Geral é diferente do CNPJ fixado para esta instalação. Revise com o operador; não altere o CNPJ fiscal apenas para liberar a licença.');
            return self::FAILURE;
        }

        $this->line($cnpj);
        return self::SUCCESS;
    }
}
