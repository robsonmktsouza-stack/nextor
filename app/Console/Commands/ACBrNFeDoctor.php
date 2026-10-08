<?php

namespace App\Console\Commands;

use App\Services\Fiscal\ACBr\ACBrNFeService;
use Illuminate\Console\Command;
use Throwable;

final class ACBrNFeDoctor extends Command
{
    protected $signature = 'acbr:nfe-doctor';
    protected $description = 'Verifica a inicialização local da ACBrLibNFe sem transmitir notas';

    public function handle(): int
    {
        $service = new ACBrNFeService();

        try {
            $version = $service->version();
            $this->components->info('ACBrLibNFe inicializada. Versão: '.$version);
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());
            return self::FAILURE;
        } finally {
            $service->close();
        }
    }
}
