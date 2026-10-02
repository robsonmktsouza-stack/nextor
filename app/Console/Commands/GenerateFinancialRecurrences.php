<?php

namespace App\Console\Commands;

use App\Services\FinancialRecurrenceService;
use Illuminate\Console\Command;

class GenerateFinancialRecurrences extends Command
{
    protected $signature='finance:generate-recurring {--through= : Gerar até a data YYYY-MM-DD}';
    protected $description='Gera lançamentos financeiros pendentes a partir das recorrências ativas.';

    public function handle(FinancialRecurrenceService $service): int
    {
        $through=$this->option('through')
            ? \Carbon\Carbon::parse((string)$this->option('through'))->startOfDay()
            : today();

        $count=$service->generateDue(null,$through);
        $this->info($count.' lançamento(s) recorrente(s) gerado(s).');

        return self::SUCCESS;
    }
}
