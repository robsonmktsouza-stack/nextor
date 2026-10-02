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
