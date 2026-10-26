# Catálogo nacional — edição 2026.10.26.1

Implementação na branch `feature/nfe55-motor-fiscal-completo`, mantendo a edição anterior `2026.10.26` integralmente. Não foi executado deploy ou alteração nas configurações de produção.

[Inventário de arquivos e commits](catalog-national-changes-2026-10-26.md).

## Conteúdo e alcance

A edição contém **15.986 registros**, com fontes oficiais, hashes, condições e vigências documentadas. CFOP e CEST são classificações nacionais; isso não transforma todos os códigos em operações suportadas pelo emissor nem em incidência automática de tributos.

| Domínio | Cobertura desta edição |
| --- | --- |
| CFOP | **619 códigos** da planilha vigente oficial do Portal NF-e, publicada em 04/09/2026; 585 permitem NF-e e 34 têm `indNFe=0` |
| CEST | **1.054 códigos distintos** nos 25 anexos de mercadorias do Convênio ICMS 142/2018 consolidado; 1.041 códigos nacionais atuais na data da consulta, 10 revogados e 3 inclusões futuras |
| Redações CEST | 1.058 registros nacionais/estaduais por período; alterações do Convênio 107/2026 somente em 01/11/2026, com exceção expressa de SP |
| NCM | Preservados os 10.516 códigos do download oficial Siscomex e suas vigências, snapshot vigente em 25/10/2026 |
| cBenef | **3.310 registros estaduais**: tabelas completas publicadas SP, ES, GO, PR e SC; recortes DF/RN; fontes históricas RS/RJ bloqueadas; código AC conservado literalmente como modelo bloqueado |
| CST / CSOSN / RTC | Domínios existentes preservados, incluindo 18 CST e 173 cClassTrib IBS/CBS |

As 207 referências CEST/SP da primeira edição permanecem identificáveis na nova edição como modelos históricos, sem substituir a classificação nacional; a edição original continua disponível sem qualquer mudança de bytes. Exceções territoriais atuais usam registros próprios e vigências expressas. Quatro vínculos NCM nacionais inconclusivos ficam disponíveis para consulta e bloqueados para determinação automática, sem completar códigos ou expressões por aproximação.

Não foi obtida uma tabela estadual completa e verificável de cBenef para BA, MG e outras UFs sem publicação validada nesta pesquisa. A matriz **Fiscal → Configurações fiscais → Cobertura por UF** mostra separadamente as 27 UFs e cada domínio. Ausência de tabela no pacote não prova dispensa de código nem inexistência de benefícios. As fontes e impedimentos constam dos relatórios abaixo.

## Comportamento integrado

- CFOP explicitamente vedado para NF-e pelo indicador oficial bloqueia a determinação e a ativação da regra gerenciada. Os validadores de finalidade, operação, CST/CSOSN e grupos XML do emissor continuam aplicáveis.
- O CEST nacional tem precedência para classificação. Exceções por UF e data são verificadas antes da seleção; as alterações futuras não atingem SP quando o Convênio a exclui.
- cBenef gerenciado exige referência da UF, início de vigência comprovado, compatibilidade CST/CSOSN e CRT quando publicados, além da revisão fiscal específica já exigida. Não se converte CST em CSOSN. Fontes históricas ou sem prova suficiente não concedem benefício automaticamente.
- A nota registra versão, hash e referência de CFOP/cBenef. Alterações ou revogações exigem revalidação de rascunhos; documentos autorizados permanecem intactos.
- A importação mantém a precisão dos parâmetros, rejeita escopos, exclusões, vínculos e indicadores malformados e preserva personalizações. `current.json` seleciona o pacote e verifica seu SHA-256.
- O hash canônico é calculado incrementalmente, preservando a compatibilidade com versões anteriores e evitando uma segunda cópia integral do catálogo em memória.

## Instalação e atualização

Primeiro validar em cópia de homologação com backup. Finalizar ou regularizar documentos fiscais pendentes antes de atualizar referências usadas por regras gerenciadas; o histórico anterior não é apagado e documentos autorizados não são recalculados. Uma instalação e um banco por CNPJ continuam sendo a arquitetura do SaaS.

```bash
git pull --ff-only origin feature/nfe55-motor-fiscal-completo
php artisan migrate
php artisan optimize:clear
php artisan fiscal:catalog --install --templates
php artisan fiscal:catalog --json
```

Esta ampliação não requer migrations adicionais às três migrations do catálogo já entregues em 26/10/2026. Os modelos continuam inativos até revisão; nenhum cadastro de regras, alíquota, MVA, FCP, DIFAL ou flag de emissão em produção é alterado pela importação. Regras revisadas/personalizadas são preservadas.

## Fontes e reprodutibilidade

- [CFOP nacional e indicadores oficiais](catalog-national-cfop-sources.md).
- [CEST nacional, períodos e exceção SP](catalog-national-cest-sources.md).
- [cBenef: fontes principais, versões e limitações](catalog-national-benefits-sources.md).
- [Pesquisa regional e estados sem tabela verificada](catalog-national-benefits-regions.md).

Os extratores em `tools/fiscal/` e `scripts/fiscal/` são ferramentas do mantenedor para gerar pacotes revisados, não dependências da instalação do cliente. O ERP utiliza somente PHP e os pacotes JSON distribuídos. A planilha oficial CFOP e capturas normativas CEST estão em `resources/fiscal/sources/` com hashes comparáveis ao manifesto.

Exemplo de montagem pelo mantenedor, após extrair e revisar os arquivos oficiais:

```bash
python tools/fiscal/build_national_catalog.py --base resources/fiscal/catalog/2026.10.26.json --cfop national-cfop.json --cest national-cest.json --benefits national-benefits.json --benefits national-benefits-regions.json --output resources/fiscal/catalog/2026.10.26.1.json --pointer resources/fiscal/catalog/current.json
```

Uma versão publicada é imutável; alterações posteriores precisam de nova identificação. Datas de publicação ou consulta não preenchem vigências legais desconhecidas. Presença de NCM/CEST, nome de grupo e código de benefício não substituem o enquadramento jurídico da operação.

## Validação e limites

Resultado final real: **1.024 testes / 7.261 assertions, zero falhas e erros; dois testes de PDF ignorados por falta de Chromium**. Os extratores passaram em 14 testes Python. Instalação, atualização real da edição anterior e repetição idempotente passaram com memória limitada a 128 MB. Checkout com `core.autocrlf=true` preservou o SHA-256 em quatro arquivos; `.gitattributes` fixa os bytes do pacote também no Windows. Resultados, logs e hashes estão em `docs/fiscal/evidence/fiscal-national-*`. A sessão usa PHP 8.5.12/SQLite; MySQL/PHP 8.3, navegador Windows e nova transmissão ACBr/SEFAZ continuam dependendo da instalação local do proprietário. Nenhuma nova autorização foi simulada.

Cobertura nacional completa foi obtida para as **classificações CFOP e CEST das fontes oficiais consultadas**. Não se declara catálogo completo de todas as legislações, benefícios estaduais, protocolos, MVA, alíquotas ou tratamentos fiscais do Brasil. Correspondências manuais, histórico não comprovado e grupos ainda não suportados pelo emissor permanecem delimitados e bloqueados quando exigem aplicação automática sem fundamento suficiente.
