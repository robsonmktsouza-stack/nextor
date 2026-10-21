<?php

namespace App\Support;

/**
 * Keeps the original audit record intact while removing obsolete implementation
 * explanations from the operator-facing event timeline.
 */
final class OperatorMessage
{
    public static function event(?string $description): string
    {
        $value = (string) $description;

        if (preg_match('/^Automação: licença próxima do vencimento em (\d{2}\/\d{2}\/\d{4})\. Verifique a renovação\.$/u', $value, $match)) {
            return 'Licença próxima do vencimento: '.$match[1].'.';
        }

        if (preg_match('/^Automação: licença vencida em (\d{2}\/\d{2}\/\d{4})\. O estado de acesso já é calculado pelo prazo\.$/u', $value, $match)) {
            return 'Licença vencida em '.$match[1].'.';
        }

        if (preg_match('/^Automação: instalação sem comunicação com a central desde (\d{2}\/\d{2}\/\d{4} \d{2}:\d{2})\.$/u', $value, $match)) {
            return 'Sem comunicação desde '.$match[1].'.';
        }

        if ($value === 'Instalação vinculada pelo gerenciador, sem interação do cliente.') {
            return 'Instalação vinculada.';
        }

        return $value;
    }
}
