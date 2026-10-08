<?php

namespace App\Console\Commands;

use App\Services\Fiscal\ACBr\ACBrNFeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnóstico sem transmitir documentos ou expor certificados e senhas.
 * Executa todas as verificações mesmo que uma delas falhe.
 */
final class ACBrNFeDoctor extends Command
{
    protected $signature = 'acbr:nfe-doctor';
    protected $description = 'Diagnóstico completo da ACBrLibNFe sem transmitir notas';

    public function handle(): int
    {
        $path = (string) config('services.acbr_nfe.library_path', '');
        $ini = (string) config('services.acbr_nfe.config_path', '');

        $this->line('PHP: '.PHP_VERSION.' | '.PHP_OS_FAMILY.' | '.(PHP_INT_SIZE * 8).' bits');
        $this->line('SAPI: '.PHP_SAPI);
        $this->line('FFI: '.(extension_loaded('ffi') ? 'habilitada' : 'desabilitada'));
        $this->line('ffi.enable: '.(ini_get('ffi.enable') === false ? 'indefinido' : ini_get('ffi.enable')));
        $this->line('DLL configurada: '.($path === '' ? '(vazia)' : $path));
        $this->line('DLL encontrada: '.($path !== '' && is_file($path) ? 'sim' : 'não'));
        $this->line('INI configurado: '.($ini === '' ? '(vazio)' : $ini));
        $this->line('INI existente: '.($ini !== '' && is_file($ini) ? 'sim' : 'não; será criado durante a inicialização'));
        $this->line('Pasta INI gravável: '.($ini !== '' && is_dir(dirname($ini)) && is_writable(dirname($ini)) ? 'sim' : 'não'));

        $service = new ACBrNFeService();
        $failures = 0;

        foreach ([
            'NFE_Inicializar' => fn () => $service->initialize() === null ? 'OK' : 'OK',
            'NFE_Nome' => fn () => $service->name(),
            'NFE_Versao' => fn () => $service->version(),
        ] as $label => $probe) {
            try {
                $this->line($label.': '.$probe());
            } catch (Throwable $e) {
                $failures++;
                $this->components->error($label.': '.$e->getMessage());
            }
        }

        try {
            $service->close();
            $this->line('NFE_Finalizar: executado');
        } catch (Throwable $e) {
            $failures++;
            $this->components->error('NFE_Finalizar: '.$e->getMessage());
        }

        $this->line('Falhas: '.$failures);
        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
