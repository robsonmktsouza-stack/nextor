# Nextor Fiscal — Fase 4: SEFAZ Homologação / Status Serviço

Auditoria: 01/10/2026.

## Limite da fase

Esta fase implementa apenas a infraestrutura necessária para consultar **NfeStatusServico** de NFC-e modelo 65 em **homologação**.

Não implementa autorização, consulta de protocolo, evento, inutilização, cancelamento, contingência, QR Code, CSC ou produção.

## Fontes oficiais auditadas

- Portal Nacional da NF-e — MOC 7.0.
- Portal Nacional da NF-e — NT 2016.002, que padroniza Web Services 4.00, métodos e parâmetros SOAP.
- Portal NFC-e/SVRS — Relação de Serviços Web, auditada em 01/10/2026.
- SEFAZ Bahia — página oficial NFC-e, confirmando uso da SVRS e URLs de homologação.
- Diretório oficial de schemas da SVRS, contendo `consStatServ_v4.00.xsd`, `leiauteConsStatServ_v4.00.xsd` e `retConsStatServ_v4.00.xsd`.

## Contrato oficial usado

```text
Web Service : NFeStatusServico4
Método      : nfeStatusServicoNF
Versão      : 4.00
SOAP        : 1.2 / Document-Literal
Entrada     : nfeDadosMsg
Saída       : nfeResultMsg
Namespace WS: http://www.portalfiscal.inf.br/nfe/wsdl/NFeStatusServico4
Namespace XML fiscal:
              http://www.portalfiscal.inf.br/nfe
```

O `consStatServ` contém:

- atributo `versao=4.00`;
- `tpAmb`;
- `cUF`;
- `xServ=STATUS`.

O parser de retorno exige `retConsStatServ` no namespace oficial e extrai:

- `tpAmb`;
- `verAplic`;
- `cStat`;
- `xMotivo`;
- `cUF`;
- `dhRecbto`;
- `tMed`;
- `dhRetorno`;
- `xObs`.

## Catálogo versionado

Arquivo:

`resources/fiscal/sefaz/endpoints/nfce-status-2026-10-01.json`

Campos de governança:

- versão;
- data da auditoria;
- modelo;
- fontes;
- autorizadores;
- topologia UF -> autorizador;
- serviço;
- ambiente;
- versão;
- URL;
- namespace WSDL;
- método;
- SOAP action;
- ativo/inativo.

### Topologias auditadas nesta versão

- AM -> AM
- BA -> SVRS
- GO -> GO
- MS -> MS
- MT -> MT
- PR -> PR
- RS -> RS
- SP -> SP

As UFs restantes **não são inferidas**. Até auditoria oficial individual ou publicação de uma tabela nacional inequívoca, o registry lança `SefazEndpointException`.

Isso mantém a arquitetura multi-UF sem transformar uma suposição em regra operacional.

## Arquitetura

```text
app/Fiscal/Sefaz/
├── Contracts/
├── DTO/
├── Endpoints/
├── Enums/
├── Exceptions/
├── Services/
└── Support/

app/Fiscal/Soap/
├── Soap12EnvelopeBuilder
└── Soap12ResponseParser
```

### Fluxo StatusServiceClient

1. recebe `FiscalCompany`;
2. bloqueia produção;
3. valida UF;
4. resolve autorizador pelo catálogo;
5. resolve endpoint pelo catálogo;
6. gera `consStatServ`;
7. monta SOAP 1.2;
8. cria `FiscalTransmission` com UUID;
9. abre mTLS A1;
10. faz POST sem redirect/retry;
11. separa erro HTTP/TLS/transport;
12. extrai `nfeResultMsg`;
13. interpreta `retConsStatServ`;
14. confere ambiente e cUF;
15. persiste cStat/xMotivo e hashes;
16. devolve `SefazStatusResult`.

## mTLS A1

`A1MutualTlsMaterialProvider` reutiliza o leitor A1 próprio do Nextor.

O PFX permanece criptografado no banco. Para a chamada cURL:

- certificado + cadeia disponível no PFX são exportados para PEM temporário;
- chave privada é exportada separadamente;
- diretório privado: 0700;
- arquivos: 0600;
- nomes: aleatórios;
- limpeza: `finally`;
- nenhuma senha é passada por linha de comando;
- nenhum segredo é registrado.

## Segurança de transporte

`CurlSecurityOptions` impõe:

- `CURLOPT_SSL_VERIFYPEER=true`;
- `CURLOPT_SSL_VERIFYHOST=2`;
- somente HTTPS;
- TLS 1.2 como mínimo negociável;
- `CURLOPT_FOLLOWLOCATION=false`.

Não há configuração `CAINFO` própria: cURL usa o trust store confiável do sistema/PHP.

Não existe retry automático.

## Persistência

Migration:

`2026_10_01_000007_create_fiscal_transmissions_table.php`

`request_payload` e `response_payload` são `longText` com cast `encrypted` no model. O banco também guarda SHA-256 dos bytes transmitidos/recebidos.

Para Status Serviço, `fiscal_document_id` é nulo. Em fases futuras, uma NFC-e poderá possuir várias tentativas de transmissão sem confundir `FiscalDocument` com `TransmissionAttempt`.

## Estados fiscais locais

A falha de `validate()` depois da assinatura passa a promover:

`signed -> error`

O XML assinado não é sobrescrito e não pode ser regenerado.

Somente um documento em `validated` poderá atravessar a futura fronteira de autorização.

## Diagnóstico Artisan

```bash
php artisan fiscal:sefaz-status {company}
```

A saída mostra apenas:

- empresa;
- UF;
- ambiente;
- autorizador;
- serviço;
- endpoint público;
- HTTP;
- cStat;
- xMotivo;
- duração;
- UUID da tentativa.

Nenhum segredo ou XML completo é exibido.

## Testes offline

O CI valida sem internet:

- UF/autorizador/endpoint;
- topologia não auditada falhando fechada;
- catálogo duplicado;
- `consStatServ`;
- SOAP 1.2;
- parser `retConsStatServ`;
- XML malformado/namespace incorreto;
- HTTP vs cStat;
- erro HTTP;
- erro TLS;
- timeout;
- produção bloqueada;
- persistência criptografada;
- segurança cURL;
- lifecycle dos PEM temporários;
- bytes de `xml_signed` preservados;
- política `signed -> error`.

## Teste live

Arquivo:

`tests/Integration/Fiscal/SefazStatusServiceIntegrationTest.php`

É opt-in:

```env
FISCAL_LIVE_TESTS=true
FISCAL_LIVE_COMPANY_ID=1
```

O teste não assume `cStat=107`; ele exige transporte HTTPS bem-sucedido e resposta fiscal estruturada com cStat de três dígitos.

Certificado real, PFX e senha nunca são adicionados ao Git.

## Critério para primeiro teste real

Após cadastrar uma empresa fiscal de homologação com A1 válido:

```bash
php artisan fiscal:sefaz-status 1
```

O comando deve realizar uma chamada real ao endpoint auditado da UF e apresentar HTTP e `cStat/xMotivo` oficiais.

Nesta implementação não há certificado empresarial real no repositório ou na sessão de desenvolvimento, portanto o teste live permanece preparado, porém não é executado pelo CI.
