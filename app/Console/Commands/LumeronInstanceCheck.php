<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Support\InstanceIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Verifies one-CNPJ-per-instance isolation without printing credentials. */
final class LumeronInstanceCheck extends Command
{
    protected $signature='lumeron:instance-check {--fiscal : Exigir os requisitos básicos da NFC-e}';
    protected $description='Verificar isolamento, configuração e infraestrutura de uma instalação Lumeron';

    public function handle(): int
    {
        $failed=false;
        $check=function(bool $ok,string $label) use (&$failed): void {
            $this->line(($ok?'[OK] ':'[ERRO] ').$label);
            if (!$ok) $failed=true;
        };

        $id=(string)config('instance.id','');
        $cnpj=InstanceIdentity::cnpj();
        $check((bool)preg_match('/^[a-z][a-z0-9_]{1,18}$/',$id),'Identificador da instalação');
        $check($cnpj!==null && preg_match('/^\d{14}$/',$cnpj)===1,'CNPJ reservado para a instalação');
        $check((string)config('app.env')==='production' && !config('app.debug'),
            'Laravel em modo produção, sem depuração');
        $check(str_starts_with((string)config('app.url'),'https://'),
            'Endereço HTTPS configurado');
        $check(strlen((string)config('app.key'))>=32,'Chave APP_KEY própria');
        $check(config('database.default')==='mysql','Conexão MySQL');
        $check((string)config('queue.default')==='database','Fila em banco isolado');
        $check(in_array((string)config('session.driver'),['file','database'],true),
            'Sessões isoladas por instalação');
        $check(in_array((string)config('cache.default'),['file','database'],true),
            'Cache isolado por instalação');

        try {
            DB::connection()->getPdo();
            $check(Schema::hasTable('company_settings') && Schema::hasTable('jobs'),
                'Migrações e fila disponíveis no banco da instalação');
            $company=CompanySetting::current();
            $check(InstanceIdentity::matches($company->document),'CNPJ cadastrado corresponde à instalação');
            if ($this->option('fiscal')) {
                $check((bool)AppSetting::value('fiscal','enabled',false)
                    && (bool)AppSetting::value('nfce','enabled',false),'Módulo NFC-e habilitado');
                $environment=(string)AppSetting::value('nfce','environment','homologation');
                $check($environment==='production'
                    && (bool)AppSetting::value('nfce','production_enabled',false),
                    'NFC-e habilitada em produção');
                $check($company->certificate_path
                    && Storage::disk('local')->exists($company->certificate_path)
                    && (bool)$company->certificate_password,'Certificado A1 disponível');
                $check((bool)AppSetting::value('nfce','csc_id','')
                    && (bool)AppSetting::value('nfce','csc_token',''),'CSC e ID configurados');
                $check(extension_loaded('ffi') && is_file((string)config('services.acbr_nfe.library_path')),
                    'ACBrLib Linux e extensão PHP FFI');
                $check(is_dir((string)config('services.acbr_nfe.schemas_path')),
                    'Schemas da NFC-e acessíveis');
                $this->warn('Atenção: esta checagem não substitui autorização SEFAZ nem validação tributária.');
            }
        } catch (Throwable $e) {
            $failed=true;
            $this->error('Banco ou configuração indisponível: '.$e->getMessage());
        }

        $this->line($failed?'Instalação requer correções.':'Verificações locais concluídas.');
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
