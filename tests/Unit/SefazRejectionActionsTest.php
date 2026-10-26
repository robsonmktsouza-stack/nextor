<?php

namespace Tests\Unit;

use App\Services\Fiscal\SefazRejectionActions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SefazRejectionActionsTest extends TestCase
{
    #[DataProvider('families')]
    public function test_official_subjects_receive_actionable_guidance(string $reason, string $category): void
    {
        $result = (new SefazRejectionActions())->describe(null, $reason);

        self::assertSame($category, $result['category']);
        self::assertNotEmpty($result['title']);
        self::assertNotEmpty($result['summary']);
        self::assertCount(2, $result['steps']);
        self::assertNotEmpty($result['fields']);
    }

    public static function families(): array
    {
        return [
            'cfop before icms' => ['Rejeição: CFOP não permitido para o CSOSN informado [nItem: 1]', 'cfop'],
            'ncm' => ['Rejeição: Informado NCM inexistente [nItem: 1]', 'ncm'],
            'cest before st' => ['Rejeição: Operação com ICMS-ST sem informação do CEST', 'cest'],
            'gtin' => ['Rejeição: GTIN (cEANTrib) inválido [nItem: 1]', 'gtin'],
            'benefit before cst' => ['Rejeição: CST com benefício fiscal e não informado o código de benefício fiscal', 'benefit'],
            'rtc before cst' => ['Rejeição: CST IBS/CBS informado indevidamente', 'rtc'],
            'cbs amount' => ['Rejeição: Valor CBS difere do calculado [nItem: 1]', 'rtc'],
            'selective tax' => ['Rejeição: Valor do Imposto Seletivo difere do calculado', 'rtc'],
            'regime' => ['Rejeição: Código de Regime Tributário do emitente diverge do cadastro da SEFAZ', 'regime'],
            'csosn' => ['Rejeição: CSOSN não permitido para a UF', 'regime'],
            'cst' => ['Rejeição: CST inválido', 'tax_code'],
            'fcp before icms' => ['Rejeição: Valor do ICMS relativo ao Fundo de Combate à Pobreza na UF de destino difere do calculado', 'fcp'],
            'difal' => ['Rejeição: Valor do ICMS Interestadual para UF de Destino difere do calculado', 'difal'],
            'st' => ['Rejeição: Total da BC ICMS-ST difere do somatório dos itens', 'icms_st'],
            'icms' => ['Rejeição: Valor do ICMS difere do calculado', 'icms'],
            'ipi' => ['Rejeição: Código de Enquadramento Legal do IPI inválido', 'ipi'],
            'pis' => ['Rejeição: Valor do PIS difere do calculado', 'pis_cofins'],
            'cofins' => ['Rejeição: Total da COFINS difere do somatório dos itens', 'pis_cofins'],
            'reference' => ['Rejeição: Documento fiscal referenciado não localizado', 'reference'],
            'purpose' => ['Rejeição: Finalidade da NF-e incompatível com a operação', 'purpose'],
            'import' => ['Rejeição: Declaração de importação sem adições', 'import'],
            'export' => ['Rejeição: Exportação sem local de embarque', 'export'],
            'fuel' => ['Rejeição: Código ANP inválido', 'fuel'],
            'medicine' => ['Rejeição: Código ANVISA inválido', 'traceability'],
            'lot' => ['Rejeição: Rastreabilidade não informada', 'traceability'],
            'lot number' => ['Rejeição: Número do lote de medicamento inválido', 'traceability'],
            'contingency' => ['Rejeição: Tipo de emissão não permitido em contingência', 'contingency'],
            'payment' => ['Rejeição: Total de pagamento menor que o total da nota', 'payment'],
            'transport' => ['Rejeição: Modalidade de frete inválida', 'transport'],
            'registration' => ['Rejeição: Emitente não habilitado para emissão da NF-e', 'registration'],
            'state registration' => ['Rejeição: IE do destinatário não cadastrada', 'state_registration'],
            'cnpj' => ['Rejeição: CNPJ do emitente inválido', 'taxpayer_identity'],
            'address' => ['Rejeição: Código do município do destinatário inválido', 'address'],
            'recipient' => ['Rejeição: Operação não presencial sem identificação do destinatário', 'recipient'],
            'date' => ['Rejeição: Data de Emissão muito atrasada', 'dates'],
            'range' => ['Rejeição: Faixa de numeração a inutilizar inválida', 'inutilization'],
            'event' => ['Rejeição: Evento não vinculado à NF-e', 'event'],
            'number' => ['Rejeição: Chave de acesso inválida', 'numbering'],
            'total' => ['Rejeição: Total da NF difere do somatório dos valores', 'totals'],
            'quantity' => ['Rejeição: Quantidade comercial inválida', 'product'],
            'price' => ['Rejeição: Valor unitário de comercialização inválido', 'product'],
            'certificate' => ['Rejeição: Certificado Assinatura inválido', 'certificate'],
            'signature' => ['Rejeição: Assinatura difere do calculado', 'signature'],
            'schema' => ['Rejeição: Falha no schema XML', 'schema'],
            'qr' => ['Rejeição: Código Identificador do CSC no QR-Code não cadastrado na SEFAZ', 'qr_code'],
            'availability' => ['Rejeição: Serviço temporariamente indisponível', 'availability'],
            'responsible technician' => ['Rejeição: Identificador do CSRT não cadastrado na SEFAZ', 'technical_responsibility'],
            'suframa' => ['Rejeição: Inscrição SUFRAMA inválida', 'suframa'],
            'regulated document' => ['Rejeição: Não informada Guia de Trânsito Animal', 'regulated_document'],
            'special product' => ['Rejeição: Número RECOPI inválido', 'special_product'],
            'intermediary' => ['Rejeição: Obrigatória as informações do intermediador da transação para operação por site de terceiros', 'intermediary'],
            'government' => ['Rejeição: NF-e de venda a Órgão Público sem informar a Nota de Empenho', 'government_purchase'],
            'issqn' => ['Rejeição: Código de Item da Lista de Serviços inexistente', 'issqn'],
            'distribution' => ['Rejeição: Número do NSU informado superior ao maior NSU do Ambiente Nacional', 'distribution'],
            'environment' => ['Rejeição: Ambiente informado diverge do Ambiente de recebimento', 'environment'],
            'model' => ['Rejeição: Modelo da NF-e diferente de 55', 'document_model'],
            'batch' => ['Rejeição: Solicitada resposta assíncrona para Lote com somente 1 NF-e', 'batch_format'],
            'import content' => ['Rejeição: Número de Controle da FCI inexistente', 'import_content'],
            'billing' => ['Rejeição: Soma do valor das parcelas difere do Valor Líquido da Fatura', 'billing'],
            'delivery location' => ['Rejeição: Local de retirada informado indevidamente', 'delivery_location'],
            'special emission' => ['Rejeição: Informação de Solicitação de NFF não pode estar preenchido', 'special_emission'],
            'registration status' => ['Rejeição: Irregularidade fiscal do emitente', 'registration'],
            'unknown subject' => ['Rejeição: Condição adicional informada pelo autorizador', 'review'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_status_guidance_never_treats_every_code_as_correctable_rejection(string $code, string $category): void
    {
        $result = (new SefazRejectionActions())->describe($code, 'Retorno fiscal');

        self::assertSame($category, $result['category']);
        self::assertNotEmpty($result['steps']);
        self::assertStringNotContainsString('gere uma nova', mb_strtolower(implode(' ', $result['steps'])));
        self::assertStringNotContainsString('reenvie automaticamente', mb_strtolower(implode(' ', $result['steps'])));
    }

    public static function statuses(): array
    {
        return [
            ['100', 'authorization'], ['120', 'authorization'], ['150', 'authorization'],
            ['103', 'pending'], ['104', 'pending'], ['105', 'pending'], ['106', 'pending'],
            ['108', 'availability'], ['109', 'availability'],
            ['107', 'service_status'], ['102', 'inutilized'], ['124', 'epec'],
            ['128', 'event_pending'], ['135', 'event_recorded'], ['136', 'event_unlinked'],
            ['111', 'registration_query'], ['112', 'registration_query'],
            ['137', 'distribution'], ['138', 'distribution'], ['139', 'distribution'], ['140', 'distribution'],
            ['172', 'warning'], ['110', 'denied'],
            ['204', 'duplicate'], ['539', 'duplicate'],
            ['205', 'denied'], ['301', 'registration'], ['302', 'registration'], ['303', 'registration'],
            ['101', 'cancelled'], ['151', 'cancelled'], ['218', 'cancelled'],
            ['217', 'not_found'], ['486', 'authorized_xml'], ['487', 'accountant'],
            ['321', 'reference'], ['656', 'rate_limit'], ['999', 'service_error'],
        ];
    }

    public function test_consultation_not_found_does_not_permit_reemission(): void
    {
        $result = (new SefazRejectionActions())->describe('217', 'Rejeição: NF-e não consta na base');

        self::assertStringContainsString('não comprova uma rejeição', $result['summary']);
        self::assertStringContainsString('antes de qualquer reenvio', implode(' ', $result['steps']));
    }

    public function test_historical_denial_is_not_confused_with_current_registration_rejection(): void
    {
        $actions = new SefazRejectionActions();

        self::assertSame('denied', $actions->describe('302', 'Uso Denegado: Irregularidade fiscal do destinatário')['category']);
        self::assertSame('registration', $actions->describe('302', 'Rejeição: Irregularidade fiscal do destinatário')['category']);
    }

    public function test_rate_limit_does_not_invent_a_fixed_unblock_time(): void
    {
        $result = (new SefazRejectionActions())->describe('656', 'Rejeição: Consumo indevido');

        self::assertStringContainsString('Interrompa', implode(' ', $result['steps']));
        self::assertDoesNotMatchRegularExpression('/\d+\s*(hora|minuto)/i', implode(' ', $result['steps']));
    }

    public function test_guidance_contains_no_automatic_mutation_instruction_or_tax_percentage(): void
    {
        foreach (self::families() as [$reason]) {
            $result = (new SefazRejectionActions())->describe(null, $reason);
            $text = implode(' ', [$result['summary'], ...$result['steps']]);
            self::assertDoesNotMatchRegularExpression('/\d[,.]?\d*\s*%/', $text);
            self::assertStringNotContainsString('transmita novamente', mb_strtolower($text));
        }
    }
}
