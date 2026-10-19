<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Services\Fiscal\FiscalDocumentSettings;
use App\Services\Fiscal\NFCeCatalogAuditService;
use App\Services\Fiscal\NFCeEnvironmentParameters;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Pré-produção local: somente leitura. Não muda ambiente, estoque, número
 * ou CSC e não chama a SEFAZ/ACBr para transmitir documentos.
 */
final class ACBrNFeReadiness extends Command
{
    protected $signature = 'acbr:nfce-check
        {--environment= : homologation ou production; vazio usa o ambiente atual}
        {--catalog : Verificar a tributação de todas as mercadorias ativas}
        {--limit=20 : Número máximo de erros de produto apresentados}';

    protected $description = 'Audita parâmetros NFC-e e, opcionalmente, os produtos sem transmitir notas';

    public function handle(
        NFCeEnvironmentParameters $parameters,
        FiscalDocumentSettings $settings,
        NFCeCatalogAuditService $catalog,
    ): int {
        $company = CompanySetting::current();
        $requested = trim((string) $this->option('environment'));
        $environment = $requested !== '' ? $requested : $settings->environment('nfce');
        if (!in_array($environment, ['homologation','production'], true)) {
            $this->error('Ambiente inválido: informe homologation ou production.');
            return self::FAILURE;
        }

        $this->line('Auditoria NFC-e: '.($environment==='production'?'produção':'homologação'));
        $certificatePath = (string) $company->certificate_path;
        $hasCertificate = $certificatePath !== '' && Storage::disk('local')->exists($certificatePath);
        $checks = [
            'Emitente na Bahia' => $company->state === 'BA',
            'CNPJ do emitente com 14 dígitos' =>
                strlen(preg_replace('/\\D/', '', (string) $company->document)) === 14,
            'Inscrição estadual presente' => trim((string) $company->state_registration) !== '',
            'Município IBGE com 7 dígitos' =>
                preg_match('/^[0-9]{7}$/', (string) $company->city_ibge_code) === 1,
            'CRT do Simples Nacional válido (1, 2 ou 4)' =>
                in_array((string) $company->crt, ['1','2','4'], true),
            'Certificado A1 no armazenamento privado' => $hasCertificate,
            'Senha de certificado cadastrada' => !empty($company->certificate_password),
            'Parâmetros completos no ambiente escolhido' => $parameters->issues($environment) === [],
            'Emissor NFC-e habilitado' => $settings->enabled('nfce'),
            'Produção autorizada quando escolhida' =>
                $environment !== 'production' || $settings->productionAuthorized('nfce'),
        ];

        $certificateValid = false;
        $notExpired = false;
        if ($hasCertificate && $company->certificate_password && function_exists('openssl_pkcs12_read')) {
            try {
                $bundle = [];
                $certificateValid = @openssl_pkcs12_read(
                    Storage::disk('local')->get($certificatePath),
                    $bundle,
                    (string) $company->certificate_password
                ) && !empty($bundle['cert']) && !empty($bundle['pkey']);
                if ($certificateValid) {
                    $details = @openssl_x509_parse($bundle['cert']);
                    $notExpired = is_array($details)
                        && isset($details['validFrom_time_t'], $details['validTo_time_t'])
                        && $details['validFrom_time_t'] <= time()
                        && $details['validTo_time_t'] > time();
                }
            } catch (\Throwable) {
                $certificateValid = false;
            }
        }
        $checks['Certificado PKCS#12 com chave privada válida'] = $certificateValid;
        $checks['Certificado A1 dentro da validade'] = $notExpired;

        $schemaPath = (string) config('services.acbr_nfe.schemas_path', '');
        $libraryPath = (string) config('services.acbr_nfe.library_path', '');
        $checks['Schemas ACBr configurados no disco'] =
            $schemaPath !== '' && is_dir($schemaPath);
        $checks['ACBrLibNFe encontrada no disco'] =
            $libraryPath !== '' && is_file($libraryPath);
        $checks['FFI disponível no PHP do worker'] = extension_loaded('ffi');

        foreach ($checks as $label => $passed) {
            $this->line(($passed?'[OK] ':'[PENDENTE] ').$label);
        }

        $errors = count(array_filter($checks, static fn(bool $passed): bool => !$passed));
        foreach ($parameters->issues($environment) as $problem) {
            $this->warn('Parâmetro: '.$problem);
        }

        if ($this->option('catalog')) {
            $limit = max(1, min(100, (int) $this->option('limit')));
            $result = $catalog->run($limit);
            $this->line(sprintf(
                'Catálogo: %d mercadorias verificadas, %d com pendências fiscais.',
                $result['checked'], $result['invalid']
            ));
            foreach ($result['examples'] as $problem) {
                $this->warn('Produto: '.$problem);
            }
            $errors += $result['invalid'];
        }

        $this->line('Pendências encontradas: '.$errors);
        $this->line('Auditoria somente leitura. Nenhuma nota, número, certificado ou configuração foi modificado.');
        $this->line('Esta auditoria não comprova autorização SEFAZ nem valida o XML assinado no Windows.');
        return $errors===0 ? self::SUCCESS : self::FAILURE;
    }
}
