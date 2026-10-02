<?php

namespace App\Console\Commands;

use App\Services\AccountingExportService;
use Illuminate\Console\Command;

class GenerateAccountingExport extends Command
{
    protected $signature='nextor:accounting-export {month?} {--force}';
    protected $description='Gera a exportação contábil mensal do Nextor.';

    public function handle(AccountingExportService $exports): int
    {
        if(!$this->option('force') && !$exports->automaticEnabled()) {
            $this->line('Exportação automática desativada.');
            return self::SUCCESS;
        }

        $month=(string)($this->argument('month') ?: now()->subMonthNoOverflow()->format('Y-m'));

        try {
            \Carbon\Carbon::createFromFormat('Y-m',$month)->startOfMonth();
        } catch (\Throwable) {
            $this->error('Competência inválida. Use AAAA-MM.');
            return self::FAILURE;
        }

        $path=$exports->generate($month);
        $this->info('Exportação criada: '.$path);

        return self::SUCCESS;
    }
}
