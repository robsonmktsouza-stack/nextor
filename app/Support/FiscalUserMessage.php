<?php

namespace App\Support;

/** Presentation only. Fiscal validators and their original diagnostics remain unchanged. */
final class FiscalUserMessage
{
    public static function present(string $message): string
    {
        // The authority's rejection and its identifying code must stay intact.
        if (preg_match('/^(?:Retorno (?:da )?SEFAZ|Consulta SEFAZ|Rejei[cç][aã]o|Uso Denegado)\b/iu', $message)) {
            return $message;
        }

        $exact = [
            'Applicable fiscal rule lacks normative traceability.' => 'A regra fiscal está sem fundamento legal cadastrado. Revise a regra antes de emitir.',
            'No approved fiscal rule covers this operation.' => 'Não há regra fiscal aprovada para esta operação. Revise a parametrização antes de emitir.',
            'Conflicting fiscal rules have equal precedence.' => 'Há regras fiscais em conflito. Revise os critérios e a prioridade antes de emitir.',
            'Rule criteria must be an array.' => 'Os critérios da regra fiscal são inválidos. Revise a configuração da regra.',
            'Invalid effective date.' => 'Informe uma data de operação válida.',
            'Invalid tax base or percentage.' => 'Confira a base e a alíquota do tributo.',
            'Tipo de operação fiscal não implementado.' => 'Operação fiscal indisponível para emissão. Confira a natureza e a finalidade da nota.',
            'Digest do XML original difere do protocolo da SEFAZ.' => 'O XML original não corresponde ao protocolo da SEFAZ. Confira os arquivos da nota.',
            'Schemas fiscais indisponíveis.' => 'A configuração de validação fiscal está indisponível. Solicite a verificação ao responsável pelo sistema.',
            'Número da DI inválido. DUIMP não está implementada neste fluxo.' => 'Número da DI inválido. A importação por DUIMP não está disponível para emissão.',
            'Este produto exige grupo fiscal específico ainda não homologado.' => 'O tratamento fiscal deste produto não está disponível para emissão. Revise o grupo tributário com o responsável fiscal.',
        ];
        if (isset($exact[$message])) return $exact[$message];

        if (preg_match('/Forma de pagamento sem mapeamento fiscal:\s*([a-z_]+)/u', $message, $match)) {
            $label = match ($match[1]) {
                'cash' => 'Dinheiro', 'pix' => 'PIX', 'credit_card' => 'Cartão de crédito',
                'debit_card' => 'Cartão de débito', 'bank_transfer' => 'Transferência bancária',
                'bank_slip', 'boleto' => 'Boleto', 'check', 'cheque' => 'Cheque',
                default => null,
            };
            return ($label === null ? 'Forma de pagamento' : $label).' sem código fiscal. Revise a forma de pagamento nas configurações.';
        }

        if (preg_match('/^Falha da ACBr em NFE_[A-Za-z]+ \(código -?\d+\)\./u', $message)) {
            return 'Não foi possível concluir a operação fiscal. Confira a situação na SEFAZ antes de tentar novamente.';
        }

        if (preg_match('/\b(?:snapshot|hash|fingerprint|fiscal_rule_trace)\b/iu', $message)) {
            return 'Os dados da regra fiscal precisam ser conferidos. Revise e valide novamente a regra aplicada à nota.';
        }

        $message = preg_replace_callback('/\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b/', static function (array $match): string {
            return FiscalPresentation::label($match[0]) ?? 'parâmetro fiscal';
        }, $message);

        $message = strtr($message, [
            'sem cálculo implementado' => 'com tratamento indisponível para emissão',
            'ainda não tem cálculo implementado' => 'não está disponível para emissão',
            'cálculo ainda não está implementado' => 'tratamento não está disponível para emissão',
            'ainda não está implementada' => 'não está disponível para emissão',
            'ainda não está implementado' => 'não está disponível para emissão',
            'ainda não é implementado' => 'não está disponível para emissão',
            'ainda não implementados' => 'indisponíveis para emissão',
            'ainda não implementadas' => 'indisponíveis para emissão',
            'ainda não implementado' => 'indisponível para emissão',
            'ainda não implementada' => 'indisponível para emissão',
            'não implementado' => 'indisponível para emissão',
            'não implementada' => 'indisponível para emissão',
            'ainda não mapeado' => 'indisponível para emissão',
            'sem tratamento implementado' => 'não disponível para emissão',
            'sem suporte' => 'indisponível para esta operação',
            'não é suportado pela NFC-e atual' => 'não está disponível para emissão de NFC-e',
            'não são suportados pela NFC-e de mercadorias' => 'não se aplicam à emissão de NFC-e de mercadorias',
            'neste emissor' => 'nesta operação',
            'neste fluxo' => 'nesta operação',
            'RTC:' => 'IBS/CBS:',
            'A ACBr informou rejeição' => 'O retorno fiscal indica rejeição',
            'Retorno da ACBr inconclusivo' => 'Retorno fiscal inconclusivo',
            'Biblioteca ACBrLib incompatível com o método solicitado.' => 'A integração fiscal precisa ser atualizada para esta operação. Solicite suporte.',
        ]);

        return preg_replace_callback('/\b(percent|percentage|quantity|combined|inbound|outbound|internal|interstate|foreign|credit_note|debit_note)\b/',
            static fn (array $match): string => $match[0] === 'percentage' ? 'Percentual' : FiscalPresentation::value($match[0]), $message);
    }
}
