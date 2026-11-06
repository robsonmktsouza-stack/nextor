# Catálogo de retornos SEFAZ — 26/10/2026

O arquivo `resources/fiscal/rejections/2026.10.26.json` reúne **1.025 códigos
cStat distintos**, com a redação publicada, fontes oficiais, versões e referências
às tabelas ou às páginas/regras de validação. Desses registros, 999 têm redação de
rejeição, 24 descrevem outros resultados e dois mantêm redação de uso denegado.
Isso é um catálogo para interpretar retornos já recebidos: não altera requisitos
tributários, não habilita operações e não comprova autorização de documentos.

## Fontes e rastreabilidade

- [MOC 7.00, Anexo I, novembro/2020](https://dfe-portal.svrs.rs.gov.br/NFE/DownloadArquivoEstatico/?sistema=NFE&tipoArquivo=1&nomeArquivo=moc7-anexo-i-leiaute-e-rv.pdf): tabelas 4.4.1, 4.4.2 e 4.4.3, páginas 143–153; importação integral dos resultados de processamento, rejeições e denegações.
- [Portal oficial de documentos NF-e da SVRS](https://dfe-portal.svrs.rs.gov.br/NFe/Documentos): notas técnicas de 2021 a 2026 publicadas na listagem consultada; 27 PDFs oficiais obtidos, dos quais 24 contêm mensagens cStat na extração. URLs individuais, tamanho e SHA-256 dos PDFs estão no manifesto `resources/fiscal/rejections/nt-sources.json`.
- NT 2023.001 v1.51, tributação monofásica de combustíveis; e NT 2024.001 v1.00, CRT=4 e denegação, possuem também as tabelas finais de mensagens importadas explicitamente.
- NT 2025.002 v1.52, reforma tributária; NT 2026.002 v1.11, autorização com alerta; NT 2026.006 v1.00, vinculação de pagamentos; NT 2026.007 v1.10, emitente sem IE; NT 2026.008 v1.00, valor líquido; NT 2026.009 v1.00, CFOP: incluídas as novas redações e códigos encontrados nas fontes oficiais.

Consulta em **26/10/2026**. Cada registro possui `source_ids` e `references`;
as fontes trazem `url`, `version` e `sha256`. Textos anteriores ficam em `variants`
quando uma NT posterior altera uma redação. Um mesmo código pode aparecer em
contextos distintos: a resposta efetivamente recebida e seu contexto têm
precedência sobre a redação de referência do catálogo.

Os oito códigos 9491–9498 da NT 2022.001 pertencem ao serviço de consulta GTIN;
não representam autorização de NF-e. Os códigos de eventos, consulta,
inutilização e processamento de lote também não devem ser interpretados como
autorização individual. O novo **120**, descrito na NT 2026.002, significa
“Autorizado o uso da NF-e, com alerta”; sua presença no catálogo não amplia os
critérios de autorização implementados no motor fiscal.

## Reprodução para manutenção

Ferramentas Python e `pdftotext` são utilizadas somente pelo desenvolvedor para
manutenção dos dados; o ERP instalado consome o JSON sem esses programas.

1. Obter os PDFs nos URLs oficiais do manifesto, preservando os respectivos
   nomes `filename`, em um diretório temporário de fontes.
2. Nesse diretório, manter também o MOC e as duas tabelas-base com os nomes
   `nfe-moc7-official.pdf`, `fiscal-nt2023.001v1.51.pdf` e
   `fiscal-nt2024.001.pdf`; seus URLs e SHA-256 constam nas três primeiras fontes
   do catálogo. Extrair cada um com `pdftotext -layout arquivo.pdf arquivo.txt`.
3. Executar na raiz do projeto:

```bash
python3 tools/fiscal/build_sefaz_rejections.py \
  --source-dir /caminho/temporario/fontes \
  --nt-manifest resources/fiscal/rejections/nt-sources.json \
  --output resources/fiscal/rejections/2026.10.26.json
python3 -m unittest discover -s tools/fiscal -p 'test_*sefaz*.py'
```

O extrator das NTs verifica o SHA-256 do PDF antes de executar `pdftotext`, isola
a coluna da mensagem para evitar misturar as condições da regra com a descrição
e preserva referências de página/regra. Uma atualização exige nova consulta e
revisão; não é um sincronismo silencioso com fontes externas em produção.

## Limites

O catálogo cobre os códigos obtidos nas fontes acima; não inventa significado
para lacunas numéricas nem afirma conhecer futuras publicações, retornos
privativos de UFs, outros modelos DF-e ou todo erro nativo da ACBr. Códigos
ausentes precisam de apresentação segura do retorno original e orientação de
conferência. As datas de implantação de cada regra e as diferenças entre
homologação/produção continuam sendo as definidas nas NTs e comunicados
oficiais: incluir uma mensagem no catálogo não coloca sua regra em vigência.

Não foi realizada nova transmissão à SEFAZ para produzir esses códigos. Os
testes de interpretação utilizam retornos sintéticos e não equivalem à
homologação de emissão ou de correção para cada rejeição.
