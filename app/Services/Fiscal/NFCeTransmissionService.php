<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use App\Services\Fiscal\ACBr\ACBrNFeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class NFCeTransmissionService
{
    public function __construct(
        private readonly NFCePreflightService $preflight,
        private readonly NFCeIniBuilder $builder,
    ) {}

    public function process(int $id): void
    {
        $lock = Cache::lock('nextor:nfce:'.$id, 300);
        if (!$lock->get()) {
            return;
        }

        try {
            $job = DB::transaction(function () use ($id) {
                $job = FiscalDocumentJob::query()->lockForUpdate()->findOrFail($id);
                if ($job->status !== 'prepared') {
                    return null;
                }
                $problems = $this->preflight->validate($job);
                if ($problems) {
                    $job->update(['error_message' => implode(' | ', $problems)]);
                    return null;
                }
                $job->update(['status' => 'processing', 'error_message' => null]);
                return $job;
            }, 3);

            if (!$job) {
                return;
            }

            $service = new ACBrNFeService();
            $transmissionAttempted = false;
            $company = CompanySetting::current();
            $password = (string) $company->certificate_password;
            $csc = (string) AppSetting::value('nfce', 'csc_token', '');

            try {
                $this->configure($service, $company, $job, $password, $csc);
                $ini = $this->builder->build($job, $company);
                $service->loadIni($ini);
                $service->sign();
                $service->validateXml();
                $signedXml = $service->getXml();

                if (!str_contains($signedXml, '<Signature') || !str_contains($signedXml, '<NFe')) {
                    throw new RuntimeException('ACBr não retornou XML NFC-e assinado.');
                }

                $basePath = 'fiscal/nfce/'.$job->id;
                $signedPath = $basePath.'/signed.xml';
                if (!Storage::disk('local')->put($signedPath, $signedXml)) {
                    throw new RuntimeException('Falha ao preservar XML assinado antes da transmissão.');
                }
                $job->update(['xml_path' => $signedPath]);

                // A partir daqui qualquer falha é situação INDETERMINADA:
                // nunca repetir envio automaticamente com novo cNF ou nova numeração.
                $transmissionAttempted = true;
                $response = $service->send((int) $job->document_number);
                $responsePath = $basePath.'/sefaz-response.ini';
                Storage::disk('local')->put($responsePath, $response);
                $job->update(['response_path' => $responsePath]);

                $status = $this->interpret($response);
                if ($status['cstat'] === '100' && $status['protocol'] && $status['key']) {
                    $xmlPath = $signedPath;
                    try {
                        $authorizedXml = $service->getXml();
                        if (str_contains($authorizedXml, '<protNFe')) {
                            $authorizedPath = $basePath.'/authorized.xml';
                            if (Storage::disk('local')->put($authorizedPath, $authorizedXml)) {
                                $xmlPath = $authorizedPath;
                            }
                        }
                    } catch (Throwable) {
                        // Autorização SEFAZ prevalece; manter XML assinado e protocolo.
                    }

                    $job->update([
                        'status' => 'authorized',
                        'access_key' => $status['key'],
                        'protocol' => $status['protocol'],
                        'xml_path' => $xmlPath,
                        'authorized_at' => now(),
                        'processed_at' => now(),
                        'error_message' => null,
                    ]);
                } else {
                    $job->update([
                        'status' => $status['cstat'] !== null && (int) $status['cstat'] >= 200 && (int) $status['cstat'] < 1000
                            ? 'rejected'
                            : 'pending',
                        'error_message' => 'Retorno SEFAZ: '.($status['cstat'] ?? 'desconhecido').' - '.$status['reason'],
                        'processed_at' => now(),
                    ]);
                }
            } catch (Throwable $e) {
                $message = str_replace(array_filter([$password, $csc]), '[protegido]', $e->getMessage());
                $job->update([
                    'status' => $transmissionAttempted ? 'pending' : 'prepared',
                    'error_message' => mb_substr($message, 0, 1800),
                    'processed_at' => now(),
                ]);
            } finally {
                $service->close();
            }
        } finally {
            $lock->release();
        }
    }

    private function configure(
        ACBrNFeService $service,
        CompanySetting $company,
        FiscalDocumentJob $job,
        string $password,
        string $csc
    ): void {
        if ($job->environment === 'production' && !config('services.acbr_nfe.production_enabled', false)) {
            throw new RuntimeException('Envio em produção bloqueado.');
        }

        $certPath = Storage::disk('local')->path($company->certificate_path);
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
        if (!is_dir($schemas) || !is_file($certPath)) {
            throw new RuntimeException('Certificado ou pasta de schemas indisponível.');
        }

        $service->initialize();
        foreach ([
            ['DFe', 'UF', 'BA'],
            ['DFe', 'ArquivoPFX', $certPath],
            ['DFe', 'Senha', $password],
            ['DFe', 'SSLCryptLib', '1'],
            ['DFe', 'SSLHttpLib', '3'],
            ['DFe', 'SSLXmlSignLib', '4'],
            ['NFe', 'PathSchemas', $schemas],
            ['NFe', 'ModeloDF', '1'],
            ['NFe', 'Ambiente', $job->environment === 'homologation' ? '1' : '0'],
            ['NFe', 'VersaoDF', '3'],
            ['NFe', 'IdCSC', (string) AppSetting::value('nfce', 'csc_id', '')],
            ['NFe', 'CSC', $csc],
        ] as [$section, $key, $value]) {
            $service->setConfig($section, $key, $value);
        }
    }

    private function interpret(string $response): array
    {
        $sections = @parse_ini_string($response, true, INI_SCANNER_RAW);
        $result = ['cstat' => null, 'reason' => 'Resposta não interpretada', 'protocol' => null, 'key' => null];
        if (!is_array($sections)) {
            return $result;
        }

        foreach ($sections as $name => $section) {
            if (!is_array($section)) {
                continue;
            }
            // A resposta individual da NFC-e deve prevalecer sobre ENVIO/RETORNO.
            if (preg_match('/^NFE\d+$/i', (string) $name)) {
                $result['cstat'] = (string) ($section['CStat'] ?? $section['cStat'] ?? '');
                $result['reason'] = (string) ($section['XMotivo'] ?? $section['xMotivo'] ?? '');
                $result['protocol'] = (string) ($section['NProt'] ?? $section['nProt'] ?? '');
                $result['key'] = (string) ($section['chDFe'] ?? $section['ChNFe'] ?? $section['chNFe'] ?? '');
                return $result;
            }
            $result['cstat'] = (string) ($section['CStat'] ?? $section['cStat'] ?? $result['cstat']);
            $result['reason'] = (string) ($section['XMotivo'] ?? $section['xMotivo'] ?? $result['reason']);
        }
        return $result;
    }
}
