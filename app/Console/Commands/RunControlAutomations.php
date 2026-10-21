<?php

namespace App\Console\Commands;

use App\Services\AutomationRunner;
use Illuminate\Console\Command;

final class RunControlAutomations extends Command
{
    protected $signature = 'control:automations-run';
    protected $description = 'Executa regras internas de vencimento e conectividade de forma idempotente.';

    public function handle(AutomationRunner $runner): int
    {
        $summary = $runner->run();
        $this->info('Regras ativas: '.$summary['rules'].'; novos avisos: '.$summary['new_events'].'.');
        return self::SUCCESS;
    }
}
