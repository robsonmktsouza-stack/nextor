<?php

namespace Tests\Unit;

use App\Models\NfeDraft;
use App\Services\Fiscal\Nfe55CancelledDanfeStampService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\TestCase;

final class Nfe55CancelledDanfeStampTest extends TestCase
{
    private function cancelledDraft(): NfeDraft
    {
        return new NfeDraft([
            'status'=>'cancelled', 'cancellation_status'=>'authorized',
            'cancelled_at'=>now(), 'cancellation_protocol'=>'129260001234567',
        ]);
    }

    private function originalPdf(): string
    {
        $pdf=new \FPDF('P','mm','A4');
        $pdf->SetCompression(false);
        for($page=1;$page<=2;$page++){
            $pdf->AddPage();
            $pdf->SetFont('Helvetica','',12);
            $pdf->SetXY(20,35);
            $pdf->Cell(150,8,'DANFE TESTE PAGINA '.$page);
        }
        return $pdf->Output('S');
    }

    public function test_a_historical_copy_marks_both_pages_and_preserves_original_content_and_state(): void
    {
        $draft=$this->cancelledDraft();
        $attributes=$draft->getAttributes();
        $original=$this->originalPdf();
        $hash=hash('sha256',$original);
        $stamped=(new Nfe55CancelledDanfeStampService())->stamp($original,$draft);

        self::assertStringStartsWith('%PDF-', $stamped);
        self::assertNotSame($original,$stamped);
        self::assertSame($hash,hash('sha256',$original));
        self::assertSame($attributes,$draft->getAttributes());
        $reader=new Fpdi();
        self::assertSame(2,$reader->setSourceFile(StreamReader::createByString($stamped)));

        // FPDF writes a separate content stream per page. Import forms retain
        // the source content; only the output page streams contain the badges.
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s',$stamped,$matches);
        $streams=array_map(static function(string $stream): string {
            $decoded=@gzuncompress($stream);
            return $decoded===false?$stream:$decoded;
        },$matches[1]);
        $marked=array_values(array_filter($streams,static fn(string $stream): bool=>str_contains($stream,'NF-e CANCELADA')));
        self::assertCount(2,$marked);
        foreach($marked as $page){
            self::assertSame(3,substr_count($page,'NF-e CANCELADA'));
            self::assertStringContainsString('SEM VALIDADE PARA CIRCULACAO DE MERCADORIAS',$page);
            self::assertStringContainsString('129260001234567',$page);
        }
        $all=implode("\n",$streams);
        self::assertStringContainsString('DANFE TESTE PAGINA 1',$all);
        self::assertStringContainsString('DANFE TESTE PAGINA 2',$all);
    }

    public function test_an_invalid_pdf_returns_an_operational_error_and_retains_its_cause(): void
    {
        try{
            (new Nfe55CancelledDanfeStampService())->stamp('conteúdo inválido',$this->cancelledDraft());
            self::fail('Invalid content must never become a printable fiscal document.');
        }catch(RuntimeException $error){
            self::assertSame('Não foi possível identificar o DANFE cancelado.',$error->getMessage());
            self::assertNotNull($error->getPrevious());
        }
    }

    public function test_the_stamp_remains_blocked_without_confirmed_cancellation(): void
    {
        $draft=$this->cancelledDraft();
        $draft->cancellation_status='uncertain';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cancelamento não comprovado.');
        (new Nfe55CancelledDanfeStampService())->stamp('%PDF-1.4',$draft);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_missing_print_dependencies_do_not_cause_a_class_loading_fatal(): void
    {
        self::assertFalse(class_exists(Fpdi::class,false));
        $draft=$this->cancelledDraft();
        $autoloaders=spl_autoload_functions();
        foreach($autoloaders as $loader)spl_autoload_unregister($loader);
        $guarded=static function(string $class) use($autoloaders): void {
            if($class==='FPDF'||str_starts_with($class,'setasign\\'))return;
            foreach($autoloaders as $loader){
                $loader($class);
                if(class_exists($class,false)||interface_exists($class,false)||trait_exists($class,false))return;
            }
        };
        spl_autoload_register($guarded);
        try{
            (new Nfe55CancelledDanfeStampService())->stamp('%PDF-1.4',$draft);
            self::fail('Missing dependencies must report an operational error.');
        }catch(RuntimeException $error){
            self::assertSame('Impressão da NF-e cancelada indisponível. Solicite a verificação ao responsável pelo sistema.',$error->getMessage());
            self::assertStringNotContainsString('setasign',$error->getMessage());
        }finally{
            spl_autoload_unregister($guarded);
            foreach($autoloaders as $loader)spl_autoload_register($loader);
        }
    }
}
