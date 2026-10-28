# Benefícios fiscais — Norte, Nordeste e parte do Centro-Oeste

Pesquisa realizada em **26/10/2026**, para AC, AL, AP, AM, CE, MA, MT, MS,
PA, PB, PE, PI, RN, RO, RR, SE e TO. Bahia, Sul, Sudeste, GO e DF têm
levantamento separado. Este documento registra códigos e limites das fontes;
não reconhece direito de uma empresa a incentivo fiscal.

## Referências efetivamente obtidas

| UF | Fonte oficial | Conteúdo incorporável | Aplicação automática |
| --- | --- | --- | --- |
| RN | [DOE/RN de 23/09/2025, nº 16.001, página 28](https://webdisk.diariooficial.rn.gov.br/Jornal/12025-09-23.pdf), Portaria-SEI 970/2025, arts. 1º e 2º e Anexo Único | Quatro códigos expressos: RN010001 e RN020001 a RN020003. Início informado: 01/10/2025; término em branco. Descrição e dispositivo legal preservados. | A tabela não informa CST, CSOSN ou CRT. Correspondência e enquadramento não são inferidos. A tabela original não comprova ausência de alterações posteriores. |
| AC | [SEFAZ/AC — Decreto 11.045/2022](https://sefaz.ac.gov.br/2021/?p=16727), arts. 2º, 5º e 6º, alterado pelo Decreto 11.119/2022 | Código **AC30002**, literalmente como publicado, e CST **20**, explicitamente indicado no art. 5º. Operações interestaduais com gado bovino, sob condições específicas. | Registro de modelo bloqueado. O código publicado tem sete caracteres; não foi completado com zeros. A duração depende dos Convênios ICMS 126/2013 e 19/2022, cuja cadeia de prorrogações e compatibilidade do código com o leiaute precisam de confirmação. |

Os percentuais presentes nas descrições legais do RN foram preservados como
texto da fonte. **Nenhum percentual foi convertido em alíquota ou parâmetro
de cálculo automático.** Os códigos de crédito presumido não dispensam o
cumprimento das condições das normas, credenciamentos e controles próprios.

No AC, a publicação do Decreto 11.045/2022 ocorreu em 28/04/2022. Essa data
identifica o início documental informado no art. 6º, sem comprovar uma data
final do benefício. A própria página contém redações antigas e atuais e
avisa que o texto não substitui o DOE. Por isso, o registro permanece com
`status=template`, `automatic_application=false`, `layout_code_verified=false`
e `full_validity_verified=false`.

## Cobertura das 17 UFs pesquisadas

| UF | Registros | Situação da pesquisa |
| --- | ---: | --- |
| AC | 1 modelo bloqueado | Código expresso em decreto; tabela geral e vigência integral não comprovadas. |
| AL | 0 | Tabela oficial pública de cBenef não confirmada. |
| AP | 0 | Tabela oficial pública de cBenef não confirmada. |
| AM | 0 | Tabela oficial pública de cBenef não confirmada. |
| CE | 0 | Tabela oficial pública de cBenef não confirmada. |
| MA | 0 | Tabela oficial pública de cBenef não confirmada. |
| MT | 0 | Tabela atual não confirmada. Documento oficial de 2019 sobre implantação de validações não comprova situação em 2026. |
| MS | 0 | Tabela oficial pública de cBenef não confirmada. Materiais de EFD encontrados não foram confundidos com cBenef. |
| PA | 0 | Tabela oficial pública de cBenef não confirmada. |
| PB | 0 | Tabela oficial pública de cBenef não confirmada. Códigos de ajustes da EFD não foram convertidos em cBenef. |
| PE | 0 | Tabela oficial pública de cBenef não confirmada. |
| PI | 0 | Tabela oficial pública de cBenef não confirmada. |
| RN | 4 referências | Anexo original da Portaria-SEI 970/2025, sem tabela de correspondência CST/CSOSN. |
| RO | 0 | Tabela oficial pública de cBenef não confirmada. |
| RR | 0 | Tabela oficial pública de cBenef não confirmada. |
| SE | 0 | Tabela oficial pública de cBenef não confirmada. |
| TO | 0 | Tabela oficial pública de cBenef não confirmada. |

**Ausência de tabela obtida não significa dispensa de cBenef, inexistência de
benefício, isenção de imposto nem autorização para emitir com tratamento
presumido.** As quinze UFs sem dados têm cobertura `unavailable`, com
`required_status=unverified`, e não `not_required`.

Foram pesquisados os termos `cBenef` e código de benefício fiscal em domínios
oficiais de cada UF. Listas privadas de estados que adotam o campo não foram
usadas como fundamento de dispensa. A publicação original do RN demonstra
que listas genéricas limitadas a oito estados podem omitir normas estaduais.

Para MT foi consultado o [quadro oficial de validações da NT 2019.001,
atualizado em 30/08/2019](https://sefaz.mt.gov.br/portal/nfe/arqDownloads/regras_facultativa_nt_2019-001_atualizacao_30-08-19.pdf).
Ele informa então uma futura publicação de tabela; não estabelece uma
dispensa atual. Para PB foram encontrados códigos de escrituração EFD, que
têm finalidade distinta e não foram incorporados como cBenef.

## Dados e reprodução da extração

O conjunto intermediário é gerado em
`/workspace/scratch/national-benefits-regions.json` para agregação ao manifesto
versionado do catálogo. Contém `sources`, `entries`, `coverage.by_uf` e
`research_attempts`. A rotina
`scripts/fiscal/normalize_regional_benefits.py` verifica os hashes dos arquivos
efetivamente revisados e a presença dos códigos no PDF oficial antes de
gerar os registros. Se o arquivo consultado mudar, exige nova revisão, sem
reaproveitar silenciosamente a transcrição antiga.

| Fonte | SHA-256 do arquivo obtido |
| --- | --- |
| DOE/RN 23/09/2025 | `f2d13d50e1464f25309c7b5dad3f546c871c2f9758caf742aad79b8658ccb613` |
| Página SEFAZ/AC | `e381476b380138154b3d3748b6c7c7dc448ec5444e09f30e9fc71611a6dc143a` |

Uso pelo mantenedor, com arquivos oficiais previamente obtidos:

```bash
python3 scripts/fiscal/normalize_regional_benefits.py \
  --rn-pdf /caminho/12025-09-23.pdf \
  --ac-html /caminho/ac-decreto-11045.html \
  --output /caminho/beneficios-regioes.json
```

A extração de manutenção usa Python padrão e `pdftotext`; essas ferramentas
**não são dependências da instalação do cliente**, nem integram o fluxo de
emissão. O ERP consome o catálogo JSON distribuído na versão aprovada.
Não foram executadas emissão, assinatura, autorização ou homologação SEFAZ
para os benefícios deste recorte.
