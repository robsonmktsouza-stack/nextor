# Nextor Fiscal Engine — arquitetura auditada em 01/10/2026

## Escopo atual

O Nextor Fiscal Engine é um motor fiscal próprio, isolado do PDV, para NFC-e modelo 65. A fase atual cobre:

- configuração fiscal por empresa;
- certificado A1;
- numeração e chave de acesso;
- documento e snapshot fiscal imutável;
- schemas oficiais NF-e/NFC-e 4.00;
- Tax Engine inicial e versionado;
- geração do XML NFC-e;
- XMLDSig;
- verificação criptográfica local;
- validação integral no XSD oficial.

Não há dependência de NFePHP, ACBr, API fiscal externa ou serviço pago.

Ainda não fazem parte do motor operacional: SOAP/SEFAZ, autorização, consulta, cancelamento, inutilização, contingência, CSC, QR Code e DANFE fiscal final.

## Fontes oficiais-base

Reauditoria em 01/10/2026:

- Portal Nacional da NF-e — MOC 7.0 e Anexo I.
- Portal Nacional da NF-e — schemas NF-e/NFC-e 4.00.
- Pacote ativo auditado: `PL_010f_v1.04`, publicado em 31/08/2026.
- NT 2025.002 v1.52 — Reforma Tributária do Consumo.
- NT 2026.002 v1.11.
- NT 2026.004 v1.01 — CNPJ alfanumérico.
- NT 2026.007 v1.10.
- NT 2026.008 v1.00.
- Informes Técnicos/tabelas RTC vigentes, inclusive classificação tributária IBS/CBS.
- DOC-ICP-04 / ICP-Brasil.
- XML Signature Syntax and Processing, W3C, apenas como referência técnica complementar ao perfil fiscal definido no MOC.

Antes de cada nova fase, versões, cronogramas, tabelas e schemas devem ser revalidados.

## Arquitetura

```text
dados comerciais tipados
        |
        v
FiscalEngineInterface
        |
        +--> TaxEngineInterface
        |      |
        |      +--> FiscalTaxGroup
        |      +--> FiscalTaxRule
        |      +--> catálogo cClassTrib versionado
        |      +--> TaxResult tipado
        |
        +--> FiscalDocumentCreator
        |      |
        |      +--> FiscalSequenceService
        |      +--> AccessKeyGenerator
        |      +--> NfceSnapshot + SHA-256
        |
        +--> NfceXmlService
        |      |
        |      +--> NfceXmlGenerator / DOMDocument
        |
        +--> FiscalDocumentSignatureService
        |      |
        |      +--> A1SigningMaterialProvider
        |      +--> XmlSigner
        |
        +--> FiscalDocumentValidationService
               |
               +--> XmlSignatureVerifier
               +--> SchemaValidator
                       |
                       +--> SchemaRegistry
                       +--> PL_010f_v1.04
```

O `PdvController`, o checkout, o estoque e o `SalesService` permanecem fora dessa arquitetura. O PDV só poderá consumir a fachada fiscal em fase posterior.

## Estrutura principal

```text
app/Fiscal/
├── Contracts/
│   └── FiscalEngineInterface.php
├── Certificate/
├── DTO/
├── Enums/
├── Exceptions/
├── Models/
├── Nfce/
├── Schema/
├── Signature/
├── State/
├── Support/
├── Tax/
│   ├── Contracts/
│   ├── DTO/
│   ├── Enums/
│   ├── Exceptions/
│   ├── Models/
│   ├── Resolver/
│   ├── Rules/
│   ├── Support/
│   ├── ValueObjects/
│   └── Versions/
├── Validation/
└── Xml/
```

## Banco implementado

- `fiscal_companies`: identidade/configuração fiscal do emitente.
- `fiscal_certificates`: PFX/senha criptografados; nunca em `public/`.
- `fiscal_sequences`: numeração por empresa/modelo/série/ambiente com `lockForUpdate`.
- `fiscal_documents`: snapshot, chave, estado e XMLs separados.
- `fiscal_tax_groups`: agrupamento fiscal reutilizável por CRT/modelo.
- `fiscal_tax_rules`: regras versionadas e com vigência por cenário.

O campo legado `products.tax_group` passa a funcionar como código de vinculação a `fiscal_tax_groups.code`. Nesta fase ele não foi convertido em FK para não quebrar o cadastro/PDV existente.

## Auditoria do cadastro de produto

### Existe

- NCM;
- CEST;
- origem;
- benefício fiscal;
- unidade comercial;
- unidade tributável opcional;
- grupo tributário;
- observações fiscais;
- GTIN.

### Falta no produto — deliberadamente

Não serão adicionados diretamente ao produto dezenas de campos como CFOP, CSOSN, CST PIS, CST COFINS, CST IBS/CBS e cClassTrib.

### Precisa normalizar

```text
Product.tax_group
        |
        v
FiscalTaxGroup.code
        |
        v
FiscalTaxRule
        |
        v
Tax Resolver
```

Isso permite vigência, versões e diferentes regras para um mesmo agrupamento sem transformar `products` em tabela de legislação tributária.

## Cenário Tax suportado nesta fase

Somente:

- modelo 65;
- CRT 1 — Simples Nacional;
- venda interna;
- consumidor final;
- operação presencial;
- finalidade normal;
- mercadoria normal;
- emissão normal;
- ano de regra 2026;
- CSOSN 102;
- PIS/COFINS pela configuração explícita suportada pelo resolver inicial;
- IBS/CBS com configuração explícita compatível com a regra/versionamento RTC de 2026.

O motor conhece a estrutura dos demais CSOSN presentes no leiaute ativo, mas não os resolve nesta fase. Código conhecido e não suportado gera erro explícito.

Não suportado: ST complexa, monofásico, DIFAL, exportação, importação, devolução, bonificação, combustível, medicamento, veículo, telecom, serviços e demais cenários especiais.

## Política de não inferência

O Tax Engine não escolhe silenciosamente:

- NCM;
- CFOP;
- origem;
- CSOSN;
- CST PIS;
- CST COFINS;
- CST IBS/CBS;
- cClassTrib;
- alíquotas.

Todos esses valores precisam estar presentes no produto/regra/catálogo correspondente e passar pelas validações da versão fiscal vigente. Dado ausente ou fora do cenário suportado bloqueia o documento.

## RTC 2026

A obrigatoriedade de preenchimento RTC para NFC-e modelo 65 foi incorporada ao cenário 2026.

A versão inicial do resolver aplica a fórmula de base compatível com a regra UB16 da NT 2025.002 apenas dentro do cenário estreito suportado. Como ICMS/FCP/ISS/IS são zero nesse cenário, a base é derivada do valor da operação menos PIS e COFINS.

As alíquotas transitórias de 2026 são verificadas contra constantes versionadas:

- IBS UF: 0,1000%;
- IBS Município: 0,0000%;
- CBS: 0,9000%.

Esses valores não são preenchidos automaticamente: a regra fiscal persistida precisa declará-los e o motor rejeita configuração divergente.

O catálogo inicial de `cClassTrib` é versionado em `resources/fiscal/tax/`. O primeiro cenário aceita apenas a classificação explicitamente cadastrada e validada. Nenhum NCM é convertido automaticamente em cClassTrib.

### vItem / vNFTot

O leiaute `PL_010f_v1.04` contém `vItem` e `vNFTot`. A regra transitória de 2026 determina que, excepcionalmente nesse período, IBS/CBS/IS não sejam adicionados a `vItem`. O Nextor isola essa regra na versão Tax, não no serializer XML.

## Decimais

Nenhum value object fiscal aceita `float` ou `int`. Entradas monetárias e tributárias são strings decimais.

```text
Money       -> 2 casas
Quantity    -> 4 casas
UnitPrice   -> 10 casas
TaxRate     -> 4 casas
```

`DecimalMath` executa adição, subtração, multiplicação, percentual e arredondamento em strings, sem converter valores fiscais para ponto flutuante e sem espalhar dependência de BCMath.

## Pagamentos

`FiscalPaymentMethod` desacopla os nomes internos do ERP do `tPag` fiscal.

```text
cash          -> 01
credit_card   -> 03
debit_card    -> 04
bank_slip     -> 15
pix           -> 17
bank_transfer -> 18
other         -> 99
```

Dados eletrônicos adicionais só são serializados quando efetivamente informados. O motor não fabrica adquirente, autorização, bandeira, CNPJ ou terminal.

## XMLDSig

O perfil implementado segue o MOC NF-e:

- assinatura Enveloped;
- referência: `#NFe{chave}`;
- alvo: `infNFe`;
- canonicalização: C14N 1.0;
- Transform 1: Enveloped Signature;
- Transform 2: C14N 1.0;
- DigestMethod: SHA-1;
- SignatureMethod: RSA-SHA1;
- `X509Certificate`: certificado DER em Base64, sem cabeçalhos PEM.

O W3C moderno desencoraja SHA-1 para novos protocolos, mas o Nextor segue o perfil fiscal oficial da NF-e enquanto essa for a especificação vigente. URIs criptográficas ficam centralizadas em `XmlSignatureAlgorithms`.

A chave privada nunca é inserida no XML ou retornada pela camada de assinatura. O PFX e sua senha permanecem criptografados no cofre A1 e o material privado só é carregado em memória durante a assinatura.

## Verificação local da assinatura

Antes de promover o documento:

1. valida-se que existe exatamente um `infNFe`;
2. `Id` precisa ser `NFe{chave}`;
3. `Reference URI` precisa apontar para o mesmo Id;
4. URIs de algoritmos/transforms precisam corresponder ao perfil;
5. o digest de `infNFe` é recalculado;
6. o certificado X.509 embutido fornece a chave pública;
7. `SignedInfo` é canonicalizado;
8. `SignatureValue` é verificado com OpenSSL;
9. o XML completo é submetido ao XSD oficial.

Alterar `xProd` ou qualquer conteúdo de `infNFe` depois da assinatura invalida o digest.

## Estados NFC-e

A ordem foi corrigida nesta fase porque o XSD oficial exige `ds:Signature`.

```text
draft
  -> generated
  -> signed
  -> validated
  -> pending                  (fase SEFAZ futura)
       -> authorized -> cancelled
       -> rejected -> generated
       -> error

validated -> contingency      (fase futura)
```

`validated` significa agora: assinatura local válida **e** XML completo válido contra o XSD oficial.

## XMLs persistidos

- `xml_generated`: XML antes da assinatura.
- `xml_signed`: XML com XMLDSig.
- `xml_protocolled`: reservado ao futuro `nfeProc` retornado após autorização.

`xml_generated` e `xml_signed` são write-once. Snapshot, chave, número, série, ambiente e data continuam imutáveis.

## Fluxo atual executável

```text
Tax Engine
  -> TaxResult
  -> snapshot fiscal imutável
  -> FiscalDocument (draft)
  -> generate()
  -> XML sem assinatura (generated)
  -> sign()
  -> XMLDSig A1 (signed)
  -> verify signature
  -> schemaValidate(PL_010f_v1.04)
  -> validated
```

## Fluxo futuro de homologação

Somente depois desta fase estável:

```text
validated
  -> statusServico
  -> NFeAutorizacao síncrona
  -> cStat/xMotivo
  -> persistência da transmissão
  -> consulta por chave quando necessário
  -> authorized
```

QR Code/CSC, DANFE final, cancelamento, inutilização e contingência permanecem fora da fase atual.

## Documentação detalhada

Ver `docs/fiscal/PHASE_3_TAX_SIGNATURE.md`.


## Fase 4 — infraestrutura SEFAZ de homologação

A camada de comunicação externa permanece isolada do PDV e, nesta fase, implementa somente `NfeStatusServico` para NFC-e modelo 65 em homologação.

```text
FiscalCompany
  -> UF
  -> SefazEndpointRegistry
  -> catálogo NFC-e versionado
  -> StatusServiceRequestXmlBuilder
  -> SOAP 1.2
  -> mTLS A1 / cURL
  -> autorizador oficial
  -> SOAP parser
  -> retConsStatServ
  -> FiscalTransmission
  -> SefazStatusResult
```

### Catálogo de endpoints

O catálogo fica em `resources/fiscal/sefaz/endpoints/` e é a única origem aceita para URLs SEFAZ. Não existe entrada de URL pelo operador.

O catálogo de 01/10/2026 é deliberadamente **homologação-only** e contém somente topologias auditadas nesta fase. UFs sem topologia auditada falham fechadas; o motor não presume SVRS.

A NFC-e da Bahia é resolvida para SVRS conforme página oficial da SEFAZ Bahia. AM, GO, MS, MT, PR, RS e SP possuem autorizadores próprios conforme a relação oficial de serviços do Portal NFC-e/SVRS.

### Padrão de comunicação

- Web Service: `NFeStatusServico4`;
- método: `nfeStatusServicoNF`;
- leiaute: 4.00;
- SOAP: 1.2, Document/Literal;
- entrada SOAP: `nfeDadosMsg`;
- saída SOAP: `nfeResultMsg`;
- TLS: 1.2 ou superior com autenticação mútua;
- certificado cliente: A1 armazenado pelo Nextor;
- verificação do certificado do servidor e hostname: obrigatórias;
- redirects: desabilitados;
- retry automático: inexistente.

O trust store utilizado é o confiável do sistema/PHP/cURL. O Nextor não inclui bundle de CA aleatório e não desliga validação SSL.

### Material A1 para mTLS

Quando cURL necessita PEM, o PFX é aberto em memória e o certificado/chave são exportados para arquivos temporários dentro de `storage/app/private/fiscal/tls`, com nomes aleatórios e permissão 0600. O diretório recebe 0700. Os arquivos são excluídos em `finally`, inclusive em exceções.

PFX, senha, chave privada e PEM completo nunca entram em logs.

### FiscalTransmission

Cada chamada externa é uma tentativa técnica independente do documento fiscal. A tabela `fiscal_transmissions` registra:

- empresa e documento opcional;
- serviço/UF/ambiente/autorizador/endpoint;
- `attempt_uuid`;
- timestamps e duração;
- HTTP e status de transporte;
- `cStat/xMotivo`;
- hashes SHA-256;
- request/response criptografados em repouso;
- classe/mensagem técnica de erro.

Para `STATUS_SERVICE`, request/response não contêm chave privada nem credenciais, mas os payloads são mesmo assim armazenados com cast `encrypted`. Logs de aplicação não recebem XML bruto.

### HTTP não é status fiscal

`HTTP 200` significa somente sucesso de transporte HTTP. O estado fiscal vem de `retConsStatServ/cStat`.

A classificação técnica atual reconhece 107, 108 e 109, mas o resultado preserva sempre `cStat` e `xMotivo` originais e não exige 107 para considerar o transporte bem-sucedido.

### Bloqueio de produção

`statusService()` rejeita qualquer empresa fora de homologação antes de resolver endpoint ou iniciar uma transmissão.

### Política após falha de validação local

```text
generated -> signed -> validate()
                     |
                     +-- válido   -> validated
                     |
                     +-- inválido -> error
```

Quando a validação local falha depois da assinatura, `xml_signed` permanece write-once e o documento passa para `error`. Regeneração silenciosa é bloqueada; uma nova tentativa lógica deverá criar novo documento fiscal.

A fronteira `SignedFiscalXml` aceita somente documento `validated` e entrega os bytes exatos de `xml_signed`, sem DOM, formatação ou reserialização.

Ver `docs/fiscal/PHASE_4_SEFAZ_STATUS.md`.
