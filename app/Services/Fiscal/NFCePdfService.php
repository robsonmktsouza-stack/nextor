<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use RuntimeException;
use Illuminate\Support\Facades\Storage;

/**
 * Gera PDF real do DANFE a partir do nfeProc autorizado, sem reemitir nota.
 * Cada requisição usa uma instância ACBr e INI privado descartado no final.
 */
class NFCePdfService
{
    public function paper(): string
    {
        $previous=(string)AppSetting::value('pdv','receipt_width','80');
        $paper=(string)AppSetting::value('printing','nfce_paper',$previous);
        return in_array($paper,['58','80','a4'],true) ? $paper : '80';
    }

    /** The selected paper and XML contents uniquely identify this exact PDF. */
    public function storedPath(FiscalDocumentJob $document, string $authorizedXml): string
    {
        $paper=$this->paper();
        $digest=substr(hash('sha256',$authorizedXml),0,20);
        return 'fiscal/nfce/'.$document->id.'/danfe-v2-'.$paper.'-'.$digest.'.pdf';
    }

    public function existingPath(FiscalDocumentJob $document, string $authorizedXml): ?string
    {
        $path=$this->storedPath($document,$authorizedXml);
        return Storage::disk('local')->exists($path) ? $path : null;
    }

    /**
     * Used only by the fiscal queue worker: the web PHP process must not
     * load ACBr DLLs and does not require the PHP FFI extension.
     */
    public function generate(FiscalDocumentJob $document, string $authorizedXml): string
    {
        if ($existing=$this->existingPath($document,$authorizedXml)) {
            return $existing;
        }

        $pdf=$this->render($document,$authorizedXml);
        if (!str_starts_with($pdf,'%PDF-') || !str_contains($pdf,'%%EOF')) {
            throw new RuntimeException('O gerador não retornou um documento PDF completo.');
        }
        $path=$this->storedPath($document,$authorizedXml);
        if (!Storage::disk('local')->put($path,$pdf)) {
            throw new RuntimeException('Não foi possível salvar o DANFE PDF.');
        }
        return $path;
    }

    public function render(FiscalDocumentJob $document, string $authorizedXml): string
    {
        if ($document->document_type !== 'nfce' || $document->status !== 'authorized') {
            throw new RuntimeException('O DANFE PDF está disponível somente para NFC-e autorizada.');
        }

        $dir=storage_path('app/acbr-runtime');
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível preparar o DANFE PDF.');
        }
        $ini=$dir.'/nfce-pdf-'.$document->id.'-'.bin2hex(random_bytes(8)).'.ini';
        $acbr=new ACBrNFeService(null,$ini);

        try {
            $paper=$this->paper();
            $company=CompanySetting::current();
            $acbr->initialize();

            // Configurações oficiais ACBrLib: Fortes é capaz de gerar PDF.
            // Nunca ativar EscPos aqui, pois é exclusivo da impressora térmica.
            $acbr->setConfig('DFe','UF',strtoupper((string)($company->state ?: 'BA')));
            $acbr->setConfig('NFe','ModeloDF','1'); // NFC-e 65
            $acbr->setConfig('NFe','Ambiente',$document->environment==='production' ? '0' : '1');
            $acbr->setConfig('DANFE','TipoDANFE','4'); // DANFE NFC-e
            $acbr->setConfig('DANFE','MostraPreview','0');
            $acbr->setConfig('DANFE','MostraSetup','0');
            $acbr->setConfig('DANFENFCe','TipoRelatorioBobina',$paper==='a4' ? '2' : '0');
            $acbr->setConfig('DANFENFCe','ImprimeEmUmaLinha','0');
            $acbr->setConfig('DANFENFCe','ImprimeEmDuasLinhas','1');
            $acbr->setConfig('DANFENFCe','FonteLinhaItem.Name','Arial');
            $acbr->setConfig('DANFENFCe','FonteLinhaItem.Size','10');
            $acbr->setConfig('DANFENFCe','ImprimeQRCodeLateral','0');
            $acbr->setConfig('DANFENFCe','EspacoFinal','0');
            $acbr->setConfig('DANFENFCe','MargemEsquerda','0.6');
            $acbr->setConfig('DANFENFCe','MargemDireita','0.4');
            $acbr->setConfig('DANFENFCe','MargemSuperior','0.4');
            $acbr->setConfig('DANFENFCe','MargemInferior','0.01');
            if ($paper!=='a4') {
                // Valores do componente Fortes: 302 (bobina 80 mm),
                // 200 (bobina 58 mm). Não são milímetros diretos.
                $acbr->setConfig('DANFENFCe','LarguraBobina',$paper==='58' ? '200' : '302');
            }
            $acbr->loadXml($authorizedXml);
            return $acbr->savePdf();
        } finally {
            try {
                $acbr->close();
            } finally {
                if (is_file($ini)) {
                    @unlink($ini);
                }
            }
        }
    }
}
