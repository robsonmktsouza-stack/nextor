<?php
namespace App\Services\Fiscal;

use App\Models\NfeDraft;
use RuntimeException;
use setasign\Fpdi\Fpdi;

// Keep this file loadable during an incomplete dependency installation. The
// service below can then return an operational error instead of a class fatal.
if (class_exists(\FPDF::class) && class_exists(Fpdi::class)) {
    final class Nfe55StampPdf extends Fpdi
    {
        public function diagonalStamp(float $x,float $y,string $text): void
        {
            // PDF uses bottom-left coordinates. Render text in the rotated local
            // coordinate system, rather than calling FPDF::Text with page coords.
            $rad=deg2rad(35);
            $c=cos($rad);
            $sin=sin($rad);
            $this->_out('q');
            $this->_out(sprintf('%.5F %.5F %.5F %.5F %.5F %.5F cm',
                $c,$sin,-$sin,$c,$x*$this->k,($this->h-$y)*$this->k));
            $width=$this->GetStringWidth($text)*$this->k;
            $this->_out(sprintf('BT %.3F 0 Td (%s) Tj ET',-$width/2,$this->_escape($text)));
            $this->_out('Q');
        }
    }
}


/**
 * Overlays a clearly marked historical copy. Never edits the authorized XML.
 * The cancellation event must already be confirmed before this service is called.
 */
final class Nfe55CancelledDanfeStampService
{
    public function stamp(string $pdf,NfeDraft $draft): string
    {
        if($draft->status!=='cancelled'
            ||$draft->cancellation_status!=='authorized'
            ||!$draft->cancelled_at
            ||!preg_match('/^\\d{15}$/D',(string)$draft->cancellation_protocol)){
            throw new RuntimeException('Cancelamento não comprovado.');
        }
        if(!class_exists(\FPDF::class)||!class_exists(Fpdi::class)){
            throw new RuntimeException('Impressão da NF-e cancelada indisponível. Solicite a verificação ao responsável pelo sistema.');
        }
        $file=tempnam(sys_get_temp_dir(),'danfe55-');
        if($file===false)throw new RuntimeException('Arquivo temporário indisponível.');
        try{
            if(file_put_contents($file,$pdf,LOCK_EX)===false){
                throw new RuntimeException('Não foi possível preparar o DANFE.');
            }
            $out=new Nfe55StampPdf('P','mm');
            $out->SetAutoPageBreak(false);
            $count=$out->setSourceFile($file);
            if($count<1 || $count>100)throw new RuntimeException('DANFE inválido.');
            for($page=1;$page<=$count;$page++){
                $template=$out->importPage($page);
                $size=$out->getTemplateSize($template);
                $w=(float)$size['width'];
                $h=(float)$size['height'];
                $out->AddPage($size['orientation'],[$w,$h]);
                $out->useTemplate($template,0,0,$w,$h);
                // Top-right cancellation badge, repeated on each page.
                // It is kept inside the top margin and never replaces XML data.
                $badgeW=min(47.0,$w-10);
                $out->SetFillColor(168,20,20);
                $out->Rect($w-$badgeW-5,2,$badgeW,6,'F');
                $out->SetTextColor(255,255,255);
                $out->SetFont('Helvetica','B',9);
                $out->SetXY($w-$badgeW-5,2);
                $out->Cell($badgeW,6,'NF-e CANCELADA',0,0,'C');
                // Footer repeated on every page.
                $out->SetDrawColor(168,20,20);
                $out->SetTextColor(168,20,20);
                $out->SetFillColor(255,245,245);
                $out->SetLineWidth(0.25);
                $bannerH=13.0;
                $out->Rect(4,$h-$bannerH-4,$w-8,$bannerH,'DF');
                $out->SetFont('Helvetica','B',12);
                $out->SetXY(6,$h-$bannerH-3);
                $out->Cell($w-12,5,'NF-e CANCELADA',0,2,'C');
                $out->SetFont('Helvetica','',8);
                $out->Cell($w-12,3.5,'SEM VALIDADE PARA CIRCULACAO DE MERCADORIAS',0,2,'C');
                $out->SetFont('Helvetica','',7);
                $out->Cell($w-12,3.5,'Protocolo do evento: '.$draft->cancellation_protocol,0,0,'C');
                // Repeated diagonal watermark, intentionally translucent-looking
                // light red to keep the original DANFE legible.
                $out->SetTextColor(235,210,210);
                $out->SetFont('Helvetica','B',24);
                $out->diagonalStamp($w/2,$h*0.62,'NF-e CANCELADA');
                $out->SetTextColor(0,0,0);
            }
            $result=$out->Output('S');
            if(!is_string($result)||!str_starts_with($result,'%PDF-')||strlen($result)>12582912){
                throw new RuntimeException('PDF cancelado inválido.');
            }
            return $result;
        }catch(\Throwable $error){
            throw new RuntimeException('Não foi possível identificar o DANFE cancelado.',0,$error);
        }finally{
            @unlink($file);
        }
    }
}
