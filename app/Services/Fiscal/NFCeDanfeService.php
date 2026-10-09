<?php

namespace App\Services\Fiscal;

use App\Models\FiscalDocumentJob;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Dados do DANFE NFC-e sempre extraídos do XML processado e autorizado.
 * Não usa o recibo interno, cadastro atual do produto ou valores da venda.
 *
 * Manual DANFE NFC-e / QR Code v6.0 (ENCAT, março/2025).
 */
final class NFCeDanfeService
{
    private const NS = 'http://www.portalfiscal.inf.br/nfe';
    private const TZ = 'America/Bahia';

    public function parse(string $xml, FiscalDocumentJob $job): array
    {
        $offline=$job->emission_mode==='offline'
            && in_array($job->status,['offline_signed','offline_print_pending','offline_sending','pending'],true);
        if ($job->document_type!=='nfce') {
            throw new RuntimeException('Modelo de DANFE inválido.');
        }
        if (!$offline && ($job->document_type!=='nfce'||$job->status!=='authorized')) {
            throw new RuntimeException('O DANFE NFC-e só pode ser impresso para documento autorizado.');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadXML($xml, LIBXML_NONET)) {
                throw new RuntimeException('XML autorizado inválido ou corrompido.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }

        if ($dom->documentElement?->namespaceURI !== self::NS
            || $dom->documentElement?->localName !== ($offline?'NFe':'nfeProc')) {
            throw new RuntimeException($offline
                ? 'Contingência exige o XML NFC-e assinado.'
                : 'É necessário o XML nfeProc autorizado.');
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('n', self::NS);
        $info = $xp->query($offline?'/n:NFe/n:infNFe':'/n:nfeProc/n:NFe/n:infNFe')->item(0);
        $protocol = $offline?null:$xp->query('/n:nfeProc/n:protNFe/n:infProt')->item(0);
        if (!$info instanceof DOMElement || (!$offline && !$protocol instanceof DOMElement)) {
            throw new RuntimeException('XML sem NFC-e e protocolo de autorização completos.');
        }

        $read = static fn (string $query, ?DOMElement $at = null): string
            => trim((string) $xp->evaluate('string('.$query.')', $at));

        $key = substr($info->getAttribute('Id'), 3);
        $number = $read('n:ide/n:nNF', $info);
        $series = $read('n:ide/n:serie', $info);
        $environment = $read('n:ide/n:tpAmb', $info);
        $xmlProtocol = $offline?'':$read('n:nProt', $protocol);
        $xmlKey = $offline?$key:$read('n:chNFe', $protocol);
        $cstat = $offline?'':$read('n:cStat', $protocol);

        if (preg_match('/^\d{44}$/', $key) !== 1
            || !hash_equals($key, (string) $job->access_key)
            || !hash_equals($key, $xmlKey)
            || (!$offline && !hash_equals($xmlProtocol, (string) $job->protocol))
            || (!$offline && !in_array($cstat, ['100', '150'], true))
            || $read('n:ide/n:mod', $info) !== '65'
            || !ctype_digit($number) || (int) $number !== (int) $job->document_number
            || !ctype_digit($series) || (int) $series !== (int) $job->series
            || $environment !== ($job->environment === 'homologation' ? '2' : '1')
            || ($offline && $read('n:ide/n:tpEmis',$info)!=='9')
            || (!$offline && $read('n:tpAmb', $protocol) !== $environment)) {
            throw new RuntimeException('XML, autorização, ambiente ou numeração não correspondem à NFC-e registrada.');
        }

        $qr = $read($offline?'/n:NFe/n:infNFeSupl/n:qrCode':'/n:nfeProc/n:NFe/n:infNFeSupl/n:qrCode');
        $url = $read($offline?'/n:NFe/n:infNFeSupl/n:urlChave':'/n:nfeProc/n:NFe/n:infNFeSupl/n:urlChave');
        $qrHost = strtolower((string) parse_url($qr, PHP_URL_HOST));
        $urlHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $validHost = static fn (string $host): bool => $host === 'sefaz.ba.gov.br'
            || str_ends_with($host, '.sefaz.ba.gov.br');
        if ($qr === '' || strlen($qr) > 3000 || !str_contains($qr, $key)
            || !in_array(parse_url($qr, PHP_URL_SCHEME), ['http', 'https'], true)
            || !$validHost($qrHost)
            || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            || !$validHost($urlHost)) {
            throw new RuntimeException('Links originais de consulta/QR Code da SEFAZ-BA ausentes ou inconsistentes.');
        }

        $items = [];
        foreach ($xp->query('n:det', $info) as $det) {
            $items[] = [
                'code' => $read('n:prod/n:cProd', $det),
                'description' => $read('n:prod/n:xProd', $det),
                'qty' => (float) $read('n:prod/n:qCom', $det),
                'unit' => $read('n:prod/n:uCom', $det),
                'unit_price' => (float) $read('n:prod/n:vUnCom', $det),
                'total' => (float) $read('n:prod/n:vProd', $det),
            ];
        }
        if (!$items) {
            throw new RuntimeException('NFC-e autorizada sem detalhamento de itens.');
        }

        $payments = [];
        foreach ($xp->query('n:pag/n:detPag', $info) as $row) {
            $code = $read('n:tPag', $row);
            $payments[] = [
                'type' => $this->paymentName($code),
                'amount' => (float) $read('n:vPag', $row),
            ];
        }

        $emit = $xp->query('n:emit', $info)->item(0);
        $address = $emit instanceof DOMElement
            ? $xp->query('n:enderEmit', $emit)->item(0) : null;
        $recipient = $xp->query('n:dest', $info)->item(0);
        $recipientCnpj = $recipient instanceof DOMElement ? $read('n:CNPJ', $recipient) : '';
        $recipientCpf = $recipient instanceof DOMElement ? $read('n:CPF', $recipient) : '';
        $recipientForeign = $recipient instanceof DOMElement ? $read('n:idEstrangeiro', $recipient) : '';
        $recipientId = $recipientCnpj ?: $recipientCpf ?: $recipientForeign;
        $recipientType = $recipientCnpj ? 'CNPJ' : ($recipientCpf ? 'CPF' : ($recipientForeign ? 'Id. Estrangeiro' : null));

        $issuerTaxId = $read('n:emit/n:CNPJ', $info) ?: $read('n:emit/n:CPF', $info);
        $total = $xp->query('n:total/n:ICMSTot', $info)->item(0);
        if (!$total instanceof DOMElement || !$issuerTaxId || (!$offline && !$xmlProtocol)) {
            throw new RuntimeException('XML sem emitente, total ou protocolo válidos para impressão.');
        }

        return [
            'issuer' => [
                'name' => $read('n:emit/n:xNome', $info),
                'tax_id' => $issuerTaxId,
                'address' => $address instanceof DOMElement
                    ? implode(', ', array_filter([
                        $read('n:xLgr', $address),
                        $read('n:nro', $address),
                        $read('n:xCpl', $address),
                        $read('n:xBairro', $address),
                    ])) : '',
                'city' => $address instanceof DOMElement
                    ? trim($read('n:xMun', $address).' / '.$read('n:UF', $address), ' /') : '',
                'zip' => $address instanceof DOMElement ? $read('n:CEP', $address) : '',
            ],
            'items' => $items,
            'count' => count($items),
            'totals' => [
                'items' => (float) $read('n:vProd', $total),
                'freight' => (float) $read('n:vFrete', $total),
                'insurance' => (float) $read('n:vSeg', $total),
                'other' => (float) $read('n:vOutro', $total),
                'discount' => (float) $read('n:vDesc', $total),
                'amount' => (float) $read('n:vNF', $total),
                'change' => (float) $read('n:pag/n:vTroco', $info),
                'taxes' => $read('n:vTotTrib', $total),
            ],
            'payments' => $payments,
            'key' => $key,
            'key_groups' => implode(' ', str_split($key, 4)),
            'series' => (int) $series,
            'number' => (int) $number,
            'issued_at' => $this->localDate($read('n:ide/n:dhEmi', $info)),
            'authorized_at' => $offline?'':$this->localDate($read('n:dhRecbto', $protocol)),
            'offline' => $offline,
            'protocol' => $xmlProtocol,
            'environment' => $environment,
            'homologation' => $environment === '2',
            'consumer_id' => $recipientId,
            'consumer_type' => $recipientType,
            'consumer_name' => $recipient instanceof DOMElement ? $read('n:xNome', $recipient) : '',
            'delivery_address' => $recipient instanceof DOMElement ? $read('n:enderDest/n:xLgr', $recipient) : '',
            'qr_url' => $qr,
            'consultation_url' => $url,
            'fiscal_message' => $read('n:infAdic/n:infAdFisco', $info),
            'additional_message' => $read('n:infAdic/n:infCpl', $info),
        ];
    }

    private function localDate(string $value): string
    {
        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone(self::TZ))
                ->format('d/m/Y H:i:s');
        } catch (\Throwable) {
            throw new RuntimeException('Data do XML fiscal ausente ou inválida.');
        }
    }

    private function paymentName(string $code): string
    {
        return [
            '01' => 'Dinheiro', '02' => 'Cheque', '03' => 'Cartão de crédito',
            '04' => 'Cartão de débito', '05' => 'Crédito loja', '10' => 'Vale alimentação',
            '11' => 'Vale refeição', '12' => 'Vale presente', '13' => 'Vale combustível',
            '15' => 'Boleto', '16' => 'Depósito bancário', '17' => 'PIX',
            '18' => 'Transferência bancária', '19' => 'Programa de fidelidade',
            '90' => 'Sem pagamento', '99' => 'Outros',
        ][$code] ?? 'Pagamento '.$code;
    }
}
