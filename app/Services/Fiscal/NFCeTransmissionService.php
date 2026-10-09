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
        private readonly NFCeSefazResponseParser $responses,
        private readonly NFCeProtocolXmlService $protocolXml,
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

            // Um INI privado por documento: não utilizar o arquivo ACBr global
            // que pode persistir senha do A1 em texto puro.
            $runtimeDir = storage_path('app/acbr-runtime');
            if (!is_dir($runtimeDir) && !mkdir($runtimeDir, 0700, true) && !is_dir($runtimeDir)) {
                $job->update(['status' => 'prepared', 'error_message' => 'Diretório ACBr temporário indisponível.']);
                return;
            }
            $runtimeIni = $runtimeDir.'/nfce-'.$job->id.'-'.bin2hex(random_bytes(8)).'.ini';
            $service = new ACBrNFeService(null, $runtimeIni);
            $transmissionAttempted = false;
            $password = '';
            $csc = '';

            try {
                $company = CompanySetting::current();
                $password = (string) $company->certificate_password;
                $csc = (string) AppSetting::value('nfce', 'csc_token', '');
                $this->configure($service, $company, $job, $password, $csc);
                $ini = $this->builder->build($job, $company);
                $service->loadIni($ini);
                $service->sign();
                $service->validateXml();
                $signedXml = $service->getXml();

                // Verificar a assinatura e chave da NFC-e efetivamente gerada.
                $signedKey = $this->protocolXml->verifySigned($signedXml, $job);

                $basePath = 'fiscal/nfce/'.$job->id;
                $signedPath = $basePath.'/signed.xml';
                if (!Storage::disk('local')->put($signedPath, $signedXml)) {
                    throw new RuntimeException('Falha ao preservar XML assinado antes da transmissão.');
                }
                // Preservar chave antes da chamada externa: em caso de timeout
                // a consulta poderá usar a MESMA chave, sem reemitir.
                $job->update(['xml_path' => $signedPath, 'access_key' => $signedKey]);

                // A partir daqui qualquer falha é situação INDETERMINADA:
                // nunca repetir envio automaticamente com novo cNF ou nova numeração.
                $transmissionAttempted = true;
                $response = $service->send((int) $job->document_number);
                $responsePath = $basePath.'/sefaz-response.ini';
                Storage::disk('local')->put($responsePath, $response);
                $job->update(['response_path' => $responsePath]);

                $status = $this->responses->parse($response);
                if ($this->responses->authorized($status)) {
                    // A chave retornada pela SEFAZ deve ser exatamente a chave
                    // do XML assinado que foi armazenado antes do envio.
                    if (!hash_equals($signedKey, $status['key'])) {
                        throw new RuntimeException('A chave autorizada pela SEFAZ difere do XML enviado; conciliação necessária.');
                    }

                    $xmlPath = $signedPath;
                    $xmlWarning = null;
                    try {
                        $authorizedXml = $this->protocolXml->buildAuthorized($signedXml, $status);
                        $authorizedPath = $basePath.'/authorized.xml';
                        if (!Storage::disk('local')->put($authorizedPath, $authorizedXml)) {
                            throw new RuntimeException('Não foi possível guardar nfeProc.');
                        }
                        $xmlPath = $authorizedPath;
                    } catch (Throwable $error) {
                        // SEFAZ autorizou; erro ao materializar XML não pode
                        // desautorizar o documento nem provocar outro envio.
                        $xmlWarning = 'NFC-e autorizada, porém XML com protocolo indisponível: '.$error->getMessage();
                    }

                    $job->update([
                        'status' => 'authorized',
                        'access_key' => $signedKey,
                        'protocol' => $status['protocol'],
                        'xml_path' => $xmlPath,
                        'authorized_at' => now(),
                        'processed_at' => now(),
                        'error_message' => $xmlWarning,
                    ]);
                } else {
                    $code = (string) ($status['cstat'] ?? '');
                    $indeterminate = !$status['individual'] || in_array($code, ['103', '104', '105', '204', '539', '656'], true);
                    $job->update([
                        'status' => !$indeterminate && ctype_digit($code) && (int) $code >= 200 && (int) $code < 1000
                            ? 'rejected'
                            : 'pending',
                        'error_message' => 'Retorno SEFAZ: '.($code ?: 'não identificado').' - '.$status['reason'],
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
                try {
                    $service->close();
                } finally {
                    if (is_file($runtimeIni)) {
                        @unlink($runtimeIni);
                    }
                }
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Reconcilia um envio incerto SEM transmiti-lo novamente.
     * Consulta exclusivamente a chave do XML assinado já armazenado.
     */
    public function consult(int $id): void
    {
        $lock = Cache::lock('nextor:nfce:'.$id, 300);
        if (!$lock->get()) {
            return;
        }

        try {
            $job = FiscalDocumentJob::query()->findOrFail($id);
            if ($job->document_type !== 'nfce' || $job->status !== 'pending'
                || preg_match('/^\d{44}$/', (string) $job->access_key) !== 1) {
                return;
            }
            $signedPath = 'fiscal/nfce/'.$job->id.'/signed.xml';
            if (!Storage::disk('local')->exists($signedPath)) {
                $job->update(['error_message' => 'XML assinado não encontrado; consultar manualmente antes de alterar o documento.']);
                return;
            }
            $signedXml = Storage::disk('local')->get($signedPath);
            $signedKey = $this->protocolXml->verifySigned($signedXml, $job);
            if (!hash_equals($signedKey, $job->access_key)) {
                $job->update(['error_message' => 'Chave salva não corresponde ao XML assinado; requer conciliação.']);
                return;
            }

            $runtimeDir = storage_path('app/acbr-runtime');
            if (!is_dir($runtimeDir) && !mkdir($runtimeDir, 0700, true) && !is_dir($runtimeDir)) {
                throw new RuntimeException('Diretório ACBr temporário indisponível.');
            }
            $runtimeIni = $runtimeDir.'/consulta-'.$job->id.'-'.bin2hex(random_bytes(8)).'.ini';
            $service = new ACBrNFeService(null, $runtimeIni);
            $company = CompanySetting::current();
            $password = (string) $company->certificate_password;
            $csc = (string) AppSetting::value('nfce', 'csc_token', '');

            try {
                $this->configure($service, $company, $job, $password, $csc);
                $raw = $service->consultByKey($signedKey);
                $responsePath = 'fiscal/nfce/'.$job->id.'/consult-response.ini';
                if (!Storage::disk('local')->put($responsePath, $raw)) {
                    throw new RuntimeException('Falha ao arquivar consulta da SEFAZ.');
                }
                $data = $this->responses->parseConsult($raw);
                $job->update(['response_path' => $responsePath, 'processed_at' => now()]);

                if ($this->responses->authorized($data)) {
                    if (!hash_equals($signedKey, $data['key'])) {
                        throw new RuntimeException('Chave da consulta difere do XML assinado.');
                    }
                    $xmlPath = $signedPath;
                    $warning = null;
                    try {
                        $xml = $this->protocolXml->buildAuthorized($signedXml, $data);
                        $authorizedPath = 'fiscal/nfce/'.$job->id.'/authorized.xml';
                        if (!Storage::disk('local')->put($authorizedPath, $xml)) {
                            throw new RuntimeException('Falha ao armazenar nfeProc.');
                        }
                        $xmlPath = $authorizedPath;
                    } catch (Throwable $error) {
                        $warning = 'Autorizada, mas não foi possível montar XML protocolado: '.$error->getMessage();
                    }
                    $job->update([
                        'status' => 'authorized',
                        'protocol' => $data['protocol'],
                        'xml_path' => $xmlPath,
                        'authorized_at' => now(),
                        'processed_at' => now(),
                        'error_message' => $warning,
                    ]);
                } else {
                    $job->update([
                        'error_message' => 'Consulta SEFAZ: '.($data['cstat'] ?: 'sem status').' - '.$data['reason'].
                            '. Não retransmita sem solucionar a situação.',
                    ]);
                }
            } catch (Throwable $error) {
                $message = str_replace(array_filter([$password, $csc]), '[protegido]', $error->getMessage());
                $job->update(['error_message' => mb_substr($message, 0, 1800), 'processed_at' => now()]);
            } finally {
                try {
                    $service->close();
                } finally {
                    if (is_file($runtimeIni)) {
                        @unlink($runtimeIni);
                    }
                }
            }
        } catch (Throwable $error) {
            if (isset($job) && $job instanceof FiscalDocumentJob && $job->status === 'pending') {
                $job->update([
                    'error_message' => mb_substr($error->getMessage(), 0, 1800),
                    'processed_at' => now(),
                ]);
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
        $issue = app(FiscalDocumentSettings::class)->transmissionIssue('nfce', (string)$job->environment);
        if ($issue !== null) {
            throw new RuntimeException($issue);
        }

        $certPath = Storage::disk('local')->path($company->certificate_path);
        $schemas = (string) config('services.acbr_nfe.schemas_path', '');
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
            ['NFe', 'FormaEmissao', '0'],
            ['NFe', 'ValidarDigest', '1'],
            ['NFe', 'ModeloDF', '1'],
            ['NFe', 'Ambiente', $job->environment === 'homologation' ? '1' : '0'],
            ['NFe', 'VersaoDF', '3'],
            ['NFe', 'IdCSC', (string) AppSetting::value('nfce', 'csc_id', '')],
            ['NFe', 'CSC', $csc],
        ] as [$section, $key, $value]) {
            $service->setConfig($section, $key, $value);
        }
    }

}
