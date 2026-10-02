# Nextor Fiscal — Fase 3: Tax Engine + XMLDSig

Auditoria e implementação: 01/10/2026.

## Objetivo

Fechar o primeiro caminho integral e local da NFC-e:

```text
dados comerciais
  -> Tax Engine
  -> TaxResult
  -> snapshot
  -> FiscalDocument
  -> XML NFC-e 4.00
  -> XMLDSig A1
  -> verificação local
  -> XSD oficial
  -> state=validated
```

Não há comunicação com SEFAZ nesta fase.

## Fontes oficiais revalidadas

### NF-e/NFC-e

- MOC NF-e 7.0 e Anexo I, Portal Nacional.
- Schemas oficiais NF-e/NFC-e 4.00.
- Pacote `PL_010f_v1.04`, publicado em 31/08/2026 e preservado no repositório com hashes SHA-256.
- NT 2025.002 v1.52.
- NT 2026.002 v1.11.
- NT 2026.004 v1.01.
- NT 2026.007 v1.10.
- NT 2026.008 v1.00.
- Informes Técnicos e tabela oficial de Classificação Tributária IBS/CBS.

### Certificação/assinatura

- DOC-ICP-04 / ICP-Brasil.
- MOC NF-e — seção de assinatura digital.
- XML Signature Syntax and Processing / W3C como referência complementar.

### Divergência criptográfica documentada

O W3C atual não recomenda SHA-1 para novos protocolos. O perfil NF-e vigente, entretanto, especifica RSA-SHA1 e SHA-1. O Nextor segue o MOC fiscal, não substitui unilateralmente algoritmos.

## Auditoria do produto

| Situação | Campos |
|---|---|
| EXISTE | NCM, CEST, origem, GTIN, benefício fiscal, unidade comercial, unidade tributável opcional, notas fiscais, `tax_group` |
| FALTA | regra fiscal normalizada com vigência/versão |
| PRECISA NORMALIZAR | `products.tax_group` deixa de ser uma regra em texto e passa a apontar conceitualmente para `fiscal_tax_groups.code` |

Nenhuma coluna fiscal nova foi adicionada a `products` nesta fase.

## Banco Tax

### fiscal_tax_groups

Identifica um agrupamento fiscal reutilizável:

- code;
- name;
- CRT;
- modelo;
- ativo.

### fiscal_tax_rules

Mantém a regra por cenário e vigência:

- grupo;
- operation_scope;
- CFOP;
- CSOSN;
- PIS CST/taxa/base;
- COFINS CST/taxa/base;
- modo RTC;
- CST IBS/CBS;
- cClassTrib;
- taxas IBS UF/Mun/CBS;
- modo de base RTC;
- versão;
- vigência.

Nenhuma migration popula regra fiscal genérica. A configuração precisa ser criada deliberadamente.

## Primeiro cenário suportado

- NFC-e 65;
- CRT 1;
- venda interna;
- consumidor final;
- presencial;
- finalidade normal;
- mercadoria;
- tpEmis normal;
- regras 2026;
- CSOSN 102.

PIS/COFINS não são definidos “porque é Simples”. O resolver exige configuração explícita. A fixture automatizada usa CST 49 e taxa 0 somente para exercitar o caminho estrutural selecionado; isso não transforma essa combinação em regra geral automática.

IBS/CBS também depende de regra explícita. O cenário de teste usa `CST 000 / cClassTrib 000001` porque está cadastrado no catálogo versionado para o teste de tributação integral; o motor não associa esse código automaticamente a todo produto ou NCM.

## Cenários não suportados

Nesta fase, o resolver bloqueia:

- CSOSN diferente do caminho inicial 102;
- ST;
- ICMS monofásico;
- DIFAL;
- importação/exportação;
- devolução/retorno;
- bonificação;
- combustíveis;
- medicamentos;
- veículos;
- telecom;
- serviços;
- unidade tributável diferente sem fator de conversão;
- RTC fora da versão 2026;
- classificações IBS/CBS fora do catálogo versionado.

## Tax architecture

```text
Product
  |
  +-- NCM/origem
  +-- tax_group
          |
          v
FiscalTaxGroup
          |
          v
FiscalTaxRule (scope + vigência + versão)
          |
          v
SimpleNationalRetailTaxEngine
          |
          +-- IcmsTaxResult
          +-- PisTaxResult
          +-- CofinsTaxResult
          +-- IbsCbsTaxResult
          |
          v
FiscalItemTaxResult
          |
          v
TaxResult
```

Arrays aparecem somente na fronteira de serialização para o snapshot/XML. A resolução usa objetos tipados.

## Política de falha

Exemplos de bloqueios:

- produto sem NCM;
- origem inválida;
- produto sem grupo fiscal;
- grupo inexistente/inativo;
- regra sem vigência;
- múltiplas regras simultaneamente vigentes;
- CFOP fora do escopo interno;
- CSOSN conhecido mas ainda não suportado;
- PIS/COFINS fora do caminho configurado;
- configuração RTC incompleta;
- cClassTrib fora do catálogo;
- taxa RTC 2026 divergente;
- totais inconsistentes.

Não existe fallback tributário silencioso.

## Decimais

`Money`, `Quantity`, `UnitPrice` e `TaxRate` rejeitam float/int e aceitam string decimal.

`DecimalMath` faz matemática decimal diretamente em strings. O objetivo é impedir que diferenças de ponto flutuante entrem em cálculo, snapshot, XML ou testes.

O arredondamento de resultados de multiplicação/percentual é centralizado e não é decidido em cada imposto separadamente.

## RTC 2026

O primeiro resolver é restrito ao ano de 2026.

A configuração precisa declarar:

- `pIBSUF=0.1000`;
- `pIBSMun=0.0000`;
- `pCBS=0.9000`.

`Rtc2026Rates` verifica esses valores. Ele não os injeta na regra.

A base segue a regra UB16 da NT 2025.002 dentro do cenário suportado. Como não há ICMS, FCP, ISS ou IS nesse cenário, a implementação reduz a fórmula geral ao valor da operação menos PIS/COFINS.

### vItem

Em 2026, a exceção transitória aplicável determina que IBS/CBS/IS não sejam somados ao `vItem`. A regra fica no Tax Engine versionado.

## Totais

Antes da geração XML, `TaxTotalsValidator` recalcula:

- vProd;
- vFrete;
- vSeg;
- vDesc;
- vOutro;
- vPIS;
- vCOFINS;
- vBCIBSCBS;
- IBS UF/Mun/total;
- CBS;
- vNF;
- vNFTot.

Não há tolerância monetária inventada: strings normalizadas precisam coincidir exatamente.

## Pagamentos

`FiscalPaymentMethod`:

| Interno | tPag |
|---|---:|
| cash | 01 |
| credit_card | 03 |
| debit_card | 04 |
| bank_slip | 15 |
| pix | 17 |
| bank_transfer | 18 |
| other | 99 |

Cartão exige ao menos `tpIntegra`. Campos como CNPJ da instituição, bandeira, autorização, beneficiário e terminal são opcionais e só aparecem se realmente informados.

## XMLDSig

### Perfil implementado

```text
NFe
├── infNFe Id="NFe{44 posições}"
└── ds:Signature
    ├── ds:SignedInfo
    │   ├── ds:CanonicalizationMethod  C14N 1.0
    │   ├── ds:SignatureMethod         RSA-SHA1
    │   └── ds:Reference URI="#NFe..."
    │       ├── ds:Transforms
    │       │   ├── Enveloped Signature
    │       │   └── C14N 1.0
    │       ├── ds:DigestMethod        SHA-1
    │       └── ds:DigestValue
    ├── ds:SignatureValue
    └── ds:KeyInfo
        └── ds:X509Data
            └── ds:X509Certificate
```

### Fluxo

1. carrega `xml_generated`;
2. localiza exatamente um `infNFe`;
3. confere `Id=NFe{chave}`;
4. canonicaliza `infNFe`;
5. calcula SHA-1 em bytes e Base64;
6. monta `SignedInfo`;
7. canonicaliza `SignedInfo`;
8. assina com OpenSSL/RSA-SHA1;
9. inclui `SignatureValue`;
10. inclui apenas o certificado público em `X509Certificate`;
11. persiste o resultado em `xml_signed`.

O PFX, a senha e a chave privada nunca são incluídos no XML.

## Verificador local

`XmlSignatureVerifier` não confia apenas no fato de existir uma tag Signature.

Ele verifica:

- referência e Id;
- algoritmos;
- transforms;
- digest;
- certificado;
- assinatura RSA.

Teste de adulteração altera `xProd` após a assinatura e exige resultado inválido.

## XSD

`xml_generated` continua intencionalmente inválido contra o `TNFe` completo porque não contém assinatura.

Depois de `sign()`, a validação passa por:

```text
XmlSignatureVerifier
       +
SchemaValidator / PL_010f_v1.04
       |
       v
FiscalValidationResult
```

O estado só muda para `validated` quando **ambos** retornarem sucesso.

## Estados

```text
draft
  -> generated
  -> signed
  -> validated
```

A ordem antiga `generated -> validated -> signed` foi removida porque não representa o XSD oficial completo.

## Fachada

`FiscalEngineInterface` contém somente operações reais da fase:

- `createDocument()`;
- `generate()`;
- `sign()`;
- `validate()`;
- `getGeneratedXml()`;
- `getSignedXml()`.

Não existem métodos fake de emissão/autorização/cancelamento/status.

`NextorFiscalEngine` é a implementação da fachada e foi registrada no container do Laravel.

## Imutabilidade

Permanecem imutáveis:

- snapshot;
- hash;
- chave;
- número;
- série;
- ambiente;
- data de emissão;
- vínculo principal.

Além disso:

- `xml_generated` é write-once;
- `xml_signed` é write-once.

Não existe correção silenciosa de um XML já assinado.

## Testes da fase

Cobertura adicionada:

### Tax

- cenário suportado;
- NCM ausente;
- grupo fiscal ausente;
- origem inválida;
- CFOP incompatível;
- CSOSN conhecido porém não suportado;
- PIS não inferido;
- COFINS não inferido;
- RTC divergente;
- cClassTrib fora do catálogo;
- decimais sem float;
- dinheiro;
- PIX;
- cartão sem dados fictícios.

### Certificado/assinatura

- PFX gerado dinamicamente para teste;
- senha correta/incorreta;
- certificado expirado;
- assinatura criada;
- Reference URI;
- DigestValue;
- SignatureValue;
- X509Certificate;
- chave privada/senha ausentes no XML;
- verificação criptográfica;
- adulteração invalida assinatura;
- XML assinado write-once.

Nenhum PFX, chave privada ou senha real é commitado.

### XSD/estado

- XML sem assinatura permanece inválido;
- XML assinado precisa validar no pacote oficial;
- erros libxml são limpos;
- `draft -> generated -> signed -> validated`;
- transições antigas inválidas são bloqueadas.

## Limitações

O resultado `validated` nesta fase significa validade **local** de assinatura + schema. Não significa autorização fiscal.

Não foram implementados:

- SEFAZ;
- SOAP;
- endpoints;
- statusServico;
- autorização;
- consulta;
- cancelamento;
- inutilização;
- contingência;
- CSC/QR Code;
- DANFE final;
- integração com o PDV.

## Próxima fase

`NEXTOR FISCAL — SEFAZ HOMOLOGAÇÃO`, iniciando por catálogo oficial de autorizadores/endpoints, TLS/certificado, `statusServico()` e autorização síncrona.
