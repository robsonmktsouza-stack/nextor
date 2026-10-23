<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use App\Support\InstanceIdentity;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Read-only gateway. Never emits documents or registers recipient events.
 * The INI lives in private storage and is removed after each consultation.
 */
class IncomingNfeAcbrGateway
{
    private const UF=[
        'RO'=>11,'AC'=>12,'AM'=>13,'RR'=>14,'PA'=>15,'AP'=>16,'TO'=>17,
        'MA'=>21,'PI'=>22,'CE'=>23,'RN'=>24,'PB'=>25,'PE'=>26,'AL'=>27,
        'SE'=>28,'BA'=>29,'MG'=>31,'ES'=>32,'RJ'=>33,'SP'=>35,
        'PR'=>41,'SC'=>42,'RS'=>43,'MS'=>50,'MT'=>51,'GO'=>52,'DF'=>53,
    ];

    public function fetch(CompanySetting $company,string $cnpj,string $lastNsu): string
    {
        if(!InstanceIdentity::matches($cnpj)) {
            throw new RuntimeException('O CNPJ configurado não corresponde à instalação.');
        }
        $uf=strtoupper((string)$company->state);
        if(!isset(self::UF[$uf])) {
            throw new RuntimeException('Configure a UF da empresa antes de buscar notas.');
        }
        if(!$company->certificate_path || !$company->certificate_password
            || ($company->certificate_expires_at && $company->certificate_expires_at->isPast())) {
            throw new RuntimeException('Configure um certificado digital A1 válido para buscar as notas.');
        }
        $disk=Storage::disk('local');
        if(!$disk->exists($company->certificate_path)) {
            throw new RuntimeException('O certificado A1 da empresa não foi encontrado.');
        }
        $schemas=(string)config('services.acbr_nfe.schemas_path','');
        if($schemas==='') {
            foreach(['C:/laragon/acbr/dep/Schemas/NFe','C:/laragon/acbr/Schemas/NFe',
                      'C:/laragon/acbr/dep/Schemas','C:/laragon/acbr/Schemas'] as $candidate) {
                if(is_dir($candidate)) {$schemas=$candidate;break;}
            }
        }
        if(!is_dir($schemas)) {
            throw new RuntimeException('Configure o caminho dos schemas fiscais para a integração.');
        }
        $dir=storage_path('app/acbr-runtime');
        if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível preparar a consulta fiscal.');
        }
        $ini=$dir.'/incoming-nfe-'.bin2hex(random_bytes(12)).'.ini';
        $service=new ACBrNFeService(null,$ini);
        $password=(string)$company->certificate_password;
        try {
            $service->initialize();
            foreach([
                ['DFe','UF',$uf],
                ['DFe','ArquivoPFX',$disk->path($company->certificate_path)],
                ['DFe','Senha',$password],
                ['DFe','SSLCryptLib','1'],
                ['DFe','SSLHttpLib','3'],
                ['DFe','SSLXmlSignLib','4'],
                ['NFe','PathSchemas',$schemas],
                ['NFe','ModeloDF','0'], // model 55, not NFC-e 65
                ['NFe','Ambiente','0'], // production distribution: real incoming notes
                ['NFe','VersaoDF','3'],
            ] as [$section,$name,$value]) {
                $service->setConfig($section,$name,$value);
            }
            return $service->distributeLastNsu(self::UF[$uf],$cnpj,$lastNsu);
        } finally {
            try {$service->close();}
            finally {
                $password='';
                if(is_file($ini)) @unlink($ini);
            }
        }
    }
}
