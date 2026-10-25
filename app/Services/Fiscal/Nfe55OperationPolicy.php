<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Models\NfeDraftItem;
use RuntimeException;

/** Operation semantics are checked separately from the configured tax treatment. */
final class Nfe55OperationPolicy
{
    private const UF_CODES = [
        'RO'=>'11','AC'=>'12','AM'=>'13','RR'=>'14','PA'=>'15','AP'=>'16','TO'=>'17',
        'MA'=>'21','PI'=>'22','CE'=>'23','RN'=>'24','PB'=>'25','PE'=>'26','AL'=>'27',
        'SE'=>'28','BA'=>'29','MG'=>'31','ES'=>'32','RJ'=>'33','SP'=>'35',
        'PR'=>'41','SC'=>'42','RS'=>'43','MS'=>'50','MT'=>'51','GO'=>'52','DF'=>'53',
    ];

    // Explicitly bounded operation families. This is not a CFOP tax-rate table.
    private const RETURN_SUFFIXES = ['201','202','208','209','210','410','411','553','556','661','662'];
    private const ORDINARY_SUFFIXES = ['101','102','401','403','405'];
    private const SPECIAL_SUFFIXES = [
        'transfer'=>['151','152','408','409'],
        'bonus'=>['910'], 'gift'=>['910'], 'sample'=>['911'],
        'remittance'=>['915'], 'return_of_remittance'=>['916'],
    ];

    public function validate(NfeDraft $draft, CompanySetting $company): array
    {
        $draft->loadMissing(['items','operationNature']);
        if (!$draft->issue_date || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', (string)$draft->issue_time)) {
            throw new RuntimeException('Informe a data e o horário de emissão para aplicar a vigência fiscal correta.');
        }
        if ($draft->inform_exit_datetime && (!$draft->exit_date
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', (string)$draft->exit_time)
            || $draft->exit_date->toDateString() < $draft->issue_date->toDateString())) {
            throw new RuntimeException('Confira data e horário de saída ou entrada; a data não pode anteceder a emissão.');
        }
        $origin = strtoupper((string)$company->state);
        $recipient = $draft->recipient_snapshot ?? [];
        $destination = strtoupper((string)($recipient['state'] ?? ''));
        if (!isset(self::UF_CODES[$origin])) {
            throw new RuntimeException('UF do emitente inválida.');
        }
        if (!in_array($draft->operation_type, ['outbound','inbound'], true)) {
            throw new RuntimeException('Selecione entrada ou saída para a NF-e.');
        }
        $foreign = $draft->destination === 'foreign';
        if ($foreign) {
            app(Nfe55ForeignTradeService::class)->validate($draft, $company);
        } elseif (!isset(self::UF_CODES[$destination])
            || !in_array($draft->destination, ['internal','interstate'], true)
            || ($draft->destination === 'internal') !== ($origin === $destination)) {
            throw new RuntimeException('O destino da operação deve corresponder às UFs do emitente e do destinatário.');
        }
        $municipalities = [[$origin, $company->city_ibge_code]];
        if (!$foreign) $municipalities[] = [$destination, $recipient['city_ibge_code'] ?? ''];
        foreach ($municipalities as [$uf,$code]) {
            if (!preg_match('/^'.self::UF_CODES[$uf].'\d{5}$/D', (string)$code)) {
                throw new RuntimeException('O código IBGE do município deve pertencer à UF informada.');
            }
        }
        if (!$draft->operationNature || !$draft->operationNature->is_active
            || $draft->operationNature->operation_type !== $draft->operation_type) {
            throw new RuntimeException('Selecione uma natureza de operação ativa compatível com entrada ou saída.');
        }
        if ($draft->operationNature->purpose && $draft->operationNature->purpose !== $draft->purpose) {
            throw new RuntimeException('A finalidade deve corresponder à natureza de operação selecionada.');
        }
        if ($draft->operationNature->require_transport
            && (!in_array((string)data_get($draft->transport_data,'freight_mode'), ['0','1','2','3','4'], true)
                || !data_get($draft->transport_data,'carrier.document'))) {
            throw new RuntimeException('A natureza de operação exige transporte e identificação da transportadora.');
        }
        if ($draft->operationNature->require_invoice && !is_array($draft->invoice_data)) {
            throw new RuntimeException('A natureza de operação exige os dados de faturamento.');
        }
        if ($draft->operationNature->require_duplicates && empty($draft->duplicates)) {
            throw new RuntimeException('A natureza de operação exige duplicatas para a cobrança.');
        }
        if (!in_array($draft->purpose, ['normal','return'], true)) {
            throw new RuntimeException('Complemento, ajuste e notas de débito ou crédito exigem grupos próprios ainda não implementados.');
        }
        $profile = (string)($recipient['ie_indicator'] ?? 'non_contributor');
        if (!in_array($profile, ['contributor','exempt','non_contributor'], true)) {
            throw new RuntimeException('Indicador de inscrição estadual do destinatário inválido.');
        }
        if ($profile === 'contributor' && trim((string)($recipient['state_registration'] ?? '')) === '') {
            throw new RuntimeException('Informe a inscrição estadual do destinatário contribuinte.');
        }
        // The ordinary interstate non-contributor final-consumer flow requires
        // ICMSUFDest/FCP and its own basis. Never omit that group silently.
        if ($draft->destination === 'interstate' && $draft->final_consumer && $profile === 'non_contributor'
            && $draft->purpose !== 'return') {
            throw new RuntimeException('Venda interestadual a consumidor final não contribuinte exige DIFAL e FCP de destino, ainda não implementados neste fluxo.');
        }
        $operation = (string)data_get($draft->custom_fields, 'fiscal_operation', $draft->purpose === 'return' ? 'devolution' : 'sale');
        if (!in_array($operation, ['sale','devolution','import','export',...array_keys(self::SPECIAL_SUFFIXES)], true)) {
            throw new RuntimeException('Tipo de operação fiscal não implementado.');
        }
        if (($operation === 'devolution') !== ($draft->purpose === 'return')) {
            throw new RuntimeException('A operação de devolução exige finalidade Devolução de mercadoria.');
        }
        if ($operation === 'transfer') {
            $issuerDocument = preg_replace('/\D/', '', (string)$company->document);
            $recipientDocument = preg_replace('/\D/', '', (string)($recipient['document'] ?? ''));
            if (strlen($recipientDocument) !== 14 || strlen($issuerDocument) !== 14
                || substr($issuerDocument, 0, 8) !== substr($recipientDocument, 0, 8)
                || $issuerDocument === $recipientDocument) {
                throw new RuntimeException('Transferência exige estabelecimentos distintos do mesmo titular.');
            }
        }
        if (in_array($operation, ['devolution','return_of_remittance'], true) && empty($draft->references)) {
            throw new RuntimeException('Devolução ou retorno exige documento fiscal de origem referenciado.');
        }
        if (in_array($operation, ['devolution','transfer','bonus','gift','sample','remittance','return_of_remittance'], true)) {
            $payments = $draft->payments ?? [];
            if ($draft->payment_type !== '90' || array_filter($payments, static fn($payment) =>
                (string)($payment['type'] ?? '') !== '90' || (float)($payment['amount'] ?? 0) !== 0.0)) {
                throw new RuntimeException('Esta operação sem recebimento exige a forma Sem pagamento com valor zero.');
            }
        }
        $result = [
            'tpNF'=>$draft->operation_type === 'outbound' ? '1' : '0',
            'idDest'=>$foreign ? '3' : ($draft->destination === 'internal' ? '1' : '2'),
            'finNFe'=>$draft->purpose === 'return' ? '4' : '1',
            'cfop_prefix'=>$foreign ? ($draft->operation_type === 'outbound' ? '7' : '3') : ($draft->operation_type === 'outbound'
                ? ($draft->destination === 'internal' ? '5' : '6')
                : ($draft->destination === 'internal' ? '1' : '2')),
            'fiscal_operation'=>$operation,
            'crt'=>(string)$company->crt,
            'final_consumer'=>(bool)$draft->final_consumer,
            'recipient_profile'=>$profile,
        ];
        foreach ($draft->items as $item) {
            $this->validateItem($item, $result);
        }
        return $result;
    }

    public function validateItem(NfeDraftItem $item, array $operation): void
    {
        $cfop = (string)$item->cfop;
        if (!preg_match('/^'.$operation['cfop_prefix'].'\d{3}$/D', $cfop)) {
            throw new RuntimeException('Item '.$item->item_number.': CFOP incompatível com entrada/saída e destino da NF-e.');
        }
        $suffix = substr($cfop, 1);
        $kind = $operation['fiscal_operation'];
        $tax = $item->tax_data ?? [];
        $inbound = ($operation['tpNF'] ?? '1') === '0';
        $tax = Nfe55TaxParameters::forDirection($tax, $inbound ? 'inbound' : 'outbound');
        $csosn = (string)($tax['icms_csosn'] ?? '');
        if ($kind === 'import' && !in_array((string)$item->origin, ['1','6'], true)) {
            throw new RuntimeException('Importação direta exige origem estrangeira de importação direta, conforme o enquadramento do produto.');
        }
        if (($operation['crt'] ?? '') === '4'
            && ($kind !== 'sale' || $inbound || $cfop !== '5102'
                || !in_array($csosn, ['102','300'], true))) {
            throw new RuntimeException('Este cenário do MEI exige parametrização específica ainda não implementada.');
        }
        if (in_array($csosn, ['101','201'], true)
            && (($operation['final_consumer'] ?? false)
                || ($operation['recipient_profile'] ?? '') !== 'contributor'
                || !data_get($tax,'fiscal_rule_trace.legal_source'))) {
            throw new RuntimeException('Crédito do Simples exige destinatário contribuinte, destinação compatível e fundamento legal registrado.');
        }
        $ipiCst = (string)($inbound ? ($tax['ipi_cst_inbound'] ?? $tax['ipi_cst'] ?? '') : ($tax['ipi_cst'] ?? ''));
        if ($ipiCst !== '' && !in_array($ipiCst, $inbound
            ? ['00','01','02','03','04','05','49'] : ['50','51','52','53','54','55','99'], true)) {
            throw new RuntimeException('Item '.$item->item_number.': CST do IPI incompatível com entrada ou saída.');
        }
        $taxableIpi = (float)($tax['ipi_rate'] ?? 0) > 0 || (float)($tax['ipi_unit_rate'] ?? 0) > 0;
        $ownIcms = in_array((string)($tax['icms_cst'] ?? ''), ['00','10','20','51','70','90'], true)
            || in_array($csosn, ['101','201','900'], true);
        if ($taxableIpi && $ownIcms && (!isset($tax['icms_base']) || $tax['icms_base'] === '')) {
            throw new RuntimeException('Informe a base do ICMS com o tratamento do IPI aprovado para esta operação.');
        }
        if (in_array($operation['crt'] ?? '', ['2','3'], true)) {
            foreach (['pis','cofins'] as $contribution) {
                if ((float)($tax[$contribution.'_rate'] ?? 0) > 0
                    && (!isset($tax[$contribution.'_base']) || $tax[$contribution.'_base'] === '')) {
                    throw new RuntimeException('Informe a base do '.strtoupper($contribution)
                        .' com as exclusões e o enquadramento aprovados para esta operação.');
                }
            }
        }
        if (trim((string)$item->fiscal_benefit_code) !== ''
            && !data_get($tax,'fiscal_rule_trace.legal_source')) {
            throw new RuntimeException('Benefício fiscal exige regra aprovada com fundamento legal, UF e vigência registrados.');
        }
        if ($kind === 'devolution' && !in_array($suffix, self::RETURN_SUFFIXES, true)) {
            throw new RuntimeException('Item '.$item->item_number.': CFOP sem tratamento de devolução neste emissor.');
        }
        if ($kind !== 'devolution' && in_array($suffix, self::RETURN_SUFFIXES, true)) {
            throw new RuntimeException('CFOP de devolução exige finalidade Devolução de mercadoria.');
        }
        if ($kind === 'sale' && !in_array($suffix, self::ORDINARY_SUFFIXES, true)) {
            throw new RuntimeException('Este CFOP exige uma operação fiscal específica; não pode ser enviado como venda ou compra comum.');
        }
        if (isset(self::SPECIAL_SUFFIXES[$kind]) && !in_array($suffix, self::SPECIAL_SUFFIXES[$kind], true)) {
            throw new RuntimeException('Item '.$item->item_number.': CFOP incompatível com a operação fiscal selecionada.');
        }
        if ($kind !== 'sale' && $kind !== 'devolution') {
            $trace = data_get($item->tax_data, 'fiscal_rule_trace', []);
            if (!is_array($trace) || empty($trace['legal_source'])
                || data_get($trace, 'snapshot.fiscal_operation') !== $kind) {
                throw new RuntimeException('A operação especial exige regra fiscal com fundamento legal e vigência registrados.');
            }
        }
        $returnedIpi=app(Nfe55ReturnedIpiCalculator::class)->calculate($tax);
        if ($returnedIpi!==null && $kind!=='devolution') {
            throw new RuntimeException('IPI devolvido exige finalidade de devolução e documento de origem referenciado.');
        }
        if ($kind === 'devolution') {
            foreach (['ipi_return_amount','ipi_return_percentage','ipi_rate','ipi_unit_rate'] as $field) {
                if (isset($tax[$field]) && (float)$tax[$field] != 0.0) {
                    throw new RuntimeException('Devolução com IPI exige o grupo de imposto devolvido, ainda não implementado.');
                }
            }
        }
    }
}
