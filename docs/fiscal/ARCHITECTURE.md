# Nextor Fiscal Engine — arquitetura auditada em 01/10/2026

## Escopo atual

Somente NFC-e modelo 65, inicialmente em homologação, com certificado A1. Não há dependência de API fiscal paga, ACBrLib ou NFePHP.

## Fontes oficiais-base

- Portal Nacional da NF-e — MOC 7.0 e Anexo I (leiaute/regras NF-e/NFC-e).
- Portal Nacional da NF-e — Web Services versão 4.00.
- Manual de Especificações Técnicas do DANFE NFC-e e QR Code v6.0 (março/2025).
- Manual de Especificações da Contingência Offline para NFC-e v2.0.
- NT 2025.002 v1.51 — Reforma Tributária do Consumo, IBS/CBS.
- NT 2026.002 v1.10 — regras atuais relacionadas às operações presenciais/não presenciais.
- NT 2026.004 v1.01 — CNPJ e chave de acesso alfanuméricos.
- NT 2023.002 v1.01 — elimina denegação e lote para NFC-e.

Antes de implementar cada etapa fiscal, revalidar versões no Portal Nacional.

## Mapa

```text
PDV / Vendas
   |
   v
FiscalEngineInterface            (fachada; fase de transmissão)
   |
   +-- Company/Configuration      fiscal_companies
   +-- Certificate               A1 reader + cofre criptografado
   +-- Nfce
   |    +-- AccessKeyGenerator
   |    +-- FiscalSequenceService
   |    +-- Xml                   (próxima fase)
   |    +-- Tax                   (próxima fase)
   |    +-- QrCode                (fase posterior)
   |    +-- Danfe                 (fase posterior)
   |
   +-- Schema                    catálogo + XSD oficiais versionados
   +-- Signature                 XMLDSig (fase posterior)
   +-- Sefaz / Soap              WS 4.00 por UF/ambiente (fase posterior)
   +-- Events                    cancelamento (fase posterior)
   +-- Contingency               offline (somente após emissão normal)
   +-- State                     máquina de estados
   +-- Validation                XSD + regras de aplicação
   +-- Persistence               documentos/eventos/transmissões
```

## Estrutura de código alvo

```text
app/Fiscal/
├── Contracts/
├── Certificate/
├── Contingency/
├── Danfe/
├── DTO/
├── Enums/
├── Events/
├── Exceptions/
├── Models/
├── Nfce/
├── QrCode/
├── Schema/
├── Sefaz/
├── Signature/
├── Soap/
├── State/
├── Support/
├── Tax/
├── Validation/
├── Versions/
└── Xml/
```

O `PdvController` não conterá regras fiscais. Quando a fachada do motor for introduzida, o PDV conversará somente com o contrato fiscal de aplicação.

## Serviços oficiais necessários para NFC-e

Fluxo principal:
- `NfeStatusServico` 4.00
- `NFeAutorizacao` 4.00
- `NfeConsultaProtocolo` 4.00
- `NfeInutilizacao` 4.00
- `RecepcaoEvento` 4.00 para cancelamento

O Portal também publica `NFeRetAutorizacao` 4.00, mas a NT 2023.002 eliminou o lote para NFC-e. Portanto ele não compõe o fluxo normal da NFC-e 2026 e não deve justificar arquitetura assíncrona baseada em recibo.

## Estados NFC-e

```text
draft
  -> generated
  -> validated
  -> signed
  -> pending
       -> authorized -> cancelled
       -> rejected -> generated (após correção)
       -> error

signed -> contingency -> pending -> authorized/rejected/error
```

`denied` não faz parte da máquina de estados nova da NFC-e porque a denegação foi eliminada pela NT 2023.002.

`pending` significa que houve tentativa de transmissão e o estado fiscal ainda precisa ser determinado. Nessas situações, consultar a chave antes de qualquer reenvio.

## Banco — fundação implementada

- `fiscal_companies`: identidade/configuração fiscal por empresa e ambiente.
- `fiscal_certificates`: PFX e senha criptografados pelo Laravel; nunca em `public/`.
- `fiscal_sequences`: numeração por empresa + modelo + série + ambiente, reservada com transação e `lockForUpdate`.

## Banco — previsto para as próximas fases

- `fiscal_documents`: venda, modelo, série, número, chave, ambiente, tpEmis, estado, cStat/xMotivo, protocolo, XMLs e datas.
- `fiscal_events`: cancelamentos e demais eventos vinculados.
- `fiscal_transmissions`: tentativas, serviço, tempos, hashes, cStat/xMotivo e payloads sem segredos.
- `fiscal_tax_rule_versions`: versão e vigência das regras tributárias/tabelas oficiais.

## PDV e estoque

O `SalesService` atual conclui a venda e baixa estoque antes de existir autorização fiscal. Não alterar isso na Fundação.

Antes de ligar a emissão ao PDV, criar fluxo comercial próprio:
1. venda fica `pending_fiscal`;
2. estoque é reservado;
3. NFC-e é gerada/validada/assinada/transmitida;
4. autorizada: confirma venda, converte reserva em saída e libera impressão;
5. rejeitada: mantém operação corrigível por prazo controlado ou libera a reserva no cancelamento.

Isso evita tanto vender sem autorização quanto segurar estoque indefinidamente e impede duplicidade em tentativas de reenvio.

## Fluxo completo de homologação definido

```text
Configurar empresa (ambiente=2)
  -> validar identidade fiscal da empresa
  -> importar/validar certificado A1
  -> configurar série + sequência
  -> configurar CSC/ID Token de homologação (fase QR Code)
  -> statusServico()
  -> reservar nNF com lock transacional
  -> montar snapshot fiscal imutável da venda
  -> gerar chave de acesso
  -> gerar XML 4.00 com regras vigentes/IBS/CBS
  -> validar no XSD oficial versionado
  -> assinar XML (XMLDSig)
  -> gerar QR Code versão aplicável
  -> transmitir para NFeAutorizacao 4.00
  -> interpretar cStat/xMotivo
       -> autorizado: persistir XML + protocolo (nfeProc)
       -> rejeitado: persistir rejeição e permitir correção controlada
       -> resposta indeterminada/timeout após envio: marcar pending e consultar a chave antes de reenviar
  -> gerar DANFE NFC-e 80 mm em HOMOLOGAÇÃO
  -> somente após testes repetíveis habilitar caminho de produção
```

A contingência offline fica deliberadamente fora do primeiro caminho de homologação. Ela será habilitada somente depois de a emissão normal, consulta por chave e persistência do protocolo estarem estáveis.

## Ordem de implementação após a Fundação

1. Importador/manifesto dos XSD oficiais e validador.
2. Snapshot fiscal + camada `Tax` versionada para Simples Nacional, incluindo IBS/CBS conforme vigência.
3. Gerador XML NFC-e 4.00.
4. XMLDSig com A1 e testes de assinatura.
5. Catálogo de autorizadores/endpoints + SOAP + `statusServico()`.
6. Autorização NFC-e síncrona, parser de retornos e consulta por chave.
7. CSC/QR Code e DANFE NFC-e oficial de homologação.
8. Cancelamento e inutilização.
9. Contingência offline.
10. Integração transacional com PDV/estoque e, por último, produção.


---

## Reauditoria documental — 01/10/2026

Esta fase revalidou a documentação antes de implementar documento, snapshot, XML e XSD.

| Documento oficial | Versão/data auditada | Impacto no Nextor Fiscal |
|---|---|---|
| MOC NF-e/NFC-e + Anexo I | MOC 7.00, leiaute 4.00 | Base estrutural dos grupos `ide`, `emit`, `dest`, `det`, `total`, `transp` e `pag`. |
| Portal Nacional — Schemas XML NF-e/NFC-e | PL_010f_v1.04, publicado em 31/08/2026 | Substitui o `010e_v1.02` registrado na Fundação como pacote ativo. Os XSDs passam a ser resolvidos por manifesto e SHA-256. |
| NT 2025.002 RTC | v1.52, publicada em 01/10/2026 | Mantém a RTC/IBS/CBS como requisito de domínio. Como é posterior ao pacote 010f, não alteramos XSD manualmente. |
| NT 2026.002 RTC | v1.11, publicada em 01/10/2026 | Alterações relacionadas às operações presenciais/não presenciais e DANFE Simplificado Tipo 2; rastreada sem antecipar regras fora do pacote XSD vigente. |
| NT 2026.007 | v1.10, publicada em 01/10/2026 | Regras/cadastros de contribuintes; rastreada separadamente das validações puramente XSD. |
| NT 2026.008 | v1.00, publicada em 01/10/2026 | Anuncia campos de valor líquido do produto. Eles não são emitidos enquanto não constarem do pacote XSD oficial ativo. |
| NT 2026.004 | v1.01, 08/06/2026 | Mantém suporte ao CNPJ alfanumérico e formação de chave já preparado na Fundação. |
| DOC-ICP-04 / ICP-Brasil | revalidado em 01/10/2026 | Extração do documento do titular A1 passa a priorizar `subjectAltName/otherName`: OID 2.16.76.1.3.3 (CNPJ PJ) e OID 2.16.76.1.3.1 (dados PF/CPF). |

Fontes oficiais consultadas:
- Portal Nacional NF-e — schemas: https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=BMPFMBoln3w%3D
- Portal SVRS NF-e — documentos e Notas Técnicas: https://dfe-portal.svrs.rs.gov.br/Nfe/Documentos
- ICP-Brasil / ITI — DOC-ICP-04: https://www.gov.br/iti/pt-br/assuntos/legislacao/resolucoes/resolucoes-old/resolucao179_doc-icp-04.htm

### Mudança explícita de baseline XSD

A Fundação, em 01/10/2026, registrou `010e_v1.02` porque era a referência anteriormente auditada. A reauditoria desta fase encontrou no Portal Nacional o pacote posterior **PL_010f_v1.04**, publicado em **31/08/2026**. O Nextor passa a considerar `PL_010f_v1.04` o pacote ativo.

Nenhuma Nota Técnica publicada depois de 31/08/2026 é convertida automaticamente em alteração de XSD. Enquanto um novo pacote oficial não for publicado/importado, o 010f permanece íntegro e as NTs posteriores são tratadas no catálogo documental/de regras.

## Fase Documento + Schema + XML

Fluxo implementado nesta etapa:

```text
dados fiscais já resolvidos
        |
        v
NfceSnapshot (imutável / sem float)
        |
        v
FiscalDocumentCreator
        |
        +-- reserva nNF com lock
        +-- gera cNF
        +-- gera chave
        +-- grava snapshot + SHA-256
        v
fiscal_documents
        |
        v
NfceXmlGenerator (DOMDocument)
        |
        v
XML NFC-e 4.00 NÃO ASSINADO
        |
        v
SchemaRegistry
  -> SchemaResolver
  -> verifica manifesto + SHA-256
  -> SchemaValidator/libxml
        |
        v
SchemaValidationResult { valid, errors[] }
```

### Snapshot fiscal imutável

O snapshot não decide tributação. Ele recebe valores fiscais **já resolvidos** por uma camada de domínio futura/externa ao gerador, incluindo CFOP, grupos ICMS, PIS, COFINS, IBS/CBS e demais valores quando aplicáveis.

Regras desta fase:
- nenhum `float` é aceito no snapshot fiscal;
- valores decimais devem chegar como strings;
- o emitente é copiado de `fiscal_companies`;
- data/hora com offset é preservada no snapshot;
- o JSON armazenado recebe SHA-256;
- identidade do documento, vínculo, chave, numeração, ambiente e snapshot não podem ser alterados depois da criação;
- o gerador XML apenas serializa os grupos resolvidos em DOM;
- XSD valida estrutura, não substitui regras tributárias/validações SEFAZ.

### Persistência

`fiscal_documents` foi criada separada de `sales` com:
- empresa e venda opcional;
- modelo, série, número e ambiente;
- chave, cNF e tpEmis;
- estado e versão do leiaute;
- snapshot + hash;
- XML gerado/assinado/protocolado separados;
- cStat/xMotivo/protocolo e datas reservados para fases posteriores;
- unicidade da chave;
- unicidade empresa + modelo + série + número + ambiente.

`Sale` recebeu apenas a relação `fiscalDocuments()`. O `SalesService` e o PDV permanecem inalterados.

### Limite oficial: XSD e assinatura

O tipo oficial `TNFe` do pacote `PL_010f_v1.04` exige `ds:Signature` após `infNFe`/informações suplementares. Portanto, **um XML NFe/NFC-e realmente não assinado não pode retornar `valid=true` no `schemaValidate()` do XSD oficial**.

Como esta fase proíbe implementar assinatura, o Nextor:
1. gera o XML 4.00 sem assinatura;
2. executa o validador oficial normalmente;
3. retorna erro estruturado informando a ausência de `Signature`;
4. não adultera XSD;
5. não cria assinatura fictícia;
6. não promove o documento para `validated` artificialmente.

A validação integral `valid=true` do elemento `NFe` será atingida na fase XMLDSig, usando o mesmo pacote XSD e o mesmo validador já implementados aqui.

### Responsabilidades de classes

```text
app/Fiscal/
├── Certificate/
│   ├── A1CertificateReader
│   ├── Asn1DerReader
│   └── IcpBrasilSubjectDocumentExtractor
├── DTO/
│   └── NfceSnapshot
├── Models/
│   └── FiscalDocument
├── Nfce/
│   └── FiscalDocumentCreator
├── Schema/
│   ├── SchemaManifest
│   ├── SchemaRegistry
│   ├── SchemaResolver
│   ├── ResolvedSchema
│   ├── SchemaValidationError
│   ├── SchemaValidationResult
│   └── SchemaValidator
└── Xml/
    ├── NfceXmlGenerator
    └── NfceXmlService
```
