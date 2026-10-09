<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use Illuminate\Support\Facades\Storage;

final class NFCePreflightService
{
    public function __construct(
        private readonly NFCeTaxCalculationService $calculator,
        private readonly FiscalDocumentSettings $documentSettings,
    ) {}

    public function validate(FiscalDocumentJob $job): array
    {
        $errors = [];
        if ($job->document_type !== 'nfce' || $job->status !== 'prepared') {
            return ['O documento não está preparado para emissão de NFC-e.'];
        }
        if (!$job->sale || $job->sale->status !== 'completed') {
            $errors[] = 'Venda vinculada não está concluída.';
        }
        if (!in_array($job->environment, ['homologation', 'production'], true)) {
            $errors[] = 'Ambiente fiscal inválido.';
        }
        if ($issue = $this->documentSettings->transmissionIssue('nfce', (string)$job->environment)) {
            $errors[] = $issue;
        }
        if ($job->series === null || (int) $job->series < 0 || (int) $job->series > 999 || (int) $job->document_number < 1) {
            $errors[] = 'Série ou número fiscal inválido.';
        }
        if ($job->emission_mode !== 'normal') {
            $errors[] = 'Contingência offline ainda não está implementada neste emissor.';
        }
        if ($job->access_key || $job->protocol || $job->authorized_at) {
            $errors[] = 'Documento já contém dados de autorização; não reenviar.';
        }

        $company = CompanySetting::current();
        foreach ([
            'CNPJ' => strlen(preg_replace('/\D/', '', (string) $company->document)) === 14,
            'Inscrição estadual' => trim((string) $company->state_registration) !== '',
            'Razão social' => trim((string) $company->legal_name) !== '',
            'Endereço' => trim((string) $company->address) !== '' && trim((string) $company->district) !== '',
            'CEP' => strlen(preg_replace('/\D/', '', (string) $company->zip_code)) === 8,
            'Município IBGE' => preg_match('/^\d{7}$/', (string) $company->city_ibge_code) === 1,
            'CRT do emitente' => in_array((string) $company->crt, ['1','2','3','4'], true),
            'UF BA' => $company->state === 'BA',
            'Certificado A1' => $company->certificate_path && Storage::disk('local')->exists($company->certificate_path),
            'Senha do certificado' => !empty($company->certificate_password),
            'CSC' => (string) AppSetting::value('nfce', 'csc_token', '') !== '',
            'ID CSC' => (string) AppSetting::value('nfce', 'csc_id', '') !== '',
        ] as $label => $ok) {
            if (!$ok) {
                $errors[] = $label.' ausente ou inválido.';
            }
        }

        $source = $job->source_snapshot ?? [];
        $items = $source['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            $errors[] = 'Venda sem itens fiscais.';
        } else {
            foreach ($items as $index => $item) {
                $n = $index + 1;
                $tax = $item['tax_defaults'] ?? [];
                $tax = is_array($tax) ? $tax : [];
                if (!( ((int) ($tax['fiscal_rule_id'] ?? 0) > 0 && (int) ($tax['fiscal_rule_revision'] ?? 0) > 0)
                    || ((int) ($tax['fiscal_group_id'] ?? 0) > 0 && (int) ($tax['fiscal_group_revision'] ?? 0) > 0)
                    || ($tax['fiscal_config_source'] ?? null) === 'product' )) {
                    $errors[] = "Item {$n}: configure um grupo tributário ou uma regra fiscal válida para este produto.";
                }
                $cfop = (string) ($tax['cfop_outbound_internal'] ?? $tax['nfce_cfop'] ?? $tax['cfop'] ?? AppSetting::value('nfce', 'default_cfop', ''));
                $csosn = (string) ($tax['icms_csosn'] ?? $tax['csosn'] ?? $tax['icms_csosn_default'] ?? AppSetting::value('tax', 'icms_csosn_default', ''));
                $pis = (string) ($tax['pis_cst'] ?? $tax['pis_cst_default'] ?? AppSetting::value('tax', 'pis_cst_default', ''));
                $cofins = (string) ($tax['cofins_cst'] ?? $tax['cofins_cst_default'] ?? AppSetting::value('tax', 'cofins_cst_default', ''));
                if (($item['item_type'] ?? '') !== 'product' || !($item['product_id'] ?? null)) {
                    $errors[] = "Item {$n}: somente produto cadastrado pode ser emitido.";
                }
                // O cadastro pode exibir NCM formatado (6913.90.00).
                // O XML exige somente os oito dígitos, como já faz o INI builder.
                $ncmRaw = trim((string) ($item['ncm'] ?? ''));
                $ncm = preg_replace('/\D/', '', $ncmRaw);
                if (preg_match('/^\d{8}$/', $ncm) !== 1
                    || preg_match('/^[0-9.\s-]+$/', $ncmRaw) !== 1) {
                    $errors[] = "Item {$n}: informe NCM de 8 dígitos.";
                }
                if (!preg_match('/^5\d{3}$/', $cfop)) {
                    $errors[] = "Item {$n}: configure CFOP interno válido.";
                }
                // Compartilha o mesmo validador/cálculo utilizado no INI ACBr.
                // Nenhum perfil não implementado pode chegar à transmissão.
                try {
                    $this->calculator->calculate($item, (string)$company->crt);
                } catch (\RuntimeException $exception) {
                    $errors[] = "Item {$n}: ".$exception->getMessage();
                }
                if ((float) ($item['quantity'] ?? 0) <= 0 || (float) ($item['unit_price'] ?? -1) < 0) {
                    $errors[] = "Item {$n}: quantidade ou preço inválido.";
                }
            }
        }

        if ((float) ($source['total'] ?? -1) < 0) {
            $errors[] = 'Total da venda inválido.';
        }
        $payments = $source['payments'] ?? [];
        if (!is_array($payments) || count($payments) === 0) {
            $errors[] = 'Formas de pagamento ausentes.';
        } else {
            $sum = 0;
            foreach ($payments as $payment) {
                $kind = strtolower((string) ($payment['payment_kind'] ?? $payment['payment_method'] ?? ''));
                if ($kind === 'card') {
                    $code = strtolower((string) ($payment['payment_method'] ?? ''));
                    $kind = str_contains($code, 'debit') || str_contains($code, 'debito') ? 'debit_card' : (str_contains($code, 'credit') || str_contains($code, 'credito') ? 'credit_card' : 'card');
                }
                if (!in_array($kind, ['cash', 'money', 'pix', 'credit_card', 'debit_card', 'bank_slip', 'bank_transfer', 'transfer'], true)) {
                    $errors[] = 'Forma de pagamento sem mapeamento fiscal: '.$kind;
                }
                $sum += (int) round((float) ($payment['amount'] ?? 0) * 100);
            }
            if (abs($sum - (int) round((float) ($source['total'] ?? 0) * 100)) > 1) {
                $errors[] = 'Pagamentos não conciliam com o total.';
            }
        }
        return $errors;
    }
}
