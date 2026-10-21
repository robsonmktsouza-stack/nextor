<?php
namespace App\Console\Commands;

use App\Services\ClientEmailNotificationService;
use Illuminate\Console\Command;

final class SendClientEmailNotifications extends Command
{
    protected $signature = 'control:client-email-send {--limit=50}';
    protected $description = 'Envia avisos de licenças aos clientes se o envio estiver autorizado.';

    public function handle(ClientEmailNotificationService $service): int
    {
        if (!$service->enabled()) {
            $this->warn('Envio de e-mail desativado ou não configurado.');
            return self::SUCCESS;
        }
        $result = $service->sendPending((int) $this->option('limit'));
        $this->info('E-mails enviados: '.$result['sent'].'; falhas: '.$result['failed'].'.');
        return $result['failed'] ? self::FAILURE : self::SUCCESS;
    }
}