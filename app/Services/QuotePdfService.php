<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Sale;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class QuotePdfService
{
    public function renderHtml(Sale $quote): string
    {
        if (!in_array($quote->operation_type,['quote','sale'],true)) {
            throw new RuntimeException('Este documento não é um orçamento.');
        }

        $quote->loadMissing(['items.product','customer','user','payments']);
        $company=CompanySetting::current();
        $logo=null;
        if ($company->logo_path && Storage::disk('public')->exists($company->logo_path)) {
            $disk=Storage::disk('public');
            $mime=$disk->mimeType($company->logo_path);
            $size=$disk->size($company->logo_path);
            if (in_array($mime,['image/png','image/jpeg','image/webp'],true) && $size<=4194304) {
                $logo='data:'.$mime.';base64,'.base64_encode($disk->get($company->logo_path));
            }
        }

        return view('sales.quote-pdf',[
            'quote'=>$quote,
            'company'=>$company,
            'logo'=>$logo,
        ])->render();
    }

    public function render(Sale $quote): string
    {
        $chrome=$this->chromeExecutable();
        if ($chrome===null || !function_exists('proc_open')) {
            throw new RuntimeException('Para gerar o PDF, instale o Chrome ou Edge no servidor e habilite proc_open no PHP.');
        }

        $root=storage_path('app/quote-pdf-runtime');
        if (!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) {
            throw new RuntimeException('Não foi possível preparar a pasta temporária do orçamento.');
        }
        $directory=$root.'/quote-'.$quote->id.'-'.bin2hex(random_bytes(8));
        if (!mkdir($directory,0700)) {
            throw new RuntimeException('Não foi possível preparar a impressão do orçamento.');
        }

        $html=$directory.'/quote.html';
        $output=$directory.'/quote.pdf';
        try {
            if (file_put_contents($html,$this->renderHtml($quote))===false) {
                throw new RuntimeException('Não foi possível preparar o orçamento.');
            }

            $args=[
                $chrome,
                '--headless=new',
                '--disable-gpu',
                '--disable-background-networking',
                '--disable-extensions',
                '--no-first-run',
                '--no-default-browser-check',
                '--no-pdf-header-footer',
                '--virtual-time-budget=1800',
                '--user-data-dir='.$directory.'/chrome-profile',
                '--print-to-pdf='.$output,
                $this->fileUrl($html),
            ];
            if (PHP_OS_FAMILY!=='Windows' && function_exists('posix_geteuid') && posix_geteuid()===0) {
                array_splice($args,-1,0,['--no-sandbox']);
            }
            $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$directory);
            if (!is_resource($process)) {
                throw new RuntimeException('Não foi possível executar o gerador de PDF.');
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1],false);
            stream_set_blocking($pipes[2],false);
            $start=microtime(true);
            $timedOut=false;
            do {
                $status=proc_get_status($process);
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                if (!$status['running']) break;
                if (microtime(true)-$start>20) {
                    proc_terminate($process);
                    $timedOut=true;
                    break;
                }
                usleep(100000);
            } while (true);

            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            if ($timedOut || !is_file($output)) {
                throw new RuntimeException('O Chrome/Edge não conseguiu gerar o PDF do orçamento.');
            }
            $pdf=file_get_contents($output);
            if ($pdf===false || !str_starts_with($pdf,'%PDF-') || !str_contains(substr($pdf,-1024),'%%EOF')) {
                throw new RuntimeException('O arquivo PDF do orçamento foi gerado incorretamente.');
            }
            return $pdf;
        } finally {
            $iterator=new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                if ($entry->isDir() && !$entry->isLink()) @rmdir($entry->getPathname());
                else @unlink($entry->getPathname());
            }
            @rmdir($directory);
        }
    }

    private function chromeExecutable(): ?string
    {
        $program=(string)(getenv('PROGRAMFILES') ?: '');
        $programX86=(string)(getenv('PROGRAMFILES(X86)') ?: '');
        $local=(string)(getenv('LOCALAPPDATA') ?: '');
        foreach (array_filter([
            (string)config('services.chromium.path',''),
            $program ? $program.'/Google/Chrome/Application/chrome.exe' : '',
            $programX86 ? $programX86.'/Google/Chrome/Application/chrome.exe' : '',
            $local ? $local.'/Google/Chrome/Application/chrome.exe' : '',
            $program ? $program.'/Microsoft/Edge/Application/msedge.exe' : '',
            $programX86 ? $programX86.'/Microsoft/Edge/Application/msedge.exe' : '',
            '/usr/bin/google-chrome','/usr/bin/chromium','/usr/bin/chromium-browser',
            '/opt/google/chrome/chrome',
        ]) as $candidate) {
            if (is_file($candidate)) return $candidate;
        }
        return null;
    }

    private function fileUrl(string $path): string
    {
        $normalized=str_replace('\\','/',$path);
        $parts=explode('/',$normalized);
        $escaped=implode('/',array_map(
            fn(string $part)=>preg_match('/^[A-Za-z]:$/',$part)?$part:rawurlencode($part),
            $parts
        ));
        return str_starts_with($normalized,'/') ? 'file://'.$escaped : 'file:///'.$escaped;
    }
}
