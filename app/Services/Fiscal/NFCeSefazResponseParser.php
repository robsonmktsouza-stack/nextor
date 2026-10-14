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
            'individual' => false, 'source' => null,
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
                    'individual' => true, 'source' => 'NFE',
                ];
            }

            if (strtoupper((string) $sectionName) === 'RETORNO') {
                $result['cstat'] = (string) ($section['cstat'] ?? '');
                $result['reason'] = (string) ($section['xmotivo'] ?? '');
                $result['source'] = 'RETORNO';
            } elseif (strtoupper((string) $sectionName) === 'ENVIO' && $result['cstat'] === null) {
                $result['cstat'] = (string) ($section['cstat'] ?? '');
                $result['reason'] = (string) ($section['xmotivo'] ?? '');
                $result['source'] = 'ENVIO';
            }
        }

        return $result;
    }

    public function parseConsult(string $raw): array
    {
        $result = $this->parse($raw);
        $sections = @parse_ini_string($raw, true, INI_SCANNER_RAW);
        if (!is_array($sections)) {
            return $result;
        }

        foreach ($sections as $name => $data) {
            if (strtoupper((string) $name) !== 'CONSULTA' || !is_array($data)) {
                continue;
            }
            $values = array_change_key_case($data, CASE_LOWER);
            $key = (string) ($values['chdfe'] ?? $values['chnfe'] ?? '');
            $protocol = (string) ($values['nprot'] ?? '');
            return [
                'cstat' => (string) ($values['cstat'] ?? ''),
                'reason' => (string) ($values['xmotivo'] ?? ''),
                'key' => preg_match('/^\d{44}$/', $key) ? $key : null,
                'protocol' => preg_match('/^\d{15}$/', $protocol) ? $protocol : null,
                'received_at' => (string) ($values['dhrecbto'] ?? ''),
                'digest' => (string) ($values['digval'] ?? ''),
                'environment' => (string) ($values['tpamb'] ?? ''),
                'application' => (string) ($values['veraplic'] ?? ''),
                'individual' => true,
            ];
        }
        return $result;
    }

    /**
     * A single NFC-e is sent synchronously by this integration. Some ACBr
     * responses carry an explicit item-level rejection directly in [Envio]
     * or [Retorno], without a [NFE{number}] section. Never equate a batch
     * status, network error, consultation, duplicate 204/539 or authorization
     * with rejection.
     *
     * Only individually correctable codes are accepted from [Envio] or
     * [Retorno], and only when xMotivo explicitly identifies a rejection.
     * A caller must still check the original archived response and signed XML.
     */
    public function definitiveCorrectionRejection(array $response): bool
    {
        $code = (string) ($response['cstat'] ?? '');
        if (!in_array($code, [
            '777', '778', '611', '383', '386', '382', '766', '725',
            '237', '220', '769', '767', '865', '866', '869',
        ], true)) {
            return false;
        }

        if (($response['individual'] ?? false) === true) {
            return true;
        }

        return in_array(($response['source'] ?? null), ['ENVIO', 'RETORNO'], true)
            && preg_match('/^\\s*Rejei(?:c|ç)(?:a|ã)o\\b/iu', (string) ($response['reason'] ?? '')) === 1;
    }

    public function authorized(array $response): bool
    {
        return ($response['individual'] ?? false) === true
            && in_array((string) ($response['cstat'] ?? ''), ['100', '150'], true)
            && preg_match('/^\d{44}$/', (string) ($response['key'] ?? '')) === 1
            && preg_match('/^\d{15}$/', (string) ($response['protocol'] ?? '')) === 1;
    }
}
