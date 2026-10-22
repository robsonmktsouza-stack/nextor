<?php

namespace App\Console\Commands;

use App\Services\InvoiceCycleService;
use App\Models\ControlSetting;
use Illuminate\Console\Command;

final class GenerateControlInvoices extends Command
{
    protected $signature = 'control:invoices-prepare {--days=}';
    protected $description = 'Prepara faturas recorrentes em rascunho, sem enviar cobranças.';

    public function handle(InvoiceCycleService $service): int
    {
        if (!ControlSetting::recurringEnabled()) {
            $this->info('Preparação automática de faturas desativada.');
            return self::SUCCESS;
        }
        $rawDays = $this->option('days');
        $days = filter_var($rawDays === null ? ControlSetting::invoiceDays() : $rawDays, FILTER_VALIDATE_INT, [
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
