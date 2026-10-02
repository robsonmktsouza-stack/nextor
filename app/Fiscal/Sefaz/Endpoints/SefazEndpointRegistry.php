<?php

namespace App\Fiscal\Sefaz\Endpoints;

use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Sefaz\DTO\SefazAuthorizer;
use App\Fiscal\Sefaz\DTO\SefazEndpoint;
use App\Fiscal\Sefaz\Enums\SefazServiceType;
use App\Fiscal\Sefaz\Exceptions\SefazEndpointException;
use JsonException;

final class SefazEndpointRegistry
{
    public function __construct(
        private readonly ?string $catalogPath = null,
    ) {
    }

    public function resolve(
        string $uf,
        FiscalEnvironment $environment,
        SefazServiceType $service,
    ): SefazEndpoint {
        if ($environment !== FiscalEnvironment::HOMOLOGATION) {
            throw new SefazEndpointException(
                'O catálogo operacional desta fase contém somente endpoints de homologação.'
            );
        }

        $catalog = $this->catalog();
        $uf = strtoupper(trim($uf));

        $authorizerCode = null;
        foreach ($catalog['topology'] as $topology) {
            if ($topology['uf'] === $uf) {
                if ($authorizerCode !== null) {
                    throw new SefazEndpointException("Topologia duplicada para a UF {$uf}.");
                }

                $authorizerCode = $topology['authorizer'];
            }
        }

        if ($authorizerCode === null) {
            throw new SefazEndpointException(
                "A topologia NFC-e da UF {$uf} ainda não foi auditada no catálogo versionado."
            );
        }

        $authorizerData = null;
        foreach ($catalog['authorizers'] as $authorizer) {
            if ($authorizer['code'] === $authorizerCode) {
                $authorizerData = $authorizer;
                break;
            }
        }

        if ($authorizerData === null) {
            throw new SefazEndpointException(
                "Autorizador {$authorizerCode} referenciado pela UF {$uf} não existe no catálogo."
            );
        }

        $matches = [];
        foreach ($catalog['endpoints'] as $endpoint) {
            if (
                $endpoint['active'] === true
                && $endpoint['authorizer'] === $authorizerCode
                && $endpoint['environment'] === $environment->value
                && $endpoint['service'] === $service->value
            ) {
                $matches[] = $endpoint;
            }
        }

        if (count($matches) !== 1) {
            throw new SefazEndpointException(
                count($matches) === 0
                    ? "Endpoint {$service->value} não encontrado para {$uf}/{$authorizerCode} em homologação."
                    : "Catálogo possui endpoints duplicados para {$uf}/{$authorizerCode}/{$service->value}."
            );
        }

        $data = $matches[0];
        $this->assertEndpoint($data);

        return new SefazEndpoint(
            key: $data['key'],
            uf: $uf,
            authorizer: new SefazAuthorizer(
                code: $authorizerData['code'],
                name: $authorizerData['name'],
            ),
            environment: $environment,
            service: $service,
            version: $data['version'],
            url: $data['url'],
            wsdlNamespace: $data['wsdl_namespace'],
            operation: $data['operation'],
            soapAction: $data['soap_action'],
        );
    }

    private function catalog(): array
    {
        $path = $this->catalogPath
            ?? resource_path('fiscal/sefaz/endpoints/nfce-status-2026-10-01.json');

        if (!is_file($path)) {
            throw new SefazEndpointException('Catálogo versionado de endpoints SEFAZ não encontrado.');
        }

        try {
            $catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SefazEndpointException('Catálogo SEFAZ contém JSON inválido.', previous: $e);
        }

        foreach (['version', 'audited_at', 'document_model', 'sources', 'authorizers', 'topology', 'endpoints'] as $field) {
            if (!array_key_exists($field, $catalog)) {
                throw new SefazEndpointException("Catálogo SEFAZ sem o campo obrigatório '{$field}'.");
            }
        }

        if ($catalog['document_model'] !== '65') {
            throw new SefazEndpointException('Catálogo SEFAZ desta fase deve ser exclusivo para NFC-e modelo 65.');
        }

        return $catalog;
    }

    private function assertEndpoint(array $endpoint): void
    {
        foreach (['key', 'version', 'url', 'wsdl_namespace', 'operation', 'soap_action'] as $field) {
            if (!isset($endpoint[$field]) || !is_string($endpoint[$field]) || trim($endpoint[$field]) === '') {
                throw new SefazEndpointException("Endpoint contém campo obrigatório inválido: {$field}.");
            }
        }

        $parts = parse_url($endpoint['url']);

        if (
            !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || filter_var($endpoint['url'], FILTER_VALIDATE_URL) === false
        ) {
            throw new SefazEndpointException('Endpoint fiscal precisa usar uma URL HTTPS válida.');
        }
    }
}
