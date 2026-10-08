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
            int NFE_ConfigLerValor(void *libHandle, const char *eSessao, const char *eChave, char *sValor, int *esTamanho);
            int NFE_ConfigGravarValor(void *libHandle, const char *eSessao, const char *eChave, const char *eValor);
            int NFE_StatusServico(void *libHandle, char *sResposta, int *esTamanho);
            int NFE_LimparLista(void *libHandle);
            int NFE_CarregarINI(void *libHandle, const char *eArquivoOuINI);
            int NFE_Assinar(void *libHandle);
            int NFE_Validar(void *libHandle);
            int NFE_ObterXml(void *libHandle, int AIndex, char *sResposta, int *esTamanho);
            int NFE_Enviar(void *libHandle, int ALote, _Bool AImprimir, _Bool ASincrono, _Bool AZipado, char *sResposta, int *esTamanho);
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

    public function setConfig(string $section, string $key, string $value): void
    {
        $this->initialize();
        $status = $this->lib->NFE_ConfigGravarValor($this->handle, $section, $key, $value);
        if ($status !== 0) {
            throw new RuntimeException("ACBr não configurou {$section}.{$key} ({$status}): ".$this->lastReturn());
        }
    }

    public function statusServico(): string
    {
        $this->initialize();
        $length = 65536;
        $buffer = FFI::new("char[{$length}]");
        $size = FFI::new('int');
        $size->cdata = $length;
        $status = $this->lib->NFE_StatusServico($this->handle, $buffer, FFI::addr($size));
        if ($status !== 0) {
            throw new RuntimeException("Consulta SEFAZ falhou ({$status}): ".$this->lastReturn());
        }
        if ($size->cdata >= $length) {
            throw new RuntimeException('Resposta SEFAZ excedeu o buffer de 64 KB.');
        }
        return FFI::string($buffer);
    }

    private function execute(string $method, array $args = []): void
    {
        $this->initialize();
        $status = $this->lib->$method($this->handle, ...$args);
        if ($status !== 0) {
            throw new RuntimeException("ACBr {$method} falhou ({$status}): ".$this->lastReturn());
        }
    }

    private function response(string $method, array $args = []): string
    {
        $this->initialize();
        $capacity = 65536;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $buffer = FFI::new("char[{$capacity}]");
            $size = FFI::new('int');
            $size->cdata = $capacity;
            $arguments = array_merge([$this->handle], $args, [$buffer, FFI::addr($size)]);
            $status = $this->lib->$method(...$arguments);
            if ($size->cdata >= $capacity && $size->cdata < 8388608) {
                $capacity = $size->cdata + 1;
                continue;
            }
            if ($status !== 0) {
                throw new RuntimeException("ACBr {$method} falhou ({$status}): ".$this->lastReturn());
            }
            return FFI::string($buffer);
        }
        throw new RuntimeException("Resposta ACBr {$method} excedeu o limite.");
    }

    public function loadIni(string $ini): void
    {
        $this->execute('NFE_LimparLista');
        $this->execute('NFE_CarregarINI', [$ini]);
    }

    public function sign(): void
    {
        $this->execute('NFE_Assinar');
    }

    public function validateXml(): void
    {
        $this->execute('NFE_Validar');
    }

    public function getXml(): string
    {
        return $this->response('NFE_ObterXml', [0]);
    }

    public function send(int $lot): string
    {
        // CRÍTICO: NFE_Enviar é uma operação de efeito externo. NUNCA
        // reinvocá-la por truncamento de buffer, timeout ou falha de retorno.
        // A rotina response() é reservada para chamadas que possam ser
        // repetidas com segurança (por exemplo, NFE_ObterXml).
        $this->initialize();
        $capacity = 1048576;
        $buffer = FFI::new("char[{$capacity}]");
        $size = FFI::new('int');
        $size->cdata = $capacity;

        $status = $this->lib->NFE_Enviar(
            $this->handle,
            $lot,
            false,
            true,
            false,
            $buffer,
            FFI::addr($size)
        );
        if ($size->cdata >= $capacity) {
            throw new RuntimeException('Resposta da transmissão NFC-e excedeu 1 MB; situação fiscal indeterminada. Não reenviar sem consulta pela chave.');
        }
        if ($status !== 0) {
            throw new RuntimeException("ACBr NFE_Enviar falhou ({$status}): ".$this->lastReturn());
        }

        return FFI::string($buffer);
    }

    public function readConfig(string $section, string $key): string
    {
        $this->initialize();
        $size = FFI::new('int');
        $size->cdata = 4096;
        $buffer = FFI::new('char[4096]');
        $status = $this->lib->NFE_ConfigLerValor($this->handle, $section, $key, $buffer, FFI::addr($size));
        if ($status !== 0) {
            throw new RuntimeException("ACBr NFE_ConfigLerValor falhou ({$status}): ".$this->lastReturn());
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
                $status = $this->lib->NFE_UltimoRetorno($this->handle, $buffer, FFI::addr($size));
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
