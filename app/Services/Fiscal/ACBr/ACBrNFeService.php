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
            int NFE_LimparListaEventos(void *libHandle);
            int NFE_CarregarEventoINI(void *libHandle, const char *eArquivoOuINI);
            int NFE_EnviarEvento(void *libHandle, int idLote, char *sResposta, int *esTamanho);
            int NFE_CarregarINI(void *libHandle, const char *eArquivoOuINI);
            int NFE_CarregarXML(void *libHandle, const char *eArquivoOuXML);
            int NFE_SalvarPDF(void *libHandle, char *sResposta, int *esTamanho);
            int NFE_Assinar(void *libHandle);
            int NFE_Validar(void *libHandle);
            int NFE_ValidarRegrasdeNegocios(void *libHandle, char *sResposta, int *esTamanho);
            int NFE_ObterXml(void *libHandle, int AIndex, char *sResposta, int *esTamanho);
            int NFE_Enviar(void *libHandle, int ALote, _Bool AImprimir, _Bool ASincrono, _Bool AZipado, char *sResposta, int *esTamanho);
            int NFE_Consultar(void *libHandle, const char *eChaveOuNFe, _Bool AExtrairEventos, char *sResposta, int *esTamanho);
            int NFE_DistribuicaoDFePorUltNSU(void *libHandle, int AcUFAutor, const char *eCNPJCPF, const char *eUltNSU, char *sResposta, int *esTamanho);
            int NFE_Cancelar(void *libHandle, const char *eChave, const char *eJustificativa, const char *eCNPJ, int ALote, char *sResposta, int *esTamanho);
            int NFE_Inutilizar(void *libHandle, const char *ACNPJ, const char *AJustificativa, int Ano, int Modelo, int Serie, int NumeroInicial, int NumeroFinal, char *sResposta, int *esTamanho);
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

    public function loadXml(string $xml): void
    {
        $this->execute('NFE_LimparLista');
        $this->execute('NFE_CarregarXML', [$xml]);
    }

    /**
     * A ACBr retorna o PDF em Base64; nunca cria arquivo publico.
     */
    public function savePdf(): string
    {
        $encoded = $this->response('NFE_SalvarPDF');
        $pdf = base64_decode(trim($encoded), true);
        if ($pdf === false || !str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('A ACBr não retornou um DANFE PDF válido.');
        }
        return $pdf;
    }

    public function sign(): void
    {
        $this->execute('NFE_Assinar');
    }

    public function validateXml(): void
    {
        $this->execute('NFE_Validar');
    }

    /**
     * Local ACBr business-rule preflight. A native return code of zero does not
     * prove there were no fiscal findings in the returned text.
     */
    public function validateBusinessRules(): void
    {
        self::assertBusinessRulesClear($this->response('NFE_ValidarRegrasdeNegocios'));
    }

    /** Pure guard used in tests without loading a Windows DLL on CI Linux. */
    public static function assertBusinessRulesClear(string $response): void
    {
        $findings=trim($response);
        if($findings!=='' && preg_match('/^(?:ok|sem\\s+(?:erros|inconsist[eê]ncias))\\.?$/iu',$findings)!==1){
            throw new RuntimeException('ACBr apontou regras fiscais pendentes: '.mb_substr($findings,0,1000));
        }
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

    /**
     * Eventos fiscais alteram o estado na SEFAZ: chamar UMA ÚNICA VEZ.
     * Buffer insuficiente e timeout deixam resultado indeterminado;
     * nunca repetir automaticamente e nunca confundir sucesso FFI com cStat=135.
     */
    private function fiscalEventOnce(string $method, array $args): string
    {
        $this->initialize();
        $capacity=1048576;
        $buffer=FFI::new("char[{$capacity}]");
        $size=FFI::new('int');
        $size->cdata=$capacity;
        $arguments=array_merge([$this->handle],$args,[$buffer,FFI::addr($size)]);
        $status=$this->lib->$method(...$arguments);
        if ($size->cdata >= $capacity) {
            throw new RuntimeException("Retorno {$method} excedeu 1 MB; resultado indeterminado. Consulte a SEFAZ antes de qualquer nova tentativa.");
        }
        if ($status !== 0) {
            // Alguns builds retornam -10 mas ainda disponibilizam o cStat individual.
            // Nunca repetir o evento nem aceitar cStat=135 com erro nativo.
            if ($method === 'NFE_EnviarEvento') {
                $candidate=FFI::string($buffer);
                if ($this->hasIndividualRejection($candidate)) return $candidate;
                $last=$this->lastReturn();
                if ($this->hasIndividualRejection($last)) return $last;
                throw new RuntimeException("ACBr {$method} falhou ({$status}): ".$last);
            }
            throw new RuntimeException("ACBr {$method} falhou ({$status}); o evento pode ter sido recebido. ".$this->lastReturn());
        }
        return FFI::string($buffer);
    }

    private function hasIndividualRejection(string $raw): bool
    {
        $start=stripos($raw,'[EVENTO001]');
        if ($start===false) return false;
        $section=substr($raw,$start+strlen('[EVENTO001]'));
        if (preg_match('/\\R\\[[^]\\r\\n]+\\]/',$section,$end,PREG_OFFSET_CAPTURE)) {
            $section=substr($section,0,$end[0][1]);
        }
        if (!preg_match('/(?mi)^CStat\\s*=\\s*([0-9]{3})\\s*$/',$section,$match)) return false;
        return !in_array($match[1], ['135','136','573'],true);
    }

    /**
     * A DLL aceita caminho ou conteúdo do INI. Para evitar ambiguidades no
     * parser da biblioteca nativa, gravamos um arquivo real, sem BOM, privado
     * e exclusivo para esta instância. A carga NÃO envia evento à SEFAZ.
     */
    public function prepareRecipientEvent(string $eventIni): void
    {
        if (!str_starts_with($eventIni,"[EVENTO]\n")
            || strlen($eventIni)>8192 || str_contains($eventIni,"\r")
            || !str_contains($eventIni,"[EVENTO001]\n")) {
            throw new RecipientEventPreparationException('INI de manifestação inválido.');
        }
        try {
            $this->initialize();
        } catch (Throwable $error) {
            throw new RecipientEventPreparationException($error->getMessage(),0,$error);
        }
        $folder=dirname($this->configPath ?: (string)config('services.acbr_nfe.config_path',''));
        if (!is_dir($folder) || !is_writable($folder)) {
            throw new RecipientEventPreparationException('Diretório de eventos indisponível.');
        }
        $path=$folder.DIRECTORY_SEPARATOR.'manifest-'.bin2hex(random_bytes(12)).'.ini';
        try {
            $contents=str_replace("\n","\r\n",$eventIni);
            if (file_put_contents($path,$contents,LOCK_EX)!==strlen($contents)) {
                throw new RuntimeException('Não foi possível gravar INI.');
            }
            @chmod($path,0600);
            $this->execute('NFE_LimparListaEventos');
            $this->execute('NFE_CarregarEventoINI',[$path]);
        } catch (Throwable $error) {
            throw new RecipientEventPreparationException($error->getMessage(),0,$error);
        } finally {
            if (is_file($path)) @unlink($path);
        }
    }

    /** Evento do destinatário só é transmitido após preparação bem-sucedida. */
    public function sendRecipientEvent(string $eventIni): string
    {
        $this->prepareRecipientEvent($eventIni);
        return $this->fiscalEventOnce('NFE_EnviarEvento',[1]);
    }

    public function cancel(string $key,string $reason,string $cnpj,int $lot): string
    {
        if (!preg_match('/^\\d{44}$/',$key) || !preg_match('/^\\d{14}$/',$cnpj) || mb_strlen($reason)<15) {
            throw new RuntimeException('Chave, CNPJ ou justificativa inválidos para cancelamento.');
        }
        return $this->fiscalEventOnce('NFE_Cancelar',[$key,$reason,$cnpj,$lot]);
    }

    public function inutilize(string $cnpj,string $reason,int $year,int $series,int $first,int $last,int $model=65): string
    {
        if (!preg_match('/^\\d{14}$/',$cnpj) || mb_strlen($reason)<15 || mb_strlen($reason)>255
            || $year<2020 || $year>2100 || !in_array($model,[55,65],true)
            || $series<0 || $series>999 || $first<1 || $last<$first || $last>999999999 || $last-$first>999) {
            throw new RuntimeException('Identificação, modelo ou faixa de inutilização inválida.');
        }
        // NFC-e mantém modelo 65 por padrão. NF-e deve passar 55 explicitamente.
        return $this->fiscalEventOnce('NFE_Inutilizar',[$cnpj,$reason,$year%100,$model,$series,$first,$last]);
    }

    /**
     * Consulta em leitura; aceita somente o último NSU persistido da própria empresa.
     * O método ACBr pode acessar a SEFAZ, portanto não executar em loop apertado.
     */
    public function distributeLastNsu(int $ufCode,string $cnpj,string $lastNsu): string
    {
        if($ufCode<11 || $ufCode>53 || !preg_match('/^\d{14}$/',$cnpj)
            || !preg_match('/^\d{1,15}$/',$lastNsu)) {
            throw new RuntimeException('UF, CNPJ ou NSU inválido para distribuição de NF-e.');
        }
        // Uma nova consulta ao DF-e pode ser contada pela SEFAZ mesmo se o
        // retorno não couber no buffer. Não reexecutar em caso de truncamento.
        $this->initialize();
        $capacity=8388608;
        $buffer=FFI::new("char[{$capacity}]");
        $length=FFI::new('int');
        $length->cdata=$capacity;
        $result=$this->lib->NFE_DistribuicaoDFePorUltNSU(
            $this->handle,$ufCode,$cnpj,str_pad($lastNsu,15,'0',STR_PAD_LEFT),
            $buffer,FFI::addr($length)
        );
        if($length->cdata>=$capacity) {
            throw new RuntimeException('Resposta da distribuição excedeu o tamanho permitido; não repetir automaticamente.');
        }
        if($result!==0) {
            throw new RuntimeException('Consulta de documentos recebidos indisponível: '.$this->lastReturn());
        }
        return FFI::string($buffer);
    }

    /** Consulta somente leitura com extração de eventos, sem alterar NSU. */
    public function consultByKeyWithEvents(string $accessKey): string
    {
        if (preg_match('/^\d{44}$/',$accessKey)!==1) {
            throw new RuntimeException('Chave inválida para consultar eventos.');
        }
        return $this->response('NFE_Consultar',[$accessKey,true]);
    }

    public function consultByKey(string $accessKey): string
    {
        if (preg_match('/^\d{44}$/', $accessKey) !== 1) {
            throw new RuntimeException('Chave de acesso inválida para consulta.');
        }
        // Consulta é exclusivamente leitura: jamais implica outra NFE_Enviar.
        return $this->response('NFE_Consultar', [$accessKey, false]);
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
