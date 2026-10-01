<?php

namespace App\Fiscal\Certificate;

use App\Fiscal\Exceptions\CertificateException;

final class Asn1DerReader
{
    /**
     * @return array{class:int,constructed:bool,tag:int,value:string}
     */
    public function read(string $der, int &$offset = 0): array
    {
        $length = strlen($der);

        if ($offset >= $length) {
            throw new CertificateException('Estrutura DER truncada.');
        }

        $identifier = ord($der[$offset++]);
        $class = ($identifier & 0xC0) >> 6;
        $constructed = (bool) ($identifier & 0x20);
        $tag = $identifier & 0x1F;

        if ($tag === 0x1F) {
            $tag = 0;
            do {
                if ($offset >= $length) {
                    throw new CertificateException('Tag ASN.1 DER truncada.');
                }

                $byte = ord($der[$offset++]);
                $tag = ($tag << 7) | ($byte & 0x7F);
            } while (($byte & 0x80) !== 0);
        }

        if ($offset >= $length) {
            throw new CertificateException('Comprimento ASN.1 DER ausente.');
        }

        $firstLength = ord($der[$offset++]);

        if (($firstLength & 0x80) === 0) {
            $valueLength = $firstLength;
        } else {
            $octets = $firstLength & 0x7F;

            if ($octets === 0 || $octets > 4 || $offset + $octets > $length) {
                throw new CertificateException('Comprimento ASN.1 DER inválido.');
            }

            $valueLength = 0;
            for ($i = 0; $i < $octets; $i++) {
                $valueLength = ($valueLength << 8) | ord($der[$offset++]);
            }
        }

        if ($offset + $valueLength > $length) {
            throw new CertificateException('Valor ASN.1 DER truncado.');
        }

        $value = substr($der, $offset, $valueLength);
        $offset += $valueLength;

        return [
            'class' => $class,
            'constructed' => $constructed,
            'tag' => $tag,
            'value' => $value,
        ];
    }

    /**
     * @return list<array{class:int,constructed:bool,tag:int,value:string}>
     */
    public function children(string $der): array
    {
        $nodes = [];
        $offset = 0;
        $length = strlen($der);

        while ($offset < $length) {
            $nodes[] = $this->read($der, $offset);
        }

        return $nodes;
    }

    public function decodeOid(string $value): string
    {
        if ($value === '') {
            throw new CertificateException('OID ASN.1 vazio.');
        }

        $first = ord($value[0]);
        $parts = [intdiv($first, 40), $first % 40];
        $current = 0;

        for ($i = 1, $length = strlen($value); $i < $length; $i++) {
            $byte = ord($value[$i]);
            $current = ($current << 7) | ($byte & 0x7F);

            if (($byte & 0x80) === 0) {
                $parts[] = $current;
                $current = 0;
            }
        }

        if ($current !== 0) {
            throw new CertificateException('OID ASN.1 truncado.');
        }

        return implode('.', $parts);
    }

    /**
     * @param array{class:int,constructed:bool,tag:int,value:string} $node
     */
    public function decodeText(array $node): string
    {
        if ($node['constructed']) {
            $parts = [];

            foreach ($this->children($node['value']) as $child) {
                $text = $this->decodeText($child);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return implode('', $parts);
        }

        if ($node['class'] !== 0) {
            return $this->printableBytes($node['value']);
        }

        return match ($node['tag']) {
            4 => $this->decodeOctetString($node['value']),
            12, 18, 19, 20, 22, 26, 27 => $this->printableBytes($node['value']),
            30 => function_exists('mb_convert_encoding')
                ? trim((string) mb_convert_encoding($node['value'], 'UTF-8', 'UTF-16BE'))
                : '',
            default => $this->printableBytes($node['value']),
        };
    }

    private function decodeOctetString(string $value): string
    {
        if ($value !== '' && (ord($value[0]) & 0x1F) !== 0) {
            try {
                $children = $this->children($value);

                if ($children !== []) {
                    return implode('', array_map(
                        fn (array $child) => $this->decodeText($child),
                        $children,
                    ));
                }
            } catch (CertificateException) {
                // OCTET STRING pode conter texto puro; nesse caso usamos fallback abaixo.
            }
        }

        return $this->printableBytes($value);
    }

    private function printableBytes(string $value): string
    {
        return trim((string) preg_replace('/[^\x20-\x7E]/', '', $value));
    }
}
