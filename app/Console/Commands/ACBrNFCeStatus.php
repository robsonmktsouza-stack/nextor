<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ACBrNFCeStatus extends Command
{
    protected $signature = 'acbr:nfce-status';
    protected $description = 'Consulta SEFAZ-BA NFC-e em homologação sem emitir documento';

    public function handle(): int
    {
        $service = new ACBrNFeService();
        $company = CompanySetting::current();

        if ($company->state !== 'BA' || AppSetting::value('nfce', 'environment') !== 'homologation') {
            $this->components->error('Bloqueado: empresa deve estar na BA e NFC-e em homologação.');
            return self::FAILURE;
        }

        $relative = (string) $company->certificate_path;
        if ($relative === '' || !Storage::disk('local')->exists($relative)) {
            $this->components->error('Certificado A1 não encontrado no armazenamento privado.');
            return self::FAILURE;
        }

        $pfxPath = Storage::disk('local')->path($relative);
        $password = (string) $company->certificate_password;

        if ($password === '' || !is_readable($pfxPath)) {
            $this->components->error('Certificado A1 ou senha indisponível.');
            return self::FAILURE;
        }

        $certs = [];
        if (!@openssl_pkcs12_read(file_get_contents($pfxPath), $certs, $password) || empty($certs['pkey'])) {
            $this->components->error('Certificado PKCS#12 ou senha inválida.');
            return self::FAILURE;
        }

        $schemas = (string) env('ACBr_NFE_SCHEMAS_PATH', '');
        if ($schemas === '') {
            foreach ([
                'C:/laragon/acbr/dep/Schemas/NFe',
                'C:/laragon/acbr/Schemas/NFe',
                'C:/laragon/acbr/dep/Schemas',
                'C:/laragon/acbr/Schemas',
            ] as $candidate) {
                if (is_dir($candidate)) {
                    $schemas = $candidate;
                    break;
                }
            }
        }
        if (!is_dir($schemas)) {
            $this->components->error('Schemas NFe ausentes. Configure ACBr_NFE_SCHEMAS_PATH.');
            return self::FAILURE;
        }

        try {
            $service->initialize();
            foreach ([
                ['DFe', 'UF', 'BA'],
                ['DFe', 'ArquivoPFX', $pfxPath],
                ['DFe', 'Senha', $password],
                ['DFe', 'SSLCryptLib', '1'],
                ['DFe', 'SSLHttpLib', '3'],
                ['DFe', 'SSLXmlSignLib', '4'],
                ['NFe', 'PathSchemas', $schemas],
                ['NFe', 'ModeloDF', '1'],
                ['NFe', 'Ambiente', '1'],
                ['NFe', 'VersaoDF', '3'],
            ] as [$section, $key, $value]) {
                $service->setConfig($section, $key, $value);
            }

            $this->line('ACBr configurada: certificado, schemas, SSL, NFC-e 65, homologação BA.');
            $response = $service->statusServico();

            $data = @parse_ini_string($response, true, INI_SCANNER_RAW);
            $status = null;
            $reason = null;
            if (is_array($data)) {
                foreach ($data as $part) {
                    if (!is_array($part)) {
                        continue;
                    }
                    $status ??= $part['CStat'] ?? $part['cStat'] ?? null;
                    $reason ??= $part['XMotivo'] ?? $part['xMotivo'] ?? null;
                }
            }
            $this->line('cStat: '.($status ?? 'não identificado'));
            $this->line('Motivo: '.($reason ?? 'não identificado'));
            return (string) $status === '107' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $message = str_replace(
                array_filter([$password, $pfxPath, $relative, (string) AppSetting::value('nfce', 'csc_token', '')]),
                '[protegido]',
                $e->getMessage()
            );
            $this->components->error('Consulta falhou: '.$message);
            return self::FAILURE;
        } finally {
            $service->close();
        }
    }
}
