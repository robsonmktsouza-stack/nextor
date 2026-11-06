<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Native NF-e validation with the installation's ACBr and A1 certificate.
 * It does NOT reserve a number, mutate a draft, enqueue jobs or contact SEFAZ.
 *
 * The number is a provisional local simulation of the next invoice number.
 * Running this test is not an NF-e authorization or a guarantee of acceptance.
 */
final class Nfe55PreflightService
{
    public function __construct(
        private readonly Nfe55EmissionService $emission,
        private readonly Nfe55IniBuilder $builder,
    ) {}

    public function check(NfeDraft $draft): array
    {
        if ($draft->status !== 'draft' || $draft->document_number !== null) {
            throw ValidationException::withMessages(['nfe'=>'A verificação fiscal só está disponível para rascunhos.']);
        }
        if (!$draft->validated_at) {
            throw ValidationException::withMessages(['nfe'=>'Salve e valide a NF-e antes de verificar o certificado e o XML.']);
        }
        $company=CompanySetting::current();
        $this->emission->assertReady($draft,$company);
        $number=app(FiscalDocumentSettings::class)->nfeNumbering((string)$draft->environment)['next_number'];
        if ($number<1 || $number>999999999) {
            throw ValidationException::withMessages(['nfe'=>'Confira a numeração de homologação da NF-e.']);
        }
        // This is NOT a reserved number. It is never persisted, distributed or sent.
        $code=random_int(1,99999999);
        $ini=$this->builder->build($draft,$company,$number,$code);
        $folder=storage_path('app/acbr-runtime');
        if (!is_dir($folder) && !mkdir($folder,0700,true) && !is_dir($folder)) {
            throw new RuntimeException('Diretório temporário de verificação indisponível.');
        }
        $runtime=$folder.'/nfe55-check-'.bin2hex(random_bytes(12)).'.ini';
        $service=null;
        try {
            $service=new ACBrNFeService(null,$runtime);
            $this->emission->configure($service,$company,$draft,(string)$company->certificate_password);
            $service->loadIni($ini);
            $service->sign();
            $service->validateXml();
            $service->validateBusinessRules();
            $service->verifySignature();
            $xml=$service->getXml();
            $key=$this->emission->verifySignedXml($xml,$draft,$company,$number,$code);
            app(Nfe55XmlTaxConsistency::class)->assertMatches($ini,$xml);
            return [
                'ok'=>true,'model'=>'55','environment'=>(string)$draft->environment,
                'tested_number'=>$number,'signed_key_verified'=>strlen($key)===44,
            ];
        } finally {
            try { $service?->close(); }
            finally { if (is_file($runtime)) @unlink($runtime); }
        }
    }
}
