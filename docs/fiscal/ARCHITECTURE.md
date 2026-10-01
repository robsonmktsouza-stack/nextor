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
