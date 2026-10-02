<?php

namespace Tests\Support;

use RuntimeException;

final class TestA1CertificateFactory
{
    /**
     * @return array{pfx:string,password:string}
     */
    public static function make(string $password = 'nextor-test-password'): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        if ($key === false) {
            throw new RuntimeException('Não foi possível criar chave RSA de teste.');
        }

        $csr = openssl_csr_new(
            ['commonName' => 'NEXTOR FISCAL TEST CERTIFICATE'],
            $key,
            ['digest_alg' => 'sha256'],
        );

        if ($csr === false) {
            throw new RuntimeException('Não foi possível criar CSR de teste.');
        }

        $certificate = openssl_csr_sign(
            $csr,
            null,
            $key,
            2,
            ['digest_alg' => 'sha256'],
        );

        if ($certificate === false) {
            throw new RuntimeException('Não foi possível criar certificado X.509 de teste.');
        }

        if (!openssl_pkcs12_export($certificate, $pfx, $key, $password)) {
            throw new RuntimeException('Não foi possível exportar PFX de teste.');
        }

        return [
            'pfx' => $pfx,
            'password' => $password,
        ];
    }

    private function __construct()
    {
    }
}
