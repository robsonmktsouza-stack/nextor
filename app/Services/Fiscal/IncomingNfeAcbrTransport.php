<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use App\Support\InstanceIdentity;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class IncomingNfeAcbrTransport
{
    private const UF_CODES=[
        'RO'=>11,'AC'=>12,'AM'=>13,'RR'=>14,'PA'=>15,'AP'=>16,'TO'=>17,
        'MA'=>21,'PI'=>22,'CE'=>23,'RN'=>24,'PB'=>25,'PE'=>26,'AL'=>27,'SE'=>28,'BA'=>29,
        'MG'=>31,'ES'=>32,'RJ'=>33,'SP'=>35,
        'PR'=>41,'SC'=>42,'RS'=>43,
        'MS'=>50,'MT'=>51,'GO'=>52,'DF'=>53,
    ];

    public function fetch(CompanySetting $company,string $cnpj,string $lastNsu): string
    {
        InstanceIdentity::assertCompanyDocument($cnpj);
        if(!hash_equals(preg_replace('/\D/','',(string)$company->document),$cnpj)) {
            throw new RuntimeException('O CNPJ da empresa não corresponde ao destinatário.');
        }
        $uf=self::UF_CODES[strtoupper(trim((string)$company->state))] ?? null;
        if(!$uf)throw new RuntimeException('Configure a UF da empresa para consultar notas recebidas.');
        if(!$company->certificate_path || !$company->certificate_password) {
            throw new RuntimeException('Cadastre um certificado digital A1 válido em Configurações → Fiscal.');
        }
        if($company->certificate_expires_at && $company->certificate_expires_at->isPast()) {
            throw new RuntimeException('O certificado A1 está vencido.');
        }
        $certPath=Storage::disk('local')->path((string)$company->certificate_path);
        if(!is_file($certPath))throw new RuntimeException('Arquivo do certificado A1 não encontrado.');
        $dir=storage_path('app/acbr-runtime');
        if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
            throw new RuntimeException('Diretório fiscal temporário indisponível.');
        }
        $ini=$dir.'/incoming-'.bin2hex(random_bytes(12)).'.ini';
        $lib=new ACBrNFeService(null,$ini);
        try {
            $lib->initialize();
            foreach([
                ['DFe','UF',strtoupper((string)$company->state)],
                ['DFe','ArquivoPFX',$certPath],
                ['DFe','Senha',(string)$company->certificate_password],
                ['DFe','SSLCryptLib','1'],
                ['DFe','SSLHttpLib','3'],
                ['DFe','SSLXmlSignLib','4'],
                ['NFe','ModeloDF','0'],
                ['NFe','Ambiente','0'], // distribuição real, não NFC-e homologação
            ] as [$section,$key,$value]) {
                $lib->setConfig($section,$key,$value);
            }
            return $lib->distributeByLastNsu($uf,$cnpj,$lastNsu);
        } finally {
            try{$lib->close();}finally{
                if(is_file($ini))@unlink($ini);
            }
        }
    }
}
