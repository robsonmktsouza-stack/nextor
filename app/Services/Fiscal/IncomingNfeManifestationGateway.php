<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use App\Support\InstanceIdentity;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Transmite um evento assinado do destinatário através da ACBrLibNFe.
 * Nunca envia automaticamente, apenas por escolha explícita do usuário.
 */
class IncomingNfeManifestationGateway
{
    public function send(CompanySetting $company, string $cnpj, string $key, string $event, ?string $reason): string
    {
        return $this->withConfiguredAcbr($company,$cnpj,
            fn(ACBrNFeService $acbr): string =>
                $acbr->sendRecipientEvent($this->eventIni($cnpj,$key,$event,$reason)));
    }

    /**
     * Diagnóstico offline da configuração e do carregamento do INI.
     * Em nenhuma hipótese chama NFE_EnviarEvento nem registra tentativa.
     */
    public function diagnose(CompanySetting $company,string $cnpj,string $key,string $event): void
    {
        $payload=$this->eventIni($cnpj,$key,$event,null);
        $this->withConfiguredAcbr($company,$cnpj,
            function (ACBrNFeService $acbr) use ($payload): string {
                $acbr->prepareRecipientEvent($payload);
                return 'prepared';
            });
    }

    /** Consulta somente leitura; nunca retransmite um evento pendente. */
    public function consult(CompanySetting $company, string $cnpj, string $key): string
    {
        if(!preg_match('/^\d{44}$/',$key)) {
            throw new RuntimeException('Chave inválida para conferência da manifestação.');
        }
        return $this->withConfiguredAcbr($company,$cnpj,
            fn(ACBrNFeService $acbr): string => $acbr->consultByKeyWithEvents($key));
    }

    private function withConfiguredAcbr(CompanySetting $company,string $cnpj,callable $operation): string
    {
        if (!InstanceIdentity::matches($cnpj)) {
            throw new RuntimeException('CNPJ da instalação divergente.');
        }
        if (!$company->certificate_path || !$company->certificate_password
            || ($company->certificate_expires_at && $company->certificate_expires_at->isPast())
            || !Storage::disk('local')->exists($company->certificate_path)) {
            throw new RuntimeException('Certificado A1 indisponível.');
        }
        $schemas=(string) config('services.acbr_nfe.schemas_path', '');
        if ($schemas==='') {
            foreach (['C:/laragon/acbr/dep/Schemas/NFe','C:/laragon/acbr/Schemas/NFe',
                      'C:/laragon/acbr/dep/Schemas','C:/laragon/acbr/Schemas'] as $path) {
                if (is_dir($path)) { $schemas=$path; break; }
            }
        }
        if (!is_dir($schemas)) {
            throw new RuntimeException('Schemas fiscais não configurados.');
        }
        $dir=storage_path('app/acbr-runtime');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível preparar a transmissão.');
        }
        $ini=$dir.'/recipient-event-'.bin2hex(random_bytes(12)).'.ini';
        $acbr=new ACBrNFeService(null, $ini);
        try {
            $acbr->initialize();
            foreach ([
                ['DFe','UF',strtoupper((string)$company->state)],
                ['DFe','ArquivoPFX',Storage::disk('local')->path($company->certificate_path)],
                ['DFe','Senha',(string)$company->certificate_password],
                ['DFe','SSLCryptLib','1'], ['DFe','SSLHttpLib','3'],
                ['DFe','SSLXmlSignLib','4'], ['NFe','PathSchemas',$schemas],
                ['NFe','ModeloDF','0'], ['NFe','Ambiente','0'],
                ['NFe','VersaoDF','3'],
            ] as [$group,$field,$value]) {
                $acbr->setConfig($group,$field,$value);
            }
            return $operation($acbr);
        } finally {
            try { $acbr->close(); }
            finally { if (is_file($ini)) @unlink($ini); }
        }
    }

    /** Formato oficial de evento do destinatário da ACBrLib, Ambiente Nacional 91. */
    public function eventIni(string $cnpj, string $key, string $event, ?string $reason): string
    {
        if (!preg_match('/^\d{14}$/',$cnpj) || !preg_match('/^\d{44}$/',$key)
            || substr($key,20,2)!=='55'
            || !in_array($event,['210200','210210','210220','210240'],true)) {
            throw new RuntimeException('Dados inválidos para manifestação.');
        }
        if ($event==='210240' && ($reason===null || mb_strlen($reason)<15 || mb_strlen($reason)>255
            || str_contains($reason,"\r") || str_contains($reason,"\n"))) {
            throw new RuntimeException('Justificativa inválida para operação não realizada.');
        }
        $ini="[EVENTO]\nidLote=1\n\n[EVENTO001]\ncOrgao=91\nCNPJ={$cnpj}\nchNFe={$key}\n";
        $ini.="dhEvento=".now()->format('Y-m-d\TH:i:sP')."\n";
        $ini.="tpEvento={$event}\nnSeqEvento=1\nversaoEvento=1.00\n";
        if ($event==='210240') $ini.="xJust={$reason}\n";
        return $ini;
    }
}
