<?php

namespace App\Console\Commands;

use App\Services\InvoiceCycleService;
use Illuminate\Console\Command;

final class GenerateControlInvoices extends Command
{
    protected $signature = 'control:invoices-prepare {--days=7}';
    protected $description = 'Prepara faturas recorrentes em rascunho, sem enviar cobranças.';

    public function handle(InvoiceCycleService $service): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 30],
        ]);
        if ($days === false) {
            $this->error('Informe uma antecedência entre 0 e 30 dias.');
            return self::FAILURE;
        }
        $result = $service->prepareNextDrafts($days);
        $this->info('Faturas preparadas: '.$result['created'].'.');
        return self::SUCCESS;
    }
}
