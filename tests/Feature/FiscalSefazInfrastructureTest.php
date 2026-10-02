<?php

namespace Tests\Feature;

use App\Fiscal\Enums\FiscalDocumentState;
use App\Fiscal\Enums\FiscalEnvironment;
use App\Fiscal\Models\FiscalDocument;
use App\Fiscal\Sefaz\DTO\SignedFiscalXml;
use App\Fiscal\Sefaz\DTO\StatusServiceRequest;
use App\Fiscal\Sefaz\Endpoints\SefazEndpointRegistry;
use App\Fiscal\Sefaz\Enums\SefazServiceType;
use App\Fiscal\Sefaz\Exceptions\SefazEndpointException;
use App\Fiscal\Sefaz\Exceptions\SefazResponseException;
use App\Fiscal\Sefaz\Services\StatusServiceRequestXmlBuilder;
use App\Fiscal\Sefaz\Services\StatusServiceResponseParser;
use App\Fiscal\Sefaz\Support\CurlSecurityOptions;
use App\Fiscal\Soap\Soap12EnvelopeBuilder;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

class FiscalSefazInfrastructureTest extends TestCase
{
    public function test_bahia_nfce_homologation_resolves_to_audited_svrs_endpoint(): void
    {
        $endpoint = app(SefazEndpointRegistry::class)->resolve(
            'BA',
            FiscalEnvironment::HOMOLOGATION,
            SefazServiceType::STATUS_SERVICE,
        );

        $this->assertSame('SVRS', $endpoint->authorizer->code);
        $this->assertSame('4.00', $endpoint->version);
        $this->assertSame(
            'https://nfce-homologacao.svrs.rs.gov.br/ws/NfeStatusServico/NfeStatusServico4.asmx',
            $endpoint->url,
        );
        $this->assertSame('nfeStatusServicoNF', $endpoint->operation);
    }

    public function test_direct_authorizer_mapping_is_multi_uf_and_not_bahia_specific(): void
    {
        $endpoint = app(SefazEndpointRegistry::class)->resolve(
            'SP',
            FiscalEnvironment::HOMOLOGATION,
            SefazServiceType::STATUS_SERVICE,
        );

        $this->assertSame('SP', $endpoint->authorizer->code);
        $this->assertSame(
            'https://homologacao.nfce.fazenda.sp.gov.br/ws/NFeStatusServico4.asmx',
            $endpoint->url,
        );
    }

    public function test_unaudited_topology_fails_closed_instead_of_guessing_svrs(): void
    {
        $this->expectException(SefazEndpointException::class);
        $this->expectExceptionMessage('ainda não foi auditada');

        app(SefazEndpointRegistry::class)->resolve(
            'AC',
            FiscalEnvironment::HOMOLOGATION,
            SefazServiceType::STATUS_SERVICE,
        );
    }

    public function test_duplicate_topology_is_rejected(): void
    {
        $source = json_decode(
            file_get_contents(resource_path('fiscal/sefaz/endpoints/nfce-status-2026-10-01.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $source['topology'][] = ['uf' => 'BA', 'authorizer' => 'SVRS', 'source' => 'duplicate-test'];

        $path = tempnam(sys_get_temp_dir(), 'nextor-sefaz-');
        file_put_contents($path, json_encode($source, JSON_THROW_ON_ERROR));

        try {
            $registry = new SefazEndpointRegistry($path);

            $this->expectException(SefazEndpointException::class);
            $this->expectExceptionMessage('Topologia duplicada');

            $registry->resolve(
                'BA',
                FiscalEnvironment::HOMOLOGATION,
                SefazServiceType::STATUS_SERVICE,
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_cons_stat_serv_400_has_official_namespace_and_fields(): void
    {
        $xml = app(StatusServiceRequestXmlBuilder::class)->build(
            new StatusServiceRequest(FiscalEnvironment::HOMOLOGATION, '29'),
        );

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $this->assertSame('4.00', $xpath->evaluate('string(/nfe:consStatServ/@versao)'));
        $this->assertSame('2', $xpath->evaluate('string(/nfe:consStatServ/nfe:tpAmb)'));
        $this->assertSame('29', $xpath->evaluate('string(/nfe:consStatServ/nfe:cUF)'));
        $this->assertSame('STATUS', $xpath->evaluate('string(/nfe:consStatServ/nfe:xServ)'));
    }

    public function test_soap_12_envelope_uses_wsdl_namespace_and_nfe_dados_msg(): void
    {
        $endpoint = app(SefazEndpointRegistry::class)->resolve(
            'BA',
            FiscalEnvironment::HOMOLOGATION,
            SefazServiceType::STATUS_SERVICE,
        );
        $payload = app(StatusServiceRequestXmlBuilder::class)->build(
            new StatusServiceRequest(FiscalEnvironment::HOMOLOGATION, '29'),
        );
        $soap = app(Soap12EnvelopeBuilder::class)->build($endpoint, $payload);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($soap));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('soap12', 'http://www.w3.org/2003/05/soap-envelope');
        $xpath->registerNamespace('ws', 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeStatusServico4');
        $xpath->registerNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $this->assertSame(
            1,
            $xpath->query('/soap12:Envelope/soap12:Body/ws:nfeDadosMsg/nfe:consStatServ')->length,
        );
    }

    public function test_ret_cons_stat_serv_fixture_is_parsed_structurally(): void
    {
        $soap = file_get_contents(base_path('tests/Fixtures/Fiscal/sefaz/status-service-107-soap12.xml'));
        $endpoint = app(SefazEndpointRegistry::class)->resolve(
            'BA',
            FiscalEnvironment::HOMOLOGATION,
            SefazServiceType::STATUS_SERVICE,
        );

        $inner = app(\App\Fiscal\Soap\Soap12ResponseParser::class)->extractResult($endpoint, $soap);
        $response = app(StatusServiceResponseParser::class)->parse($inner);

        $this->assertSame(FiscalEnvironment::HOMOLOGATION, $response->environment);
        $this->assertSame('SVRS20261001', $response->applicationVersion);
        $this->assertSame('29', $response->stateCode);
        $this->assertSame('107', $response->statusCode);
        $this->assertSame('Servico em Operacao', $response->reason);
        $this->assertSame(hash('sha256', $inner), $response->rawXmlHash);
    }

    public function test_malformed_or_wrong_namespace_response_is_rejected(): void
    {
        $parser = app(StatusServiceResponseParser::class);

        try {
            $parser->parse('<broken>');
            $this->fail('Malformed XML deveria falhar.');
        } catch (SefazResponseException) {
            $this->assertTrue(true);
        }

        $this->expectException(SefazResponseException::class);
        $this->expectExceptionMessage('namespace oficial');

        $parser->parse(
            '<retConsStatServ versao="4.00"><tpAmb>2</tpAmb><verAplic>X</verAplic>'
            .'<cStat>107</cStat><xMotivo>OK</xMotivo><cUF>29</cUF></retConsStatServ>'
        );
    }

    public function test_curl_security_options_never_disable_peer_or_hostname_validation(): void
    {
        $options = CurlSecurityOptions::make();

        $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        $this->assertSame(CURL_SSLVERSION_TLSv1_2, $options[CURLOPT_SSLVERSION]);
    }

    public function test_signed_xml_boundary_preserves_exact_bytes(): void
    {
        $bytes = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<NFe>  <signed/>\n</NFe>\n";
        $document = new FiscalDocument([
            'state' => FiscalDocumentState::VALIDATED,
            'xml_signed' => $bytes,
        ]);

        $payload = SignedFiscalXml::fromValidatedDocument($document);

        $this->assertSame($bytes, $payload->bytes());
        $this->assertSame(hash('sha256', $bytes), hash('sha256', $payload->bytes()));
    }

    public function test_signed_xml_boundary_rejects_non_validated_document(): void
    {
        $document = new FiscalDocument([
            'state' => FiscalDocumentState::SIGNED,
            'xml_signed' => '<NFe/>',
        ]);

        $this->expectException(SefazResponseException::class);
        SignedFiscalXml::fromValidatedDocument($document);
    }
}
