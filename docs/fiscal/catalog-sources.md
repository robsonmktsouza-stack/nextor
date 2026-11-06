# Fontes e cobertura do catálogo fiscal 2026.10.26

O pacote `resources/fiscal/catalog/2026.10.26.json` contém **11.063 referências**.
É uma versão do catálogo do Lumeron, não uma versão de legislação criada pelo
sistema. Os registros conservam a fonte, versão documental quando declarada,
data de consulta, hash SHA-256 do arquivo consultado, escopo, condições e datas
originais que puderam ser comprovadas. Não contém alíquotas gerais presumidas,
MVA, crédito fiscal de empresa nem concessão automática de benefício.

| Domínio | Registros | Fonte oficial e identificação | Cobertura e condição |
| --- | ---: | --- | --- |
| NCM | 10.516 | [Classif/Siscomex — download JSON](https://portalunico.siscomex.gov.br/classif/api/publico/nomenclatura/download/json), `Vigente em 25/10/2026`, Resolução Gecex nº 926/2026 | Todas as folhas de oito dígitos do arquivo baixado em 26/10/2026; cabeçalhos excluídos. Não representa o histórico de códigos revogados. |
| CFOP | 64 | [Tabela da SEFAZ/PE](https://www.sefaz.pe.gov.br/Legislacao/Tributaria/Documents/Legislacao/Tabelas/CFOP.htm), notas explicativas do Anexo II do Convênio SINIEF s/n de 1970 | Recorte das operações usuais. O documento contém metadados de 13/10/2015; a consolidação nacional vigente em 2026 não foi obtida. Datas desconhecidas permanecem vazias; exige revisão antes de habilitar regra. |
| Origem da mercadoria | 9 | [MOC 7.00, Anexo I](https://dfe-portal.svrs.rs.gov.br/NFE/DownloadArquivoEstatico/?sistema=NFE&tipoArquivo=1&nomeArquivo=moc7-anexo-i-leiaute-e-rv.pdf), campo N11 | Domínio técnico, sem inferência da origem de um produto. |
| CRT | 4 | MOC 7.00, C21; [NT 2024.001, versão 1.00](https://dfe-portal.svrs.rs.gov.br/NFE/DownloadArquivoEstatico/?sistema=NFE&tipoArquivo=3&nomeArquivo=NT2024.001-CRT-MEI.pdf), CRT=4 | Domínio técnico; não determina a opção tributária da empresa. |
| CSOSN | 10 | MOC 7.00, grupos N10c a N10h, campo N12a | Códigos de referência do Simples; a escolha depende da operação e enquadramento comprovados. |
| CST ICMS | 15 | MOC 7.00, N12; [NT 2023.001, versão 1.51](https://dfe-portal.svrs.rs.gov.br/NFE/DownloadArquivoEstatico/?sistema=NFE&tipoArquivo=3&nomeArquivo=NT2023.001_v1.51_Tributa%C3%A7%C3%A3oMonof%C3%A1sicaCombust%C3%ADveis.pdf), códigos 02/15/53/61 | Inclusão do código no catálogo não habilita cálculos de combustíveis ainda bloqueados no emissor. |
| CST PIS/COFINS | 33 | MOC 7.00, Q06 e S06 | Domínio técnico de códigos, sem alíquota presumida ou classificação monofásica de mercadorias. |
| CST IPI | 14 | MOC 7.00, O09 | Domínio técnico; enquadramento legal e condição de contribuinte continuam necessários. |
| CST IBS/CBS | 18 | [Tabela oficial SVRS](https://dfe-portal.svrs.rs.gov.br/CFF/ClassificacaoTributaria), consultada em 26/10/2026 | Todos os CST presentes na página. Não se atribui uma versão de planilha ao conteúdo HTML. |
| cClassTrib IBS/CBS | 173 | Mesma página SVRS, com referência à LC 214/2025 por classificação | Todos os códigos da página; indicadores de documentos, reduções e datas preservados. **Anexos NCM/NBS e enquadramento da empresa não incorporados.** |
| CEST e vínculos NCM | 207 | [Portaria CAT 68/2019, art. 1º e anexos I a XXII — SEFAZ/SP](https://legislacao.fazenda.sp.gov.br/Paginas/Portaria-CAT-68-de-2019.aspx), remissão ao Convênio ICMS 142/2018 | Recorte das linhas não marcadas como redação revogada. **Escopo SP, parcial, sem atestado nacional de vigência e sem aplicar ST automaticamente.** Descrição, segmento, exceções e legislação da operação são cumulativos ao NCM. |
| Benefícios/cBenef BA | 0 | Necessária tabela oficial estadual validada | Nenhum código foi criado por aproximação. A estrutura permite importar versões futuras validadas. |

## Fundamento do Simples e da transição IBS/CBS

Também foram obtidos os textos atualizados em fontes legislativas oficiais:

- [LC 123/2006, texto atualizado da Câmara dos Deputados](https://www2.camara.leg.br/legin/fed/leicom/2006/leicomplementar-123-14-dezembro-2006-548099-normaatualizada-pl.html): arts. 13 e 23, tributos abrangidos, exceções e condições dos créditos de ICMS. O texto consultado inclui referências às alterações da LC 227/2026.
- [LC 214/2025, texto atualizado da Câmara dos Deputados](https://www2.camara.leg.br/legin/fed/leicom/2025/leicomplementar-214-16-janeiro-2025-796905-normaatualizada-pl.html): arts. 41, 343, 346, 348 e 544, regime regular, tratamento do Simples/MEI e produção de efeitos dos dispositivos.

O art. 348, III, “c”, exclui as operações dos optantes pelo Simples Nacional
das alíquotas de teste previstas nos arts. 343 e 346 para 2026. O § 1º trata
da dispensa de recolhimento condicionada a obrigações acessórias, o que não
permite eliminar grupos documentais sem verificar as notas técnicas e o
regime. O art. 544 tem datas distintas por dispositivo. Essas condições
reforçam a necessidade de determinar regime e data antes do cálculo.
Não se atribuíram alíquotas, crédito ou desoneração a produtos apenas com base
na leitura desses artigos. Os arquivos e hashes estão registrados em `sources`
do manifesto, mesmo sem gerar regras fiscais ativas.

## Vigência e precisão

Para NCM, `Data_Inicio` e `Data_Fim` foram convertidas de DD/MM/AAAA para
AAAA-MM-DD. O marcador oficial `31/12/9999` permanece em
`parameters.source_end_date`; `valid_until` fica nulo. A completude da fonte
refere-se à fotografia do download, e não a todas as versões históricas da NCM.
Ausência nessa fotografia não prova que um código nunca tenha sido válido.

Nas 2.654 folhas cuja descrição original é apenas “Outros” ou “Outras”, a
interface recebe o caminho dos cabeçalhos oficiais para pesquisa e leitura,
por exemplo `Animais vivos. › Cavalos, asininos e muares, vivos. › Cavalos: — Outros`
para 0101.29.00. `parameters.source_leaf_description` conserva o texto original,
`source_parent_path` conserva os códigos e descrições dos pais e
`description_derived_from_official_hierarchy` identifica a apresentação
derivada. Isso acrescenta contexto de leitura sem atribuir classificação a
produtos ou mudar códigos, vigências e fundamento da fonte.

Para IBS/CBS, o término da fonte é exclusivo. O catálogo armazena em
`valid_until` o dia anterior, para a comparação inclusiva comum aos cadastros,
e preserva o término original em `parameters.source_valid_until`. Reduções
são strings decimais, não números de ponto flutuante. Indicadores, descrições e
pares CST/cClassTrib provêm do `dadosOriginais` da página oficial. Os anexos não
foram incorporados; esses registros não classificam mercadorias pelo NCM.

No recorte CEST/SP, a vigência geral da Portaria CAT 68/2019 é 01/01/2020,
mas suas alterações e exclusões têm datas próprias. Não se transferiu essa data
indiscriminadamente para cada linha: datas individuais permanecem nulas.
A extração exclui as células identificadas como `sefazElement-TextoRevogado`,
`line-through`, `s`, `strike` ou `del`. Prefixos literais da coluna NCM/SH são
preservados; expressões ambíguas exigem revisão. Presença de CEST ou coincidência
de prefixo nunca determina responsabilidade por ST ou tratamento em outra UF.

## Pesquisa técnica e impedimentos observados

O [IT 2023.002 versão 2.00, agosto/2026](https://dfe-portal.svrs.rs.gov.br/NFE/DownloadArquivoEstatico/?sistema=NFE&tipoArquivo=16&nomeArquivo=IT_2023.002_v2.00_Tabela_CFOP_indExcIBSCBS.pdf)
foi consultado. Ele descreve a tabela CFOP e a inclusão de `indExcIBSCBS`, com
homologação em 01/09/2026 e produção em 03/11/2026. O PDF não contém a tabela
de dados completa; não foi usado para inventar os indicadores de cada CFOP.

Nesta consulta, o domínio CONFAZ retornou **HTTP 503** para a consolidação do
Convênio SINIEF s/n de 1970, Ajuste SINIEF 3/2010 e
[Convênio ICMS 142/2018](https://www.confaz.fazenda.gov.br/legislacao/convenios/2018/CV142_18).
Tentativas nos endereços de legislação da SEFAZ/BA retornaram HTTP 403/503.
O endereço Planalto da LC 123/2006 expirou sem resposta; seu texto atualizado foi obtido posteriormente na Câmara, assim como o da LC 214/2025. O link antigo da
Receita para IN RFB 1.009/2010 retornou apenas o aplicativo web de consulta,
sem o texto do ato. Essas tentativas não foram apresentadas como leitura
bem-sucedida de legislação consolidada.

Por isso, este pacote distribui referências efetivamente obtidas em fontes
oficiais e modelos que exigem revisão. **Não distribui uma tabela nacional
completa e atual de CFOP/CEST, legislação consolidada de todos os tributos,
MVA, alíquotas estaduais, DIFAL/FCP ou benefícios da Bahia.** Regras de
tributação utilizam a infraestrutura já existente e devem receber seus
parâmetros e fundamento próprios. Os modelos não são provas de validade
jurídica da configuração de uma empresa.

## Atualização verificável

Uma atualização deve ser preparada em nova versão de manifesto, a partir de
arquivos oficiais verificados. Conferir cobertura declarada, descrições,
condições, vigências e escopo, registrar a consulta e o hash de cada arquivo,
e testar a importação antes de distribuí-la pelo release aprovado do Lumeron.
Não editar silenciosamente o conteúdo de uma versão já instalada. O serviço de
catálogo registra o conteúdo recebido e não altera regras, grupos, produtos ou
documentos autorizados da instalação ao trocar dados de referência.

O catálogo técnico não substitui a legislação de incidência: Simples Nacional,
ICMS/ST/DIFAL/FCP, PIS/COFINS, IPI e IBS/CBS precisam ser analisados nas fontes
legais aplicáveis à empresa, mercadoria e data. Uma fonte inacessível ou
condição não comprovada deve manter a aplicação automática indisponível.
