# NEXTOR — Modelos fiscais pré-configurados (09/10/2026)

## O que a migração instala

Arquivo: database/migrations/2026_10_09_000001_install_fiscal_tax_group_presets.php

Cria modelos prontos em fiscal_tax_groups com:
- preset_key (identificador técnico estável e único);
- target_crt (regime aplicável: 1, 2, 3 ou 4);
- CFOP, CSOSN ou CST de ICMS e CST de PIS/COFINS **já selecionados**;
- notas sobre aplicação e limitações;
- estado ativo/inativo conforme a necessidade de parametrização adicional.

Todos ficam com is_default=false. Nenhum modelo é aplicado a produtos
sem que um usuário responsável o selecione expressamente. O cadastro de
produtos mostra somente modelos do CRT da empresa, além de grupos livres
sem CRT específico. A tradução fiscal também rejeita um modelo de outro CRT.

## Modelos instalados

| Modelo (preset_key) | CRT | CFOP | ICMS | PIS/COFINS | Inicialmente ativo? |
|---|---|---|---|---|---|
| sn_resale_common | 1 | 5102 | CSOSN 102 | 49 / 49 | Sim |
| sn_production_common | 1 | 5101 | CSOSN 102 | 49 / 49 | Sim |
| sn_resale_monophase | 1 | 5102 | CSOSN 102 | 04 / 04 | Sim |
| sn_resale_zero | 1 | 5102 | CSOSN 102 | 06 / 06 | Sim |
| sn_resale_st_retained | 1 | 5405 | CSOSN 500 | 49 / 49 | Sim, mas requer retenção por produto |
| sn_immunity | 1 | 5102 | CSOSN 300 | 49 / 49 | Não |
| sn_unreached | 1 | 5102 | CSOSN 400 | 49 / 49 | Não |
| sn_revenue_band_exemption | 1 | 5102 | CSOSN 103 | 49 / 49 | Não |
| mei_resale_common | 4 | 5102 | CSOSN 102 | 49 / 49 | Sim |
| mei_immunity | 4 | 5102 | CSOSN 300 | 49 / 49 | Não |
| normal_icms_00 | 3 | 5102 | CST 00 | 01 / 01 | Não: configurar alíquotas |
| normal_icms_20 | 3 | 5102 | CST 20 | 01 / 01 | Não: configurar alíquotas e redução |
| excess_icms_00 | 2 | 5102 | CST 00 | 49 / 49 | Não: configurar alíquota e revisar PIS/COFINS |

Os modelos inativos podem ser ajustados e ativados pelo responsável.
O status "ativo" permite selecionar o modelo; **não declara que todas
as vendas de um produto daquele ramo têm essa tributação**.
A possibilidade de emitir permanece condicionada às validações do motor,
à situação fiscal real, aos dados do produto e à autorização da SEFAZ.

### Por que nenhuma alíquota foi presumida?

- ICMS, FCP e demais tributos variam por UF, situação da operação, NCM,
  exceções e datas de vigência.
- Revenda PIS/COFINS monofásica ou com alíquota zero exige enquadramento
  legal do produto; NCM isolado pode ser insuficiente.
- No Simples Nacional, a identificação de receita monofásica/ST também
  afeta segregações de PGDAS-D; a NFC-e não apura essas parcelas sozinha.
- Regime Normal requer conferir tributação cumulativa/não cumulativa
  e as alíquotas aplicáveis; por isso os modelos começam inativos.

### Procedimento do usuário

1. Configurar corretamente o CRT e o emitente em Configurações.
2. No cadastro do produto, selecionar "Grupo tributário" entre os
   modelos do CRT corrente. Os CFOPs/CSTs não precisam ser digitados.
3. Completar apenas dados particulares: NCM, origem, CEST se cabível,
   retenção anterior e unidade/linha em CSOSN 500, ou alíquota do
   grupo normal depois da revisão fiscal.
4. Efetuar venda de teste e conferir XML/ACBr em homologação.
5. Nunca marcar um único grupo como "padrão" para todos os produtos sem
   prévia certeza de homogeneidade do mix tributário.

## Referências públicas oficiais

- Portal Nacional da NF-e, MOC 7.0 (leiaute e validações):
  https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=ndIjl+iEFdE%3D
- Informe Técnico 2023.002 v2.10, divulgado em 04/09/2026,
  atualização da tabela de CFOP:
  https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=hXzemuyNHW4%3D
- Portal Nacional da NF-e, tabelas vigentes: CFOP, NCM/uTrib, cClassTrib,
  cCredPres e outros catálogos:
  https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=%2FNJarYc9nus%3D
- NT 2024.001 — CRT 4 (MEI), regras NFC-e para CFOP/CSOSN:
  https://hom.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=RCv1W2OGY3U%3D
- NT 2025.002 v1.52 publicada em 01/10/2026 (IBS/CBS/IS):
  https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=04BIflQt1aY%3D
- IT 2025.002 v1.70 publicada em 01/10/2026 (cClassTrib/IBS/CBS):
  https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=hXzemuyNHW4%3D
- Receita Federal — guia de PIS/COFINS, setor supermercadista:
  https://www.gov.br/receitafederal/pt-br/assuntos/orientacao-tributaria/restituicao-ressarcimento-reembolso-e-compensacao/conformidade-tributaria/Creditos-Indevidos-de-PIS-Pasep-Cofins-Setor-Supermercadista/

A tabela oficial completa de cClassTrib **não foi importada** nessa
migração. A lista comunitária parcial já existente no NEXTOR não deve
ser usada como fonte normativa definitiva de IBS/CBS.
Tabelas oficiais têm versão e devem ser atualizadas separadamente.

## Implantação

Após o git pull da branch feature/acbr-nfe-bootstrap, esta atualização
**exige executar php artisan migrate**, pois adiciona campos à tabela
fiscal_tax_groups e instala os registros. Nenhum pacote Composer novo
foi adicionado. Em ambiente de cliente, fazer backup antes da migração.

A migração não edita grupos cadastrados anteriormente, não ativa
produção e não altera produtos nem documentos fiscais emitidos.
Ao reverter, os modelos inalterados e não vinculados podem ser removidos;
modelos editados ou vinculados permanecem como grupos normais.


## Atualização: modelos de serviços (09/10/2026)

Uma migração adicional instala **24 grupos nacionais de serviços**:

database/migrations/2026_10_09_000002_install_fiscal_service_presets.php

Os códigos do Anexo Nacional de Serviços são publicados pelo Portal NFS-e:
https://www.gov.br/nfse/pt-br/mei-e-demais-empresas/codigos-de-tributacao-nacional-nbs

| Exemplo | Código de tributação nacional | Item LC 116 |
|---|---|---|
| Contabilidade | 171901 | 17.19 |
| Desenvolvimento de sistemas | 010101 | 01.01 |
| Programação | 010201 | 01.02 |
| Suporte técnico em informática | 010701 | 01.07 |
| Academia e atividades físicas | 060401 | 06.04 |
| Mecânica e manutenção | 140101 | 14.01 |
| Construção civil (empreitada) | 070202 | 07.02 |
| Treinamentos | 080201 | 08.02 |
| Segurança e monitoramento | 110201 | 11.02 |
| Consultoria empresarial | 170101 | 17.01 |

A tabela no código inclui outros 14 serviços. Cada grupo de serviços traz
código nacional de seis dígitos, item da lista de serviços e exigibilidade
de ISS "1" como situação ordinária (alterar se existir hipótese legal
específica). A escolha do grupo preenche os códigos automaticamente no
cadastro de serviço; mudanças posteriores são aplicadas ao preparar o
snapshot da venda/NFS-e, sem depender do operador do caixa.

O grupo não define alíquota municipal universal. LC 116/2003 art. 8º e 8º-A:
limites gerais entre 2% e 5% (com exceções). O percentual exigível de
cada município e o recolhimento no Simples dependem de detalhes que não
podem ser deduzidos somente do código nacional. O cadastro do grupo
permite informar a alíquota com código IBGE do município e referência legal.
A preparação de NFS-e bloqueia a utilização de alíquota configurada para
outro município ou sem origem legal.

Fonte LC 116: https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp116.htm
Layout NFS-e produção:
https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual
Documentação da Reforma Tributária NFS-e (atualizada 02/10/2026):
https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc

Nenhum dos grupos constitui apuração de ISS do Simples, classificação
autônoma por CNAE/NBS, ou autorização NFS-e em produção. **O NEXTOR
ainda não tem transmissor NFS-e próprio integrado ao ambiente nacional**;
o que fica pronto é o cadastro padronizado e a classificação consistente
na preparação da venda. Os códigos de serviços não podem suprir a falta
de credenciamento, municipalização, integração com provedor ou apuração
correta do ISS, IBS e CBS.

A tela do grupo apresenta apenas os campos pertinentes ao tipo
produto/serviço, sem mensagens técnicas no cadastro do usuário.
