# Classificação nacional CEST — catálogo 2026.10.26.1

Consulta realizada em **26/10/2026**, a partir do [texto consolidado oficial do Convênio ICMS 142/2018](https://www.confaz.fazenda.gov.br/legislacao/convenios/2018/CV142_18), já com remissão ao [Convênio ICMS 107/2026](https://www.confaz.fazenda.gov.br/legislacao/convenios/2026/CV107_26).

A classificação nacional abrange todas as 27 UFs. Isso **não estabelece incidência de ICMS-ST**, MVA, alíquotas, responsabilidade por retenção, benefícios ou regras automáticas de uma UF. Esses parâmetros continuam dependentes da legislação e dos acordos específicos da operação.

## Conteúdo verificado

| Medida | Resultado |
| --- | ---: |
| Anexos de mercadorias integralmente normalizados | 25 — II a XXVI |
| Itens da consolidação, incluindo revogados e futuros | 1.054 |
| Códigos CEST distintos, incluindo revogados e futuros | 1.054 |
| Códigos nacionais vigentes na consulta, sem contar duplicações estaduais | 1.041 |
| Códigos revogados preservados para auditoria | 10 |
| Redações futuras, com efeitos em 01/11/2026 | 5 |
| Redações estaduais específicas por exclusão expressa de SP | 2 |
| Registros com escopo e vigência distintos | 1.058 |
| Expressões NCM sem vínculo automático seguro | 4 |

O Anexo I fornece os nomes e códigos dos segmentos. Não foram criados códigos CEST a partir desses nomes. Os Anexos XXVII a XXIX tratam de condições e procedimentos relativos à escala industrial não relevante e não são classificados como novos códigos de mercadorias. O texto oficial completo desses anexos permanece no arquivo de fonte; sua presença não implementa automaticamente a exceção tributária.

## Fundamentos e condições

| Fonte oficial | Dispositivo | Utilização e limite |
| --- | --- | --- |
| Convênio ICMS 142/2018, texto consolidado consultado | Cláusula segunda | A adoção de ST interestadual depende de acordo específico; a classificação nacional não substitui esse acordo. |
| Mesmo convênio | Cláusulas terceira e quarta | Aplicação aos contribuintes optantes ou não do Simples, observadas as normas da UF de destino; nenhuma alíquota é deduzida. |
| Mesmo convênio | Cláusula sexta e Anexo I | Estrutura de sete dígitos do CEST e identificação dos segmentos. |
| Mesmo convênio | Cláusula sétima e Anexos II a XXVI | Classificação conjunta por NCM, descrição e segmento; reclassificações de NCM não são interpretadas como alteração automática de CEST. |
| Mesmo convênio | Anotações de alteração ou revogação de cada item | Datas registradas somente quando expressamente demonstradas na anotação do item. Datas iniciais não demonstradas permanecem `null`. |
| Convênio ICMS 107/2026 | Cláusulas primeira e segunda | Nova redação dos itens 46.0 e 47.0 do Anexo XX e inclusão dos itens 46.1, 47.1 e 51.1. |
| Convênio ICMS 107/2026 | Cláusula terceira | Exclusão expressa de São Paulo de todo o disposto nesse convênio. |
| Convênio ICMS 107/2026 | Cláusula quarta e publicação no DOU de 09/09/2026 | Efeitos a partir do primeiro dia do segundo mês subsequente: 01/11/2026. |

Os códigos 21.046.00 e 21.047.00 preservam redação nacional anterior até 31/10/2026 e redação específica de SP sem a alteração do Convênio 107/2026. As cinco redações nacionais futuras registram `excluded_ufs: ["SP"]`; as três inclusões não são disponibilizadas antes de 01/11/2026 nem para SP. Nenhuma dessas condições presume incidência de ST paulista ou das demais UFs.

Foram preservadas as descrições históricas e suas anotações normativas. Elas são evidências de auditoria, e não versões adicionais automaticamente aplicáveis a períodos sem vigência demonstrada. Códigos revogados não são retornados como referências ativas.

## Vínculos NCM

O parser conserva a expressão NCM publicada e gera apenas os prefixos explicitamente enumerados, incluindo capítulos e intervalos de capítulos expressamente indicados. Não corrige códigos por aproximação e não classifica o produto apenas por semelhança de descrição.

| CEST | Motivo da revisão manual |
| --- | --- |
| 01.999.00 | Expressão NCM não informada na linha publicada. |
| 17.116.00 | Expressão extraída `008.13 009.09`, cuja normalização para código NCM exigiria correção não comprovada. |
| 20.040.00 | Expressão extraída inclui `926.90.90`; nenhum dígito foi presumido ou acrescentado. |
| 28.999.00 | Expressão NCM não informada na linha publicada. |

Essas linhas conservam a classificação e o texto de origem, com `ncm_link_validation: manual` e sem prefixos que possam produzir determinação automática insegura.

## Evidência e reprodução

O acesso HTTPS direto pelo ambiente retornou HTTP 503. A ferramenta de navegação conectada conseguiu consultar a página oficial e o vínculo oficial para o Convênio 107/2026. Foram capturadas **todas as linhas numeradas de 0 a 3.004** da consolidação, sem preencher lacunas com fonte privada.

Fontes públicas arquivadas:

- `resources/fiscal/sources/confaz-cest-2026-10-26.txt` — extração textual integral da consolidação oficial.
- `resources/fiscal/sources/confaz-cest-107-2026-10-26.txt` — consulta oficial da alteração e de suas condições.

Os hashes SHA-256 no catálogo correspondem à representação textual arquivada, não ao HTML original. A descrição da representação acompanha a fonte. Nenhuma credencial, XML ou informação de cliente foi usada.

Reprodução pelo mantenedor, sem necessidade de instalar Python no computador do cliente:

```bash
python3 tools/fiscal/parse_official_cest.py \
  --input resources/fiscal/sources/confaz-cest-2026-10-26.txt \
  --amendment resources/fiscal/sources/confaz-cest-107-2026-10-26.txt \
  --output /tmp/national-cest.json
python3 -m unittest tools/fiscal/test_parse_official_cest.py -v
```

O parser recusa fonte incompleta, mudança na consolidação esperada, contagem divergente, revogação sem data comprovada, alteração futura sem tratamento expresso de escopo ou identidade fiscal duplicada. Uma nova consolidação precisa de pesquisa e revisão antes de substituir esse arquivo de origem.

Validação executada: **7 testes Python aprovados**. Cobrem integridade da captura nacional, classificação de datas e escopos, exclusão de SP, revogações, células NCM divididas em múltiplas linhas, intervalos explícitos de capítulos, preservação de expressões inconclusivas e ausência de alíquotas/ST inferidas. Importação Laravel, integração com produtos e regressão do emissor são validadas separadamente pela suíte do catálogo.

## Atualizações

O novo pacote é uma versão independente. O pacote anterior e suas fontes permanecem imutáveis. Um recorte antigo de legislação estadual não deve prevalecer sobre essa classificação nacional por simples precedência de UF; somente uma exceção normativa expressamente comprovada, como a do Convênio 107/2026, justifica a redação específica correspondente.

A atualização não modifica produtos, regras aprovadas, documentos autorizados ou parâmetros tributários particulares da instalação.
