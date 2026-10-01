<?php

namespace App\Fiscal\Xml;

use App\Fiscal\Exceptions\InvalidFiscalDataException;
use App\Fiscal\Models\FiscalDocument;
use DOMDocument;
use DOMElement;

final class NfceXmlGenerator
{
    private const NS = 'http://www.portalfiscal.inf.br/nfe';

    private const ICMS_TOTAL_ORDER = [
        'vBC', 'vICMS', 'vICMSDeson',
        'vFCPUFDest', 'vICMSUFDest', 'vICMSUFRemet',
        'vFCP', 'vBCST', 'vST', 'vFCPST', 'vFCPSTRet',
        'qBCMono', 'vICMSMono', 'qBCMonoReten', 'vICMSMonoReten', 'qBCMonoRet', 'vICMSMonoRet',
        'vProd', 'vFrete', 'vSeg', 'vDesc', 'vII', 'vIPI', 'vIPIDevol',
        'vPIS', 'vCOFINS', 'vOutro', 'vNF', 'vTotTrib',
    ];

    private const TAX_GROUP_ORDER = [
        'ICMS', 'IPI', 'II', 'PIS', 'PISST', 'COFINS', 'COFINSST',
        'ICMSUFDest', 'IS', 'IBSCBS',
    ];

    public function generate(FiscalDocument $document): string
    {
        if (!$document->snapshotIntegrityIsValid()) {
            throw new InvalidFiscalDataException('Integridade do snapshot fiscal inválida.');
        }

        if ($document->model !== '65') {
            throw new InvalidFiscalDataException('O gerador atual aceita somente NFC-e modelo 65.');
        }

        if (!$document->access_key || strlen($document->access_key) !== 44) {
            throw new InvalidFiscalDataException('Documento fiscal não possui chave de acesso válida.');
        }

        $snapshot = $document->snapshot();
        $issuer = $snapshot->issuer();
        $identification = $snapshot->identification();

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = false;

        $nfe = $dom->createElementNS(self::NS, 'NFe');
        $dom->appendChild($nfe);

        $infNFe = $this->element($dom, $nfe, 'infNFe');
        $infNFe->setAttribute('Id', 'NFe'.$document->access_key);
        $infNFe->setAttribute('versao', $document->layout_version);

        $this->appendIde($dom, $infNFe, $document, $snapshot->issuedAt(), $identification);
        $this->appendIssuer($dom, $infNFe, $issuer);

        $recipient = $snapshot->recipient();
        if ($recipient !== null) {
            $this->appendRecipient($dom, $infNFe, $recipient);
        }

        foreach ($snapshot->items() as $index => $item) {
            $this->appendItem($dom, $infNFe, $index + 1, $item);
        }

        $this->appendTotals($dom, $infNFe, $snapshot->totals());

        $transp = $this->element($dom, $infNFe, 'transp');
        $this->element($dom, $transp, 'modFrete', $snapshot->freightMode());

        $this->appendPayments($dom, $infNFe, $snapshot->payments(), $snapshot->toArray()['change'] ?? null);

        if ($snapshot->additionalInfo() !== null) {
            $infAdic = $this->element($dom, $infNFe, 'infAdic');
            $this->element($dom, $infAdic, 'infCpl', $snapshot->additionalInfo());
        }

        $xml = $dom->saveXML();

        if (!is_string($xml) || $xml === '') {
            throw new InvalidFiscalDataException('Falha ao serializar o XML da NFC-e.');
        }

        return $xml;
    }

    private function appendIde(
        DOMDocument $dom,
        DOMElement $parent,
        FiscalDocument $document,
        string $issuedAt,
        array $ide,
    ): void {
        $node = $this->element($dom, $parent, 'ide');

        $this->element($dom, $node, 'cUF', substr($document->access_key, 0, 2));
        $this->element($dom, $node, 'cNF', $document->numeric_code);
        $this->element($dom, $node, 'natOp', $ide['nature_operation']);
        $this->element($dom, $node, 'mod', $document->model);
        $this->element($dom, $node, 'serie', (string) $document->series);
        $this->element($dom, $node, 'nNF', (string) $document->number);
        $this->element($dom, $node, 'dhEmi', $issuedAt);
        $this->element($dom, $node, 'tpNF', $ide['operation_type']);
        $this->element($dom, $node, 'idDest', $ide['destination']);
        $this->element($dom, $node, 'cMunFG', $ide['city_tax_code']);

        if (isset($ide['ibs_city_tax_code']) && $ide['ibs_city_tax_code'] !== '') {
            $this->element($dom, $node, 'cMunFGIBS', $ide['ibs_city_tax_code']);
        }

        $this->element($dom, $node, 'tpImp', $ide['print_type']);
        $this->element($dom, $node, 'tpEmis', (string) $document->emission_type);
        $this->element($dom, $node, 'cDV', substr($document->access_key, -1));
        $this->element($dom, $node, 'tpAmb', $document->environment->value);
        $this->element($dom, $node, 'finNFe', $ide['purpose']);

        if (isset($ide['debit_note_type']) && $ide['debit_note_type'] !== '') {
            $this->element($dom, $node, 'tpNFDebito', $ide['debit_note_type']);
        }

        if (isset($ide['credit_note_type']) && $ide['credit_note_type'] !== '') {
            $this->element($dom, $node, 'tpNFCredito', $ide['credit_note_type']);
        }

        $this->element($dom, $node, 'indFinal', $ide['final_consumer']);
        $this->element($dom, $node, 'indPres', $ide['presence']);

        if (isset($ide['intermediary']) && $ide['intermediary'] !== '') {
            $this->element($dom, $node, 'indIntermed', $ide['intermediary']);
        }

        if (isset($ide['operation_location_code']) && $ide['operation_location_code'] !== '') {
            $this->element($dom, $node, 'cIndOp', $ide['operation_location_code']);
        }

        $this->element($dom, $node, 'procEmi', $ide['process']);
        $this->element($dom, $node, 'verProc', $ide['process_version']);
    }

    private function appendIssuer(DOMDocument $dom, DOMElement $parent, array $issuer): void
    {
        $emit = $this->element($dom, $parent, 'emit');
        $this->element($dom, $emit, 'CNPJ', $issuer['cnpj']);
        $this->element($dom, $emit, 'xNome', $issuer['legal_name']);

        if (!empty($issuer['trade_name'])) {
            $this->element($dom, $emit, 'xFant', $issuer['trade_name']);
        }

        $address = $this->element($dom, $emit, 'enderEmit');
        $this->element($dom, $address, 'xLgr', $issuer['street']);
        $this->element($dom, $address, 'nro', $issuer['number']);

        if (!empty($issuer['complement'])) {
            $this->element($dom, $address, 'xCpl', $issuer['complement']);
        }

        $this->element($dom, $address, 'xBairro', $issuer['district']);
        $this->element($dom, $address, 'cMun', $issuer['city_ibge']);
        $this->element($dom, $address, 'xMun', $issuer['city']);
        $this->element($dom, $address, 'UF', $issuer['uf']);
        $this->element($dom, $address, 'CEP', $issuer['zip_code']);

        if ($issuer['state_registration'] !== '') {
            $this->element($dom, $emit, 'IE', $issuer['state_registration']);
        }

        $this->element($dom, $emit, 'CRT', $issuer['crt']);
    }

    private function appendRecipient(DOMDocument $dom, DOMElement $parent, array $recipient): void
    {
        foreach (['document_type', 'document', 'ie_indicator'] as $required) {
            if (!isset($recipient[$required]) || !is_scalar($recipient[$required])) {
                throw new InvalidFiscalDataException("Campo obrigatório do destinatário ausente: {$required}.");
            }
        }

        $documentType = (string) $recipient['document_type'];
        if (!in_array($documentType, ['CNPJ', 'CPF', 'idEstrangeiro'], true)) {
            throw new InvalidFiscalDataException('Tipo de documento do destinatário inválido.');
        }

        $dest = $this->element($dom, $parent, 'dest');
        $this->element($dom, $dest, $documentType, $recipient['document']);

        if (!empty($recipient['name'])) {
            $this->element($dom, $dest, 'xNome', $recipient['name']);
        }

        if (isset($recipient['address']) && is_array($recipient['address'])) {
            $addressData = $recipient['address'];
            foreach (['street', 'number', 'district', 'city_ibge', 'city', 'uf'] as $required) {
                if (!isset($addressData[$required]) || !is_scalar($addressData[$required])) {
                    throw new InvalidFiscalDataException("Campo obrigatório do endereço do destinatário ausente: {$required}.");
                }
            }

            $address = $this->element($dom, $dest, 'enderDest');
            $this->element($dom, $address, 'xLgr', $addressData['street']);
            $this->element($dom, $address, 'nro', $addressData['number']);

            if (!empty($addressData['complement'])) {
                $this->element($dom, $address, 'xCpl', $addressData['complement']);
            }

            $this->element($dom, $address, 'xBairro', $addressData['district']);
            $this->element($dom, $address, 'cMun', $addressData['city_ibge']);
            $this->element($dom, $address, 'xMun', $addressData['city']);
            $this->element($dom, $address, 'UF', $addressData['uf']);

            if (!empty($addressData['zip_code'])) {
                $this->element($dom, $address, 'CEP', $addressData['zip_code']);
            }

            if (!empty($addressData['country_code'])) {
                $this->element($dom, $address, 'cPais', $addressData['country_code']);
            }

            if (!empty($addressData['country'])) {
                $this->element($dom, $address, 'xPais', $addressData['country']);
            }

            if (!empty($addressData['phone'])) {
                $this->element($dom, $address, 'fone', $addressData['phone']);
            }
        }

        $this->element($dom, $dest, 'indIEDest', $recipient['ie_indicator']);

        if (!empty($recipient['state_registration'])) {
            $this->element($dom, $dest, 'IE', $recipient['state_registration']);
        }

        if (!empty($recipient['suframa'])) {
            $this->element($dom, $dest, 'ISUF', $recipient['suframa']);
        }

        if (!empty($recipient['municipal_registration'])) {
            $this->element($dom, $dest, 'IM', $recipient['municipal_registration']);
        }

        if (!empty($recipient['email'])) {
            $this->element($dom, $dest, 'email', $recipient['email']);
        }
    }

    private function appendItem(DOMDocument $dom, DOMElement $parent, int $number, array $item): void
    {
        $det = $this->element($dom, $parent, 'det');
        $det->setAttribute('nItem', (string) $number);

        $prod = $this->element($dom, $det, 'prod');
        $this->element($dom, $prod, 'cProd', $item['code']);
        $this->element($dom, $prod, 'cEAN', $item['gtin']);
        $this->element($dom, $prod, 'xProd', $item['description']);
        $this->element($dom, $prod, 'NCM', $item['ncm']);

        if (!empty($item['cest'])) {
            $this->element($dom, $prod, 'CEST', $item['cest']);
        }

        if (array_key_exists('benefit_code', $item) && $item['benefit_code'] !== null) {
            $this->element($dom, $prod, 'cBenef', $item['benefit_code']);
        }

        if (!empty($item['ipi_exception'])) {
            $this->element($dom, $prod, 'EXTIPI', $item['ipi_exception']);
        }

        $this->element($dom, $prod, 'CFOP', $item['cfop']);
        $this->element($dom, $prod, 'uCom', $item['unit']);
        $this->element($dom, $prod, 'qCom', $item['quantity']);
        $this->element($dom, $prod, 'vUnCom', $item['unit_price']);
        $this->element($dom, $prod, 'vProd', $item['gross_total']);
        $this->element($dom, $prod, 'cEANTrib', $item['tax_gtin']);
        $this->element($dom, $prod, 'uTrib', $item['tax_unit']);
        $this->element($dom, $prod, 'qTrib', $item['tax_quantity']);
        $this->element($dom, $prod, 'vUnTrib', $item['tax_unit_price']);

        foreach (['freight' => 'vFrete', 'insurance' => 'vSeg', 'discount' => 'vDesc', 'other' => 'vOutro'] as $key => $tag) {
            if (array_key_exists($key, $item) && $item[$key] !== null) {
                $this->element($dom, $prod, $tag, $item[$key]);
            }
        }

        $this->element($dom, $prod, 'indTot', $item['include_total']);

        $tax = $this->element($dom, $det, 'imposto');

        if (isset($item['estimated_tax_total']) && $item['estimated_tax_total'] !== '') {
            $this->element($dom, $tax, 'vTotTrib', $item['estimated_tax_total']);
        }

        foreach (self::TAX_GROUP_ORDER as $group) {
            if (!isset($item['tax'][$group]) || !is_array($item['tax'][$group])) {
                continue;
            }

            $groupNode = $this->element($dom, $tax, $group);
            $this->appendResolvedTree($dom, $groupNode, $item['tax'][$group]);
        }
    }

    private function appendTotals(DOMDocument $dom, DOMElement $parent, array $totals): void
    {
        $total = $this->element($dom, $parent, 'total');
        $icmsTotal = $this->element($dom, $total, 'ICMSTot');
        $values = $totals['ICMSTot'];

        foreach (self::ICMS_TOTAL_ORDER as $tag) {
            if (array_key_exists($tag, $values) && $values[$tag] !== null) {
                $this->element($dom, $icmsTotal, $tag, $values[$tag]);
            }
        }

        foreach (['ISSQNtot', 'retTrib', 'ISTot', 'IBSCBSTot'] as $group) {
            if (!isset($totals[$group]) || !is_array($totals[$group])) {
                continue;
            }

            $groupNode = $this->element($dom, $total, $group);
            $this->appendResolvedTree($dom, $groupNode, $totals[$group]);
        }

        if (isset($totals['vNFTot']) && $totals['vNFTot'] !== null) {
            $this->element($dom, $total, 'vNFTot', $totals['vNFTot']);
        }
    }

    /**
     * @param list<array<string,mixed>> $payments
     */
    private function appendPayments(
        DOMDocument $dom,
        DOMElement $parent,
        array $payments,
        mixed $change,
    ): void {
        $pag = $this->element($dom, $parent, 'pag');

        foreach ($payments as $payment) {
            $detPag = $this->element($dom, $pag, 'detPag');

            if (isset($payment['indicator']) && $payment['indicator'] !== '') {
                $this->element($dom, $detPag, 'indPag', $payment['indicator']);
            }

            $this->element($dom, $detPag, 'tPag', $payment['type']);

            if (!empty($payment['description'])) {
                $this->element($dom, $detPag, 'xPag', $payment['description']);
            }

            $this->element($dom, $detPag, 'vPag', $payment['amount']);

            if (!empty($payment['date'])) {
                $this->element($dom, $detPag, 'dPag', $payment['date']);
            }

            if (isset($payment['card']) && is_array($payment['card'])) {
                $card = $this->element($dom, $detPag, 'card');
                $this->appendResolvedTree($dom, $card, $payment['card']);
            }
        }

        if ($change !== null) {
            $this->element($dom, $pag, 'vTroco', $change);
        }
    }

    private function appendResolvedTree(DOMDocument $dom, DOMElement $parent, array $data): void
    {
        foreach ($data as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $name)) {
                throw new InvalidFiscalDataException('Nome de elemento fiscal resolvido inválido.');
            }

            if (is_array($value)) {
                $node = $this->element($dom, $parent, $name);
                $this->appendResolvedTree($dom, $node, $value);
                continue;
            }

            if (!is_scalar($value) && $value !== null) {
                throw new InvalidFiscalDataException("Valor fiscal inválido para o elemento {$name}.");
            }

            if ($value !== null) {
                $this->element($dom, $parent, $name, $value);
            }
        }
    }

    private function element(
        DOMDocument $dom,
        DOMElement $parent,
        string $name,
        mixed $value = null,
    ): DOMElement {
        $element = $dom->createElementNS(self::NS, $name);

        if ($value !== null) {
            $element->appendChild($dom->createTextNode((string) $value));
        }

        $parent->appendChild($element);

        return $element;
    }
}
