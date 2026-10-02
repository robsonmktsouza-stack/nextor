<?php

namespace App\Fiscal\Sefaz\Services;

use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Sefaz\Contracts\SefazTransportInterface;
use App\Fiscal\Sefaz\DTO\SefazEndpoint;
use App\Fiscal\Sefaz\DTO\SefazHttpResponse;
use App\Fiscal\Sefaz\DTO\TlsCertificateFiles;
use App\Fiscal\Sefaz\Exceptions\SefazTlsException;
use App\Fiscal\Sefaz\Exceptions\SefazTransportException;
use App\Fiscal\Sefaz\Support\A1MutualTlsMaterialProvider;
use App\Fiscal\Sefaz\Support\CurlSecurityOptions;

final class CurlMutualTlsHttpClient implements SefazTransportInterface
{
    public function __construct(
        private readonly A1MutualTlsMaterialProvider $materials,
    ) {
    }

    public function post(
        FiscalCompany $company,
        SefazEndpoint $endpoint,
        string $body,
    ): SefazHttpResponse {
        if (!extension_loaded('curl')) {
            throw new SefazTransportException('Extensão cURL não está disponível no PHP.');
        }

        return $this->materials->withPemFiles(
            $company,
            function (TlsCertificateFiles $files) use ($endpoint, $body): SefazHttpResponse {
                $curl = curl_init($endpoint->url);
                if ($curl === false) {
                    throw new SefazTransportException('Não foi possível inicializar o cliente HTTP fiscal.');
                }

                $options = CurlSecurityOptions::make() + [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => false,
                    CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) config('fiscal.sefaz.connect_timeout_ms')),
                    CURLOPT_TIMEOUT_MS => max(1, (int) config('fiscal.sefaz.request_timeout_ms')),
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_SSLCERT => $files->certificatePath,
                    CURLOPT_SSLKEY => $files->privateKeyPath,
                    CURLOPT_SSLCERTTYPE => 'PEM',
                    CURLOPT_SSLKEYTYPE => 'PEM',
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/soap+xml; charset=utf-8; action="'.$endpoint->soapAction.'"',
                        'Accept: application/soap+xml',
                        'Expect:',
                    ],
                ];

                curl_setopt_array($curl, $options);
                $started = hrtime(true);
                $response = curl_exec($curl);
                $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

                if ($response === false) {
                    $errno = curl_errno($curl);
                    $message = curl_error($curl);
                    curl_close($curl);

                    if ($this->isTlsError($errno)) {
                        throw new SefazTlsException(
                            'Não foi possível estabelecer conexão TLS com o autorizador.'
                        );
                    }

                    if ($errno === CURLE_OPERATION_TIMEDOUT) {
                        throw new SefazTransportException('Tempo limite excedido na comunicação com o autorizador.');
                    }

                    throw new SefazTransportException(
                        $message !== ''
                            ? 'Falha de transporte na comunicação SEFAZ: '.$this->sanitizeCurlMessage($message)
                            : 'Falha de transporte na comunicação SEFAZ.'
                    );
                }

                $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                curl_close($curl);

                return new SefazHttpResponse(
                    status: $httpStatus,
                    body: (string) $response,
                    durationMs: $durationMs,
                );
            },
        );
    }

    private function isTlsError(int $errno): bool
    {
        return in_array($errno, [35, 51, 58, 59, 60, 77, 80, 82, 83, 90, 91, 98], true);
    }

    private function sanitizeCurlMessage(string $message): string
    {
        $message = preg_replace('/\/[A-Za-z0-9_\.\-\/\\]+\.key\.pem/i', '[private-key]', $message) ?? $message;
        $message = preg_replace('/\/[A-Za-z0-9_\.\-\/\\]+\.cert\.pem/i', '[certificate]', $message) ?? $message;

        return mb_substr($message, 0, 300);
    }
}
