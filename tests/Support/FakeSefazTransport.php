<?php

namespace Tests\Support;

use App\Fiscal\Models\FiscalCompany;
use App\Fiscal\Sefaz\Contracts\SefazTransportInterface;
use App\Fiscal\Sefaz\DTO\SefazEndpoint;
use App\Fiscal\Sefaz\DTO\SefazHttpResponse;
use Throwable;

final class FakeSefazTransport implements SefazTransportInterface
{
    public ?string $lastBody = null;
    public ?SefazEndpoint $lastEndpoint = null;

    public function __construct(
        private readonly ?SefazHttpResponse $response = null,
        private readonly ?Throwable $error = null,
    ) {
    }

    public function post(
        FiscalCompany $company,
        SefazEndpoint $endpoint,
        string $body,
    ): SefazHttpResponse {
        $this->lastBody = $body;
        $this->lastEndpoint = $endpoint;

        if ($this->error !== null) {
            throw $this->error;
        }

        if ($this->response === null) {
            throw new \RuntimeException('FakeSefazTransport sem resposta configurada.');
        }

        return $this->response;
    }
}
