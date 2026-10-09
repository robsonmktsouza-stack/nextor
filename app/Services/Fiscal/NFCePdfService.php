<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use RuntimeException;

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
            $acbr->setConfig('DANFE','MostraPreview','0');
            $acbr->setConfig('DANFE','MostraSetup','0');
            $acbr->setConfig('DANFENFCe','TipoRelatorioBobina',$paper==='a4' ? '2' : '0');
            if ($paper!=='a4') {
                $acbr->setConfig('DANFENFCe','LarguraBobina',$paper);
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
