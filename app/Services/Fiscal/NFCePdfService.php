<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\FiscalDocumentJob;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Usa o próprio DANFE do NEXTOR (HTML/CSS oficial) como fonte única.
 * O Chrome instalado no servidor converte a página em PDF. O leitor
 * nativo do navegador abre o PDF sem alterar o layout do documento.
 *
 * Nenhuma API da SEFAZ é chamada, e a ACBr não gera outro modelo visual.
 * Executado apenas pelo worker fiscal, nunca na requisição HTTP.
 */
class NFCePdfService
{
    private const LAYOUT_VERSION='nextor-original-v1';

    public function paper(): string
    {
        $previous=(string)AppSetting::value('pdv','receipt_width','80');
        $configured=(string)AppSetting::value('printing','nfce_paper',$previous);
        return in_array($configured,['58','80','a4'],true) ? $configured : '80';
    }

    public function storedPath(FiscalDocumentJob $document, string $authorizedXml): string
    {
        $digest=substr(hash('sha256',$authorizedXml),0,20);
        return 'fiscal/nfce/'.$document->id.'/danfe-'.self::LAYOUT_VERSION
            .'-'.$this->paper().'-'.$digest.'.pdf';
    }

    public function existingPath(FiscalDocumentJob $document, string $authorizedXml): ?string
    {
        $path=$this->storedPath($document,$authorizedXml);
        return Storage::disk('local')->exists($path) ? $path : null;
    }

    public function generate(FiscalDocumentJob $document, string $authorizedXml): string
    {
        if ($existing=$this->existingPath($document,$authorizedXml)) {
            return $existing;
        }

        $pdf=$this->render($document,$authorizedXml);
        if (!str_starts_with($pdf,'%PDF-') || !str_contains(substr($pdf,-1024),'%%EOF')) {
            throw new RuntimeException('O PDF do DANFE não foi gerado corretamente.');
        }

        $path=$this->storedPath($document,$authorizedXml);
        if (!Storage::disk('local')->put($path,$pdf)) {
            throw new RuntimeException('Não foi possível salvar o DANFE PDF.');
        }
        return $path;
    }

    /**
     * Gera exatamente o template já existente, com os dados obtidos
     * do nfeProc autorizado e o CSS que o NEXTOR usava originalmente.
     */
    public function renderHtml(FiscalDocumentJob $document, string $authorizedXml): string
    {
        $danfe=app(NFCeDanfeService::class)->parse($authorizedXml,$document);
        return view('fiscal.nfce-danfe',[
            'danfe'=>$danfe,
            'paper'=>$this->paper(),
            'pdfExport'=>true,
        ])->render();
    }

    public function render(FiscalDocumentJob $document, string $authorizedXml): string
    {
        if ($document->document_type!=='nfce' || $document->status!=='authorized') {
            throw new RuntimeException('O DANFE somente pode ser gerado para NFC-e autorizada.');
        }

        $chrome=$this->chromeExecutable();
        if ($chrome===null) {
            throw new RuntimeException('Chrome ou Chromium não encontrado no servidor para gerar o DANFE PDF.');
        }
        if (!function_exists('proc_open')) {
            throw new RuntimeException('O PHP não permite executar o gerador local de PDF.');
        }

        $base=storage_path('app/nfce-pdf-runtime');
        if (!is_dir($base) && !mkdir($base,0700,true) && !is_dir($base)) {
            throw new RuntimeException('Não foi possível preparar a pasta de impressão fiscal.');
        }
        $working=$base.'/danfe-'.$document->id.'-'.bin2hex(random_bytes(8));
        if (!mkdir($working,0700)) {
            throw new RuntimeException('Não foi possível preparar a impressão fiscal.');
        }

        $input=$working.'/documento.html';
        $output=$working.'/documento.pdf';
        $profile=$working.'/chrome-profile';

        try {
            if (file_put_contents($input,$this->renderHtml($document,$authorizedXml))===false) {
                throw new RuntimeException('Não foi possível preparar o DANFE original.');
            }

            $command=[
                $chrome,
                '--headless=new',
                '--disable-gpu',
                '--disable-background-networking',
                '--disable-extensions',
                '--no-first-run',
                '--no-default-browser-check',
                '--no-pdf-header-footer',
                '--virtual-time-budget=2200',
                '--user-data-dir='.$profile,
                '--print-to-pdf='.$output,
                $this->fileUrl($input),
            ];
            if (PHP_OS_FAMILY!=='Windows' && function_exists('posix_geteuid') && posix_geteuid()===0) {
                array_splice($command,-1,0,['--no-sandbox']);
            }

            $descriptors=[
                0=>['pipe','r'],
                1=>['pipe','w'],
                2=>['pipe','w'],
            ];
            $process=proc_open($command,$descriptors,$pipes,$working);
            if (!is_resource($process)) {
                throw new RuntimeException('Não foi possível iniciar o gerador do DANFE PDF.');
            }

            fclose($pipes[0]);
            stream_set_blocking($pipes[1],false);
            stream_set_blocking($pipes[2],false);
            $logs='';
            $timedOut=false;
            $start=microtime(true);

            do {
                $status=proc_get_status($process);
                $logs.=substr(stream_get_contents($pipes[1]) ?: '',-1024);
                $logs.=substr(stream_get_contents($pipes[2]) ?: '',-1024);
                $logs=substr($logs,-3000);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true)-$start>18) {
                    $timedOut=true;
                    proc_terminate($process);
                    break;
                }
                usleep(150000);
            } while (true);

            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            if ($timedOut || !is_file($output)) {
                throw new RuntimeException('Não foi possível gerar o PDF com o Chrome local. '.substr($logs,-600));
            }

            $pdf=file_get_contents($output);
            if ($pdf===false || !str_starts_with($pdf,'%PDF-') || !str_contains(substr($pdf,-1024),'%%EOF')) {
                throw new RuntimeException('O Chrome retornou um arquivo PDF inválido.');
            }
            return $pdf;
        } finally {
            $this->removeDirectory($working);
        }
    }

    private function chromeExecutable(): ?string
    {
        $local=(string)(getenv('LOCALAPPDATA') ?: '');
        $program=(string)(getenv('PROGRAMFILES') ?: '');
        $programX86=(string)(getenv('PROGRAMFILES(X86)') ?: '');
        $configured=(string)config('services.chromium.path','');

        $candidates=array_filter([
            $configured,
            $program ? $program.'/Google/Chrome/Application/chrome.exe' : '',
            $programX86 ? $programX86.'/Google/Chrome/Application/chrome.exe' : '',
            $local ? $local.'/Google/Chrome/Application/chrome.exe' : '',
            $program ? $program.'/Microsoft/Edge/Application/msedge.exe' : '',
            $programX86 ? $programX86.'/Microsoft/Edge/Application/msedge.exe' : '',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/opt/google/chrome/chrome',
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function fileUrl(string $path): string
    {
        $normalized=str_replace('\\','/',$path);
        $parts=explode('/',$normalized);
        $encoded=implode('/',array_map(static function (string $part): string {
            // Windows file:///C:/... must preserve the drive colon.
            return preg_match('/^[A-Za-z]:$/',$part) ? $part : rawurlencode($part);
        },$parts));
        return str_starts_with($normalized,'/') ? 'file://'.$encoded : 'file:///'.$encoded;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $contents=new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($contents as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
