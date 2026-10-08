<?php

namespace App\Services\Fiscal;

/**
 * A resposta da ACBr contém ENVIO/RETORNO e uma seção NFE{número}.
 * Somente o cStat da seção individual identifica a autorização fiscal.
 */
final class NFCeSefazResponseParser
{
    public function parse(string $raw): array
    {
        $result = [
            'cstat' => null, 'reason' => 'Retorno SEFAZ não identificado',
            'key' => null, 'protocol' => null, 'received_at' => null,
            'digest' => null, 'environment' => null, 'application' => null,
            'individual' => false,
        ];

        $sections = @parse_ini_string($raw, true, INI_SCANNER_RAW);
        if (!is_array($sections)) {
            return $result;
        }

        foreach ($sections as $sectionName => $data) {
            if (!is_array($data)) {
                continue;
            }

            $section = array_change_key_case($data, CASE_LOWER);
            if (preg_match('/^NFE\d+$/i', (string) $sectionName)) {
                $key = (string) ($section['chdfe'] ?? $section['chnfe'] ?? '');
                $protocol = (string) ($section['nprot'] ?? '');
                return [
                    'cstat' => (string) ($section['cstat'] ?? ''),
                    'reason' => (string) ($section['xmotivo'] ?? ''),
                    'key' => preg_match('/^\d{44}$/', $key) ? $key : null,
                    'protocol' => preg_match('/^\d{15}$/', $protocol) ? $protocol : null,
                    'received_at' => (string) ($section['dhrecbto'] ?? ''),
                    'digest' => (string) ($section['digval'] ?? ''),
                    'environment' => (string) ($section['tpamb'] ?? ''),
                    'application' => (string) ($section['veraplic'] ?? ''),
                    'individual' => true,
                ];
            }

            if (strtoupper((string) $sectionName) === 'RETORNO') {
                $result['cstat'] = (string) ($section['cstat'] ?? '');
                $result['reason'] = (string) ($section['xmotivo'] ?? '');
            } elseif (strtoupper((string) $sectionName) === 'ENVIO' && $result['cstat'] === null) {
                $result['cstat'] = (string) ($section['cstat'] ?? '');
                $result['reason'] = (string) ($section['xmotivo'] ?? '');
            }
        }

        return $result;
    }

    public function authorized(array $response): bool
    {
        return ($response['individual'] ?? false) === true
            && in_array((string) ($response['cstat'] ?? ''), ['100', '150'], true)
            && preg_match('/^\d{44}$/', (string) ($response['key'] ?? '')) === 1
            && preg_match('/^\d{15}$/', (string) ($response['protocol'] ?? '')) === 1;
    }
}
