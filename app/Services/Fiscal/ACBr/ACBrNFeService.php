<?php

namespace App\Services\Fiscal\ACBr;

use FFI;
use RuntimeException;
use Throwable;

/**
 * Bootstrap da ACBrLibNFe MT Cdecl x64. Não compartilhe a mesma instância
 * entre empresas ou requisições simultâneas sem isolamento por processo.
 */
final class ACBrNFeService
{
    private ?FFI $lib = null;
    private $handle = null;
    private bool $initialized = false;

    public function __construct(
        private readonly ?string $libraryPath = null,
        private readonly ?string $configPath = null,
    ) {}

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }
        if (!extension_loaded('ffi') || !class_exists(FFI::class)) {
            throw new RuntimeException('Extensão PHP FFI não disponível; habilite ffi.enable para o processo fiscal.');
        }

        $path = $this->libraryPath ?: (string) config('services.acbr_nfe.library_path', '');
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Biblioteca ACBrNFe não encontrada em ACBr_NFE_LIBRARY_PATH.');
        }

        $ini = $this->configPath ?: (string) config('services.acbr_nfe.config_path', '');
        if ($ini === '') {
            throw new RuntimeException('Defina ACBr_NFE_CONFIG_PATH com um INI exclusivo de diagnóstico.');
        }
        $parent = dirname($ini);
        if (!is_dir($parent) || !is_writable($parent)) {
            throw new RuntimeException('Diretório de configuração ACBr inexistente ou sem permissão de escrita.');
        }

        // ACBrLib MT exige ponteiro de instância em TODAS as chamadas.
        // Para Windows, usar a DLL MT/Cdecl, nunca StdCall ou ST.
        $header = <<<'CDEF'
            int NFE_Inicializar(void **libHandle, const char *eArqConfig, const char *eChaveCrypt);
            int NFE_Finalizar(void *libHandle);
            int NFE_Nome(void *libHandle, char *sNome, int *esTamanho);
            int NFE_Versao(void *libHandle, char *sVersao, int *esTamanho);
            int NFE_UltimoRetorno(void *libHandle, char *sMensagem, int *esTamanho);
        CDEF;

        $this->lib = FFI::cdef($header, $path);
        $this->handle = FFI::new('void *');
        $status = $this->lib->NFE_Inicializar(FFI::addr($this->handle), $ini, '');
        if ($status !== 0) {
            $reason = $this->lastReturn();
            $this->lib = null;
            $this->handle = null;
            throw new RuntimeException("ACBr NFE_Inicializar falhou ({$status}): {$reason}");
        }
        $this->initialized = true;
    }

    public function name(): string
    {
        $this->initialize();
        $size = FFI::new('int');
        $size->cdata = 4096;
        $buffer = FFI::new('char[4096]');
        $status = $this->lib->NFE_Nome($this->handle, $buffer, FFI::addr($size));
        if ($status !== 0) {
            throw new RuntimeException("ACBr NFE_Nome falhou ({$status}): ".$this->lastReturn());
        }
        return FFI::string($buffer);
    }

    public function version(): string
    {
        $this->initialize();
        $size = FFI::new('int');
        $size->cdata = 4096;
        $buffer = FFI::new('char[4096]');
        $status = $this->lib->NFE_Versao($this->handle, $buffer, FFI::addr($size));

        if ($status !== 0) {
            throw new RuntimeException("ACBr NFE_Versao falhou ({$status}): ".$this->lastReturn());
        }
        if ($size->cdata >= 4096) {
            $length = $size->cdata + 1;
            if ($length > 1048576) {
                throw new RuntimeException('Resposta ACBr excede o limite permitido.');
            }
            $buffer = FFI::new("char[{$length}]");
            $size->cdata = $length;
            $status = $this->lib->NFE_Versao($this->handle, $buffer, FFI::addr($size));
            if ($status !== 0) {
                throw new RuntimeException("ACBr NFE_Versao falhou ({$status}).");
            }
        }
        return FFI::string($buffer);
    }

    private function lastReturn(): string
    {
        if ($this->lib === null) {
            return 'biblioteca indisponível';
        }
        try {
            $size = FFI::new('int');
            $size->cdata = 4096;
            $buffer = FFI::new('char[4096]');
            $status = $this->lib->NFE_UltimoRetorno($this->handle, $buffer, FFI::addr($size));
            if ($size->cdata >= 4096 && $size->cdata < 1048576) {
                $capacity = $size->cdata + 1;
                $buffer = FFI::new("char[{$capacity}]");
                $size->cdata = $capacity;
                $status = $this->lib->NFE_UltimoRetorno($buffer, FFI::addr($size));
            }
            $message = FFI::string($buffer);
            return $message !== '' ? $message : "NFE_UltimoRetorno retornou {$status} sem mensagem";
        } catch (Throwable) {
            return 'não foi possível consultar o último retorno';
        }
    }

    public function close(): void
    {
        if ($this->initialized && $this->lib !== null) {
            $this->lib->NFE_Finalizar($this->handle);
        }
        $this->initialized = false;
        $this->lib = null;
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
