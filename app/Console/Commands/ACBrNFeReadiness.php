<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class ACBrNFeReadiness extends Command
{
    protected $signature = 'acbr:nfce-check';
    protected $description = 'Confere o cadastro fiscal e os arquivos locais sem revelar credenciais ou transmitir notas';

    public function handle(): int
    {
        $company = CompanySetting::current();
        $environment = (string) AppSetting::value('nfce', 'environment', '');
        $id = trim((string) AppSetting::value('nfce', 'csc_id', ''));
        $token = trim((string) AppSetting::value('nfce', 'csc_token', ''));
        $certificatePath = (string) $company->certificate_path;
        $checks = [
            'Empresa BA' => $company->state === 'BA',
            'CNPJ informado' => strlen(preg_replace('/\\D/', '', (string) $company->document)) === 14,
            'IE informada' => trim((string) $company->state_registration) !== '',
            'Código IBGE válido' => $company->city_ibge_code === '2926806',
            'CRT informado' => trim((string) $company->crt) !== '',
            'Certificado no disco privado' => $certificatePath !== '' && Storage::disk('local')->exists($certificatePath),
            'Senha A1 cadastrada' => !empty($company->certificate_password),
            'Homologação NFC-e' => $environment === 'homologation',
            'CSC ID configurado' => $id !== '',
            'CSC token configurado' => $token !== '',
            'Série NFC-e válida' => (int) AppSetting::value('nfce', 'series', -1) >= 0 && (int) AppSetting::value('nfce', 'series', -1) <= 999,
            'Número NFC-e válido' => (int) AppSetting::value('nfce', 'next_number', 0) >= 1,
            'NFC-e habilitada' => (bool) AppSetting::value('nfce', 'enabled', false),
        ];
        $certIsValid = false;
        if ($certificatePath !== '' && Storage::disk('local')->exists($certificatePath) && function_exists('openssl_pkcs12_read')) {
            try {
                $bytes = Storage::disk('local')->get($certificatePath);
                $certs = [];
                $certIsValid = @openssl_pkcs12_read($bytes, $certs, (string) $company->certificate_password)
                    && !empty($certs['cert'])
                    && !empty($certs['pkey']);
            } catch (\Throwable) {
                $certIsValid = false;
            }
        }
        $checks['PKCS#12 abre com chave privada'] = $certIsValid;

        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '[OK] ' : '[PENDENTE] ').$label);
        }
        $failures = count(array_filter($checks, fn ($ok) => !$ok));
        $this->line('Pendências: '.$failures);
        $this->line('Nenhum segredo foi exibido. Nenhum documento foi transmitido.');
        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
