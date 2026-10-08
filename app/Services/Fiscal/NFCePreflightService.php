<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;

/**
 * Validação determinística antes de enviar qualquer NFC-e à SEFAZ.
 * Nunca altera venda, estoque, financeiro ou numeração reservada.
 */
final class NFCePreflightService
{
    public function validate(FiscalDocumentJob $job): array
    {
        $errors = [];
        if ($job->document_type !== 'nfce') {
            return ['Documento não é uma NFC-e.'];
        }
        if ($job->status !== 'prepared') {
            return ['Documento não está preparado para emissão.'];
        }
        if (!$job->sale || $job->sale->status !== 'completed') {
            $errors[] = 'Venda vinculada não está concluída.';
        }
        if (!in_array($job->environment, ['homologation', 'production'], true)) {
            $errors[] = 'Ambiente fiscal inválido.';
        }
        if ($job->series === null || (int) $job->series < 0 || (int) $job->series > 999 || (int) $job->document_number < 1) {
            $errors[] = 'Série ou número da NFC-e inválidos.';
        }
        if ($job->access_key || $job->protocol || $job->authorized_at) {
            $errors[] = 'Documento já contém identificação de autorização; não reenviar automaticamente.';
        }

        $company = CompanySetting::current();
        if (strlen(preg_replace('/\D/', '', (string) $company->document)) !== 14) {
            $errors[] = 'CNPJ do emitente inválido.';
        }
        if (trim((string) $company->state_registration) === '') {
            $errors[] = 'Inscrição estadual ausente.';
        }
        if ($company->state !== 'BA' || strlen((string) $company->city_ibge_code) !== 7) {
            $errors[] = 'UF ou município IBGE inválido.';
        }
        if (!in_array((string) $company->crt, ['1', '2', '3', '4'], true)) {
            $errors[] = 'CRT do emitente inválido.';
        }

        $snapshot = $job->source_snapshot ?? [];
        if (empty($snapshot['items']) || !is_array($snapshot['items'])) {
            $errors[] = 'Venda sem itens fiscais.';
        } else {
            foreach ($snapshot['items'] as $index => $item) {
                $n = $index + 1;
                if (($item['item_type'] ?? '') !== 'product') {
                    $errors[] = "Item {$n}: NFC-e não aceita serviço neste fluxo.";
                }
                if (!preg_match('/^\d{8}$/', (string) ($item['ncm'] ?? ''))) {
                    $errors[] = "Item {$n}: NCM de oito dígitos obrigatório.";
                }
                if ((float) ($item['quantity'] ?? 0) <= 0 || (float) ($item['unit_price'] ?? 0) < 0) {
                    $errors[] = "Item {$n}: quantidade ou valor inválido.";
                }
            }
        }

        if ((float) ($snapshot['total'] ?? -1) < 0) {
            $errors[] = 'Total da venda inválido.';
        }
        if (empty($snapshot['payments']) || !is_array($snapshot['payments'])) {
            $errors[] = 'Meio de pagamento não identificado.';
        }

        return $errors;
    }
}
