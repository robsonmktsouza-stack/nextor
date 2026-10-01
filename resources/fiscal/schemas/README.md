# Schemas fiscais do Nextor

Esta pasta contém somente schemas oficiais da NF-e/NFC-e preservados de forma versionada. O código nunca deve editar XSD para fazê-lo aceitar um XML do Nextor.

## Baseline ativo em 01/10/2026

A Fundação registrava o pacote `010e_v1.02`. Na reauditoria desta fase, a listagem oficial do Portal Nacional passou a indicar como pacote mais recente publicado:

- **PL_010f_v1.04**
- publicação oficial do pacote: **31/08/2026**
- leiaute: NF-e/NFC-e **4.00**
- notas associadas ao pacote: **NT 2025.002 v1.50** e **NT 2026.007 v1.00**
- ZIP oficial: `https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=8ITFuBLltXs%3D`
- SHA-256 preservado do ZIP: `b8589490a58a09a993a80e6ac4d7ed10f20892061ecfc56719337098d4b95998`

Arquivos do pacote:

| Arquivo | SHA-256 |
|---|---|
| `DFeTiposBasicos_v1.00.xsd` | `173577a4e3a9dc1d0deced85b89b955b6064bb5a4ae5a96f6727d2da8a694d09` |
| `leiauteNFe_v4.00.xsd` | `2bace939973916d54184ff3e2740041a932de5d79772f3363504504160f22542` |
| `nfe_v4.00.xsd` | `adce3646c13ceb54922ec3142fc1dc45bd4fb839ac35ad583e86c733c07d27df` |
| `tiposBasico_v4.00.xsd` | `772619c85723e598840667ca66e7298a250442df47eeb94b397d2a333ce62047` |
| `xmldsig-core-schema_v1.01.xsd` | `f56744a5f51c03f027de13f39f869307091781a9ef1d91b1ebe14719ce28e1ac` |

O `SchemaRegistry` recalcula o SHA-256 de cada XSD em tempo de execução antes de liberar o pacote.

## Atualizações documentais posteriores ao pacote

Em 01/10/2026, a documentação técnica oficial já lista revisões posteriores ao pacote 010f, entre elas NT 2025.002 v1.52, NT 2026.002 v1.11, NT 2026.007 v1.10 e NT 2026.008 v1.00. Enquanto não houver um novo pacote XSD oficial publicado e auditado, essas notas são rastreadas como regras/documentação e **não autorizam alterar manualmente o 010f**.

A NT 2026.008 v1.00 anuncia novos campos para valor líquido do produto. Eles não são inventados no XML do Nextor enquanto não estiverem presentes no pacote XSD oficial utilizado pelo motor.

## Estrutura

```text
resources/fiscal/schemas/
├── manifests/
│   └── nfe-4.00-PL_010f_v1.04.json
└── nfe/
    └── 4.00/
        └── PL_010f_v1.04/
            ├── DFeTiposBasicos_v1.00.xsd
            ├── leiauteNFe_v4.00.xsd
            ├── nfe_v4.00.xsd
            ├── tiposBasico_v4.00.xsd
            └── xmldsig-core-schema_v1.01.xsd
```

## Regra de integridade

1. Não editar XSD oficial.
2. Cada pacote possui manifesto independente.
3. O manifesto registra origem, publicação, download, notas técnicas e SHA-256.
4. Só pode existir um manifesto ativo para o mesmo documento/versão.
5. O gerador XML não conhece caminhos de XSD.
6. A validação sempre passa por `SchemaRegistry -> SchemaResolver -> SchemaValidator`.
7. Uma atualização só se torna ativa depois de publicação oficial, importação, hash e testes.

## Limite da fase sem assinatura

No schema oficial `TNFe`, o elemento XMLDSig `Signature` é obrigatório. Por isso um XML `NFe` realmente sem assinatura — como o gerado nesta fase — não pode ser classificado artificialmente como XSD válido. O validador deve devolver o erro oficial. A validação integral do `NFe` ocorrerá na fase de assinatura, sem alterar o XSD nem inserir assinatura fictícia.
