<?php

namespace App\Services\Fiscal;

use App\Models\AppSetting;
use App\Models\CompanySetting;
use App\Models\FiscalDocumentJob;
use RuntimeException;

final class NFCeIniBuilder
{
    public function __construct(private readonly NFCeTaxCalculationService $calculator) {}

    public function build(FiscalDocumentJob $job, CompanySetting $company): string
    {
        $source = $job->source_snapshot ?? [];
        $items = $source['items'] ?? [];
        if (!$items) {
            throw new RuntimeException('Documento sem itens.');
        }

        $digits = fn ($value) => preg_replace('/\D/', '', (string) $value);
        $text = fn ($value) => trim(str_replace(["\r", "\n", '=', '[', ']'], ' ', (string) $value));
        $price = fn ($value) => number_format((float) $value, 2, '.', '');
        $sections = [];
        $add = function (string $name, array $fields) use (&$sections, $text): void {
            $lines = ["[{$name}]"];
            foreach ($fields as $key => $value) {
                if ($value !== null && (string) $value !== '') {
                    $lines[] = $key.'='.$text($value);
                }
            }
            $sections[] = implode("\r\n", $lines);
        };

        $cNF = sprintf('%08d', random_int(1, 99999999));
        $add('infNFe', ['versao' => '4.00']);
        $add('Identificacao', [
            'cUF' => '29',
            'cNF' => $cNF,
            'natOp' => 'VENDA DE MERCADORIA',
            'Modelo' => '65',
            'serie' => (string) $job->series,
            'nNF' => (string) $job->document_number,
            'dhEmi' => now()->timezone($company->timezone ?: 'America/Sao_Paulo')->format('d/m/Y H:i:s'),
            'tpNF' => '1',
            'idDest' => '1',
            'tpAmb' => $job->environment === 'homologation' ? '2' : '1',
            'tpImp' => '4',
            'tpEmis' => '1',
            'finNFe' => '1',
            'indFinal' => '1',
            'indPres' => '1',
            'procEmi' => '0',
            'verProc' => 'NEXTOR 1.0',
            'cMunFG' => $digits($company->city_ibge_code),
        ]);
        $add('Emitente', [
            'CRT' => $company->crt,
            'CNPJCPF' => $digits($company->document),
            'xNome' => $company->legal_name,
            'xFant' => $company->trade_name ?: null,
            'IE' => $digits($company->state_registration),
            'xLgr' => $company->address,
            'nro' => $company->address_number,
            'xCpl' => $company->address_complement,
            'xBairro' => $company->district,
            'cMun' => $digits($company->city_ibge_code),
            'xMun' => $company->city,
            'cUF' => '29',
            'UF' => 'BA',
            'CEP' => $digits($company->zip_code),
            'cPais' => '1058',
            'xPais' => 'BRASIL',
            'Fone' => $digits($company->phone),
        ]);

        // O destinatário é facultativo na NFC-e sem identificação; não inventar dados.
        if (($consumer = $digits($source['consumer_document'] ?? '')) !== '') {
            $add('Destinatario', [
                'CNPJCPF' => $consumer,
                'xNome' => $job->environment === 'homologation'
                    ? 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL'
                    : ($source['consumer_name'] ?? null),
                'indIEDest' => '9',
            ]);
        }

        $totalGross = 0;
        $totalDiscount = 0;
        $totalPis = 0;
        $totalCofins = 0;
        $totalIcmsBase = 0;
        $totalIcms = 0;
        foreach ($items as $index => $item) {
            $i = sprintf('%03d', $index + 1);
            $tax = $item['tax_defaults'] ?? [];
            if (!is_array($tax)) {
                $tax = [];
            }
            $qty = (float) $item['quantity'];
            $unit = (float) $item['unit_price'];
            $gross = round($qty * $unit, 2);
            $discount = isset($item['discount'])
                ? round((float) $item['discount'], 2)
                : round($gross - (float) $item['line_total'], 2);
            $totalGross += (int) round($gross * 100);
            $totalDiscount += (int) round($discount * 100);
            $cfop = $tax['cfop_outbound_internal'] ?? $tax['nfce_cfop'] ?? $tax['cfop'] ?? AppSetting::value('nfce', 'default_cfop', '');
            $calculated = $this->calculator->calculate($item, (string)$company->crt);
            $totalPis += (int) round((float)($calculated['pis']['vPIS'] ?? 0) * 100);
            $totalCofins += (int) round((float)($calculated['cofins']['vCOFINS'] ?? 0) * 100);
            $totalIcmsBase += (int) round((float)($calculated['icms']['vBC'] ?? 0) * 100);
            $totalIcms += (int) round((float)($calculated['icms']['vICMS'] ?? 0) * 100);

            $add('Produto'.$i, [
                'cProd' => ($item['sku'] ?? null) ?: 'PROD-'.$item['product_id'],
                'cEAN' => $digits($item['gtin'] ?? '') ?: 'SEM GTIN',
                // A SEFAZ exige a descrição especial somente no primeiro
                // item da NFC-e em homologação (regra I04-10 / rejeição 373).
                // Os demais itens mantêm o nome real do produto no XML.
                'xProd' => $job->environment === 'homologation' && $index === 0
                    ? 'NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL'
                    : $item['name'],
                'NCM' => $digits($item['ncm'] ?? ''),
                'CEST' => $digits($item['cest'] ?? ''),
                'CFOP' => $cfop,
                'uCom' => ($item['unit'] ?? null) ?: 'UN',
                'qCom' => number_format($qty, 3, '.', ''),
                'vUnCom' => number_format($unit, 4, '.', ''),
                'vProd' => $price($gross),
                'uTrib' => $item['unit'] ?: 'UN',
                'qTrib' => number_format($qty, 3, '.', ''),
                'vUnTrib' => number_format($unit, 4, '.', ''),
                'cEANTrib' => $digits($item['gtin'] ?? '') ?: 'SEM GTIN',
                'vDesc' => $price($discount),
                'indTot' => '1',
            ]);
            $add('ICMS'.$i, $calculated['icms']);
            $add('PIS'.$i, $calculated['pis']);
            $add('COFINS'.$i, $calculated['cofins']);
        }

        $total = $price($source['total'] ?? 0);
        if (abs(($totalGross - $totalDiscount) / 100 - (float) $total) > 0.01) {
            throw new RuntimeException('Somatório dos itens não corresponde ao total da venda.');
        }
        $add('Total', [
            'vBC' => $price($totalIcmsBase / 100), 'vICMS' => $price($totalIcms / 100),
            'vBCST' => '0.00', 'vST' => '0.00',
            'vProd' => $price($totalGross / 100),
            'vDesc' => $price($totalDiscount / 100),
            'vPIS' => $price($totalPis / 100), 'vCOFINS' => $price($totalCofins / 100),
            'vNF' => $total,
        ]);
        $add('Transportador', ['modFrete' => '9']);

        $methods = [
            'cash' => '01', 'money' => '01', 'pix' => '17',
            'credit_card' => '03', 'debit_card' => '04',
            'bank_slip' => '15', 'bank_transfer' => '18', 'transfer' => '18',
        ];
        $cashChangeAssigned = false;
        foreach (($source['payments'] ?? []) as $index => $payment) {
            $kind = (string) ($payment['payment_kind'] ?? '');
            $kind = $kind !== '' ? $kind : strtolower((string) ($payment['payment_method'] ?? ''));
            if ($kind === 'card') {
                $code = strtolower((string) ($payment['payment_method'] ?? ''));
                $kind = str_contains($code, 'debit') || str_contains($code, 'debito') ? 'debit_card' : (str_contains($code, 'credit') || str_contains($code, 'credito') ? 'credit_card' : 'card');
            }
            $paymentType = $methods[$kind] ?? null;
            if ($paymentType === null) {
                throw new RuntimeException('Forma de pagamento sem mapeamento NFC-e: '.$text($kind));
            }
            $fields = [
                'tPag' => $paymentType,
                'vPag' => $price($payment['amount']),
            ];
            if (in_array($paymentType, ['03', '04'], true)) {
                $fields += [
                    'tpIntegra' => $payment['integration_type'] ?? '2',
                    'CNPJ' => $digits($payment['transaction_document'] ?? ''),
                    'tBand' => $payment['card_brand'] ?? null,
                    'cAut' => $payment['authorization_code'] ?? null,
                ];
            }
            if ((float) ($source['change_amount'] ?? 0) > 0 && !$cashChangeAssigned && in_array($kind, ['cash', 'money'], true)) {
                // O valor recebido em espécie inclui o troco; o lançamento da
                // venda/financeiro permanece pelo valor efetivo da parcela.
                $fields['vPag'] = $price((float) $payment['amount'] + (float) $source['change_amount']);
                $cashChangeAssigned = true;
            }
            // A ACBr lê vTroco como total do grupo pag e pode sobrescrevê-lo
            // ao processar as seções seguintes. Informar somente na última.
            if ($index === count($source['payments'] ?? []) - 1 && (float) ($source['change_amount'] ?? 0) > 0) {
                $fields['vTroco'] = $price($source['change_amount']);
            }
            $add('pag'.sprintf('%03d', $index + 1), $fields);
        }

        if ((float) ($source['change_amount'] ?? 0) > 0 && !$cashChangeAssigned) {
            throw new RuntimeException('Troco em dinheiro não corresponde a nenhum pagamento em espécie.');
        }

        return implode("\r\n\r\n", $sections)."\r\n";
    }
}
