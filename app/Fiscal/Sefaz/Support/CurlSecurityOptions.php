<?php

namespace App\Fiscal\Sefaz\Support;

final class CurlSecurityOptions
{
    private function __construct()
    {
    }

    /** @return array<int,mixed> */
    public static function make(): array
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('Extensão cURL não está disponível no PHP.');
        }

        return [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        ];
    }
}
