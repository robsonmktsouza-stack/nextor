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
        // Correction/duplication can create multiple fiscal attempts for one
        // commercial sale, but never a second authorized or active NFC-e.
        if ($job->sale_id && FiscalDocumentJob::query()
            ->where('id', '!=', $job->id)
            ->where('sale_id', $job->sale_id)
            ->where('document_type', 'nfce')
            ->whereIn('status', ['authorized','processing','offline_signed','offline_sending'])
            ->exists()) {
            $errors[] = 'A venda já possui outra NFC-e autorizada ou em processamento.';
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
        if (!in_array($job->emission_mode,['normal','offline'],true)) {
            $errors[] = 'Modalidade de emissão fiscal inválida.';
        }
        if ($job->emission_mode==='offline' &&
            (!$job->contingency_started_at || mb_strlen(trim((string)$job->contingency_reason))<15
                || $job->contingency_started_at->isFuture())) {
            $errors[] = 'Contingência exige motivo com 15 caracteres e data/hora válida.';
        }
        if ($job->access_key || $job->protocol || $job->authorized_at) {
            $errors[] = 'Documento já contém dados de autorização; não reenviar.';
        }

        $params=app(NFCeEnvironmentParameters::class);
        foreach ($params->issues((string)$job->environment) as $issue) {
            $errors[]=$issue;
        }

        $company = CompanySetting::current();
        if (!\App\Support\InstanceIdentity::matches($company->document)) {
            $errors[] = 'CNPJ do emitente não corresponde à instalação contratada.';
        }
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
        ] as $label => $ok) {
            if (!$ok) {
                $errors[] = $label.' ausente ou inválido.';
            }
        }

        $source = $job->source_snapshot ?? [];
        $items = $source['items'] ?? [];
        $rtc = new NFCeRtcTaxService();
        $valueGuard = new NFCeItemValueGuard();
        $rtcItemCount = 0;
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
                    $valueGuard->verify($item);
                    $valueGuard->verifyGtin($item['gtin'] ?? null);
                    $calculated = $this->calculator->calculate($item, (string)$company->crt);
                    if ($rtc->calculate($item, (string)$company->crt, now()->toDateString(), $calculated) !== null) {
                        $rtcItemCount++;
                    }
                } catch (\RuntimeException $exception) {
                    $errors[] = "Item {$n}: ".$exception->getMessage();
                }
                if ((float) ($item['quantity'] ?? 0) <= 0 || (float) ($item['unit_price'] ?? -1) < 0) {
                    $errors[] = "Item {$n}: quantidade ou preço inválido.";
                }
            }
        }

        if (is_array($items) && $rtcItemCount > 0 && $rtcItemCount !== count($items)) {
            $errors[] = 'RTC: a nota mistura produtos com e sem IBS/CBS. Configure a classificação em todos os itens do documento.';
        }
        if ((float) ($source['total'] ?? -1) < 0) {
            $errors[] = 'Total da venda inválido.';
        }
        $payments = $source['payments'] ?? [];
        if (!is_array($payments) || count($payments) === 0) {
            $errors[] = 'Formas de pagamento ausentes.';
        } else {
            $sum = 0;
            foreach ($payments as $index => $payment) {
                if (!is_array($payment)) {
                    $errors[] = 'Pagamento '.($index + 1).': dados inválidos.';
                    continue;
                }
                $kind = \App\Models\PaymentMethod::resolveFiscalKind(
                    (string) ($payment['payment_kind'] ?? $payment['payment_method'] ?? ''),
                    (string) ($payment['payment_method'] ?? '')
                );
                if (!in_array($kind, ['cash', 'money', 'pix', 'credit_card', 'debit_card', 'bank_slip', 'bank_transfer', 'transfer'], true)) {
                    $errors[] = 'Forma de pagamento sem mapeamento fiscal: '.$kind;
                }
                $number = $index + 1;
                if (!is_numeric($payment['amount'] ?? null) || (float)$payment['amount']<=0) {
                    $errors[] = "Pagamento {$number}: informe um valor maior que zero.";
                }
                $integration = (string) ($payment['integration_type'] ?? '');
                $isCard = in_array($kind, ['credit_card','debit_card'], true);
                if ($isCard && !in_array($integration, ['1','2'], true)) {
                    $errors[] = "Pagamento {$number}: informe se o cartão é integrado (TEF) ou não integrado (POS).";
                }
                if ($kind === 'pix' && $integration !== '' && !in_array($integration, ['1','2'], true)) {
                    $errors[] = "Pagamento {$number}: integração PIX inválida.";
                }

                $transactionDocument = preg_replace('/\\D/', '', (string) ($payment['transaction_document'] ?? ''));
                $transactionState = strtoupper(trim((string) ($payment['transaction_state'] ?? '')));
                if (($transactionDocument !== '') xor ($transactionState !== '')) {
                    $errors[] = "Pagamento {$number}: CNPJ e UF da transação devem ser informados juntos.";
                }
                if ($transactionDocument !== '' && preg_match('/^[0-9]{14}$/', $transactionDocument) !== 1) {
                    $errors[] = "Pagamento {$number}: CNPJ da transação inválido.";
                }
                $states = [
                    'AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG',
                    'PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO',
                ];
                if ($transactionState !== '' && !in_array($transactionState, $states, true)) {
                    $errors[] = "Pagamento {$number}: UF da transação inválida.";
                }

                $acquirer = preg_replace('/\\D/', '', (string) ($payment['institution_document'] ?? ''));
                $beneficiary = preg_replace('/\\D/', '', (string) ($payment['beneficiary_document'] ?? ''));
                foreach (['adquirente'=>$acquirer,'beneficiário'=>$beneficiary] as $label=>$document) {
                    if ($document !== '' && preg_match('/^[0-9]{14}$/', $document) !== 1) {
                        $errors[] = "Pagamento {$number}: CNPJ do {$label} inválido.";
                    }
                }
                if ($isCard && $integration === '1'
                    && ($acquirer === '' || trim((string) ($payment['authorization_code'] ?? '')) === '')) {
                    $errors[] = "Pagamento {$number}: cartão integrado exige CNPJ da adquirente e código de autorização.";
                }

                $brand = trim((string) ($payment['card_brand'] ?? ''));
                if ($isCard && $brand !== '' && !in_array($brand, [
                    '01','02','03','04','05','06','07','08','09','99'
                ], true)) {
                    $errors[] = "Pagamento {$number}: bandeira do cartão inválida.";
                }
                if (!in_array($kind,['credit_card','debit_card','pix'],true)
                    && ($integration!=='' || $brand!=='' || $acquirer!==''
                        || $beneficiary!=='' || $transactionDocument!==''
                        || trim((string)($payment['authorization_code'] ?? ''))!==''
                        || trim((string)($payment['terminal_id'] ?? ''))!=='')) {
                    $errors[] = "Pagamento {$number}: dados eletrônicos incompatíveis com a forma selecionada.";
                }
                $sum += (int) round((float) ($payment['amount'] ?? 0) * 100);
            }
            if ($sum !== (int) round((float) ($source['total'] ?? 0) * 100)) {
                $errors[] = 'Pagamentos não conciliam com o total.';
            }
        }
        return $errors;
    }
}
