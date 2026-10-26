<?php
namespace App\Services\Fiscal;

use App\Models\NfeDraft;
use RuntimeException;
use setasign\Fpdi\Fpdi;

final class Nfe55StampPdf extends Fpdi
{
    public function diagonalStamp(float $x,float $y,string $text): void
    {
        $angle=35;
        $rad=deg2rad($angle);
        $c=cos($rad);$sin=sin($rad);
        $this->_out('q');
        $this->_out(sprintf('%.5F %.5F %.5F %.5F %.5F %.5F cm',
            $c,$sin,-$sin,$c,$x*$this->k,($this->h-$y)*$this->k));
        $this->Text(-54,0,$text);
        $this->_out('Q');
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
        if(!class_exists(Fpdi::class)){
            throw new RuntimeException('Biblioteca de impressão fiscal não instalada.');
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
                // Visible on every page. Never conceal original fiscal content.
                $out->SetDrawColor(168,20,20);
                $out->SetTextColor(168,20,20);
                $out->SetFillColor(255,245,245);
                $out->SetLineWidth(0.25);
                $bannerH=15.0;
                $out->Rect(4,$h-$bannerH-4,$w-8,$bannerH,'DF');
                $out->SetFont('Helvetica','B',15);
                $out->SetXY(6,$h-$bannerH-3);
                $out->Cell($w-12,6,'NF-e CANCELADA',0,2,'C');
                $out->SetFont('Helvetica','',8);
                $out->Cell($w-12,4,'SEM VALIDADE PARA CIRCULACAO DE MERCADORIAS',0,2,'C');
                $out->SetFont('Helvetica','',7);
                $out->Cell($w-12,4,'Protocolo do evento: '.$draft->cancellation_protocol,0,0,'C');
                // Repeated diagonal watermark, intentionally translucent-looking
                // light red to keep the original DANFE legible.
                $out->SetTextColor(210,140,140);
                $out->SetFont('Helvetica','B',26);
                $out->diagonalStamp($w/2,$h/2,'NF-e CANCELADA');
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
