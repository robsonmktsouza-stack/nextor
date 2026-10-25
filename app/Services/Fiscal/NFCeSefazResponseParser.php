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
            '777', '778', '611', '612', '882', '883', '884', '885', '886', '888',
            '890', '891', '892', '893', '894', '895', '896',
            '383', '386', '382', '766', '725',
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

    /** Strict model-55 recovery proof; deliberately separate from NFC-e policy. */
    public function definitiveNfe55Rejection486(string $raw, string $key, string $environment): bool
    {
        return $this->definitiveNfe55AuthorizationRejection($raw, $key, $environment, ['486']);
    }

    public function definitiveNfe55AuthorizationRejection(string $raw, string $key, string $environment, array $allowedCodes = ['486', '487']): bool
    {
        if (strlen($raw) > 8388608 || preg_match('/<!DOCTYPE|<!ENTITY/i', $raw)
            || !preg_match('/^\d{44}$/', $key)
            || !in_array($environment, ['homologation', 'production'], true)) return false;
        $sections = @parse_ini_string($raw, true, INI_SCANNER_RAW);
        if (!is_array($sections)) return false;
        // parse_ini_string collapses duplicate sections/fields. Do not let an
        // earlier authorization or another document disappear during parsing.
        $seenSections = [];
        $seenFields = [];
        foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $match)) {
                $section = strtoupper($match[1]);
                if (isset($seenSections[$section])) return false;
                $seenSections[$section] = true;
                $seenFields = [];
            } elseif (preg_match('/^\s*([A-Za-z][A-Za-z0-9_]*)\s*=/', $line, $match)) {
                $field = strtolower($match[1]);
                if (isset($seenFields[$field])) return false;
                $seenFields[$field] = true;
            }
        }
        $found = false;
        $individual = 0;
        foreach ($sections as $name => $values) {
            if (!is_array($values)) return false;
            $name = strtoupper((string) $name);
            if (!in_array($name, ['ENVIO', 'RETORNO'], true) && !preg_match('/^NFE\d+$/', $name)) return false;
            $values = array_change_key_case($values, CASE_LOWER);
            if (str_starts_with($name, 'NFE') && ++$individual > 1) return false;
            if (str_starts_with($name, 'NFE')
                && (int) substr($name, 3) !== (int) substr($key, 25, 9)) return false;
            $code = (string) ($values['cstat'] ?? '');
            if (!in_array($code, array_merge(['103', '104'], $allowedCodes), true)) return false;
            if (in_array($code, $allowedCodes, true)) {
                if (preg_match('/^\s*Rejei(?:c|ç)(?:a|ã)o\b/iu', (string) ($values['xmotivo'] ?? '')) !== 1) return false;
                $found = true;
            } elseif (str_starts_with($name, 'NFE')) return false;
            foreach (['chdfe', 'chnfe'] as $field) {
                if (isset($values[$field]) && !hash_equals($key, (string) $values[$field])) return false;
            }
            if (!empty($values['nprot']) || !empty($values['nrec'])) return false;
            foreach ($values as $field => $value) {
                if ((str_contains($field, 'xml') || str_contains($field, 'evento')) && (string) $value !== '') return false;
            }
            if (isset($values['tpamb']) && (string) $values['tpamb'] !== ($environment === 'homologation' ? '2' : '1')) return false;
        }
        return $found;
    }
}
