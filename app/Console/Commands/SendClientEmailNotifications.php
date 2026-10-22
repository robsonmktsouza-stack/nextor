<?php
namespace App\Console\Commands;

use App\Services\ClientEmailNotificationService;
use App\Services\InvoiceEmailNotificationService;
use Illuminate\Console\Command;

final class SendClientEmailNotifications extends Command
{
    protected $signature = 'control:client-email-send {--limit=50}';
    protected $description = 'Envia avisos de licenças aos clientes se o envio estiver autorizado.';

    public function handle(ClientEmailNotificationService $service, InvoiceEmailNotificationService $invoices): int
    {
        if (!$service->enabled() && !$invoices->automaticEnabled()) {
            $this->warn('Envio de e-mail desativado ou não configurado.');
            return self::SUCCESS;
        }
        $result = $service->sendPending((int) $this->option('limit'));
        $bills = $invoices->sendPending((int) $this->option('limit'));
        $sent = $result['sent'] + $bills['sent'];
        $failed = $result['failed'] + $bills['failed'];
        $this->info('E-mails enviados: '.$sent.'; falhas: '.$failed.'.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}