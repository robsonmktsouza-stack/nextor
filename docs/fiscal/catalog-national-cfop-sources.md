# CFOP nacional completo — consulta de 26/10/2026

A ampliação nacional usa a **planilha vigente do Portal Nacional da NF-e**, e
não a tabela histórica parcial da SEFAZ/PE utilizada no primeiro pacote.
O conteúdo do pacote `2026.10.26` anterior permanece imutável.

| Informação | Evidência |
| --- | --- |
| Página de publicação | [Documentos → Diversos → Vigentes](https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=/NJarYc9nus=) |
| Arquivo oficial | [Tabela de CFOP — publicada em 04/09/2026](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=74KmX8poGpM=) |
| Nome informado no download | `IT_2023_002_v2_10_Tabela_CFOP_Descrição.xlsx` |
| Tamanho baixado | 65.625 bytes |
| SHA-256 do XLSX | `577e05eec452294945d0e9df1f9bb9b21a4af115938e75ec74cf6a541ae4dacf` |
| Modificação interna declarada no arquivo | 24/09/2026, 17:21:48 UTC |
| Consulta | 26/10/2026 |
| Registros | **619 CFOP distintos**, todos com título, nota explicativa e início de vigência |
| Abrangência | Brasil; entradas e saídas internas, interestaduais e do exterior |
| Indicador de NF-e | **585** com `indNFe=1`; **34** com `indNFe=0` |

A publicação apresenta o arquivo como vigente. A versão `v2_10` pertence ao
nome da planilha efetivamente obtida; não foi atribuída a um PDF de versão
diferente. A data de publicação da página e a data de modificação interna
foram registradas separadamente, sem substituir uma pela outra.

## Conteúdo e preservação

O extrator conserva as 14 colunas oficiais:

- CFOP, início e fim de vigência;
- `indNFe`, `indComunica`, `indTransp`, `indDevol`, `indRetor`, `indAnula`,
  `indRemes`, `indComb` e `indExcIBSCBS`;
- título e descrição/nota explicativa.

Os nove indicadores permanecem com seus nomes e valores oficiais em
`parameters`. `indNFe` também é normalizado em `parameters.ind_nfe` booleano
para a verificação do emissor. Ausência ou valor inválido gera erro de extração;
não se converte informação desconhecida em `false`. **`indComb` possui valores
0, 1 e 2 e permanece inteiro. A planilha não contém uma coluna `indNFCe`; esse
indicador não foi criado.** As validações próprias da NFC-e continuam necessárias.

As datas Excel foram convertidas pelo calendário 1900 declarado no arquivo.
Há 560 inícios em 01/01/2006 e outros 59 entre 2018 e 2021; todos os fins estão
em branco nesta fotografia. O extrator suporta término exclusivo, conforme a
definição da tabela no informe técnico, convertendo-o para o último dia aceito
no campo inclusivo `valid_until` e preservando o original. Não deduz uma data
de vigência a partir da data de publicação da planilha.

A completude significa **todas as linhas da planilha vigente consultada**. Não
significa que ela contenha todas as redações e códigos revogados de versões
anteriores, nem que os indicadores atuais sejam válidos retroativamente.

## Indicador do contribuinte exclusivo do IBS/CBS

O [IT 2023.002 versão 2.00](https://dfe-portal.svrs.rs.gov.br/NFE/DownloadArquivoEstatico/?sistema=NFE&tipoArquivo=16&nomeArquivo=IT_2023.002_v2.00_Tabela_CFOP_indExcIBSCBS.pdf)
foi consultado para a definição do indicador `indExcIBSCBS` e seu cronograma:
01/09/2026 em homologação e **03/11/2026 em produção**. Antes dessa implantação,
o indicador não deve ser usado para antecipar rejeições em produção.

O texto do IT 2.00 menciona 84 códigos, enquanto a planilha vigente v2.10
baixada contém **72 registros com `indExcIBSCBS=1`**. O catálogo preserva as
linhas efetivamente publicadas na planilha consultada e registra sua fonte e
hash; não força a contagem do documento anterior sobre o arquivo mais recente.

## Extração reproduzível

O Portal usa detecção de cookies ASP.NET. Requisições sem conservar o cookie
público podem entrar em redirecionamento repetido. Conservar um cookie jar
público resolve o acesso sem autenticação ou certificado fiscal:

```bash
curl --fail --location --cookie-jar portal-public-cookies.txt \
  --cookie portal-public-cookies.txt \
  'https://www.nfe.fazenda.gov.br/portal/principal.aspx' -o portal-home.html
curl --fail --location --cookie-jar portal-public-cookies.txt \
  --cookie portal-public-cookies.txt \
  'https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=74KmX8poGpM=' \
  -o cfop-oficial.xlsx
python3 tools/fiscal/parse_official_cfop.py cfop-oficial.xlsx cfop-normalizado.json \
  --consulted-at=2026-10-26 --expected-count=619
```

O script utiliza somente a biblioteca padrão do Python, lê as partes conhecidas
do XLSX sem executar macros e falha diante de fórmulas, colunas preenchidas
inesperadas, indicadores desconhecidos, códigos duplicados, descrições vazias,
datas inconsistentes ou mudança de cabeçalhos. Células que possuem apenas
formatação fora da tabela são ignoradas. A saída é um fragmento de catálogo
para revisão e composição de uma **nova versão**, sem gerar regras ativas ou
alterar personalizações de uma instalação.

## Limite de aplicação

A existência do CFOP e seu indicador técnico não determina CRT, CST/CSOSN,
origem da mercadoria, benefício, incidência, alíquota, ST, DIFAL ou suporte do
fluxo operacional pelo Lumeron. O motor continua exigindo a regra fiscal
fundamentada e os campos específicos da operação. CFOP com `indNFe=0` não
pode ser habilitado para uma determinação automática de NF-e por um grupo
tributário ou nome de natureza.
