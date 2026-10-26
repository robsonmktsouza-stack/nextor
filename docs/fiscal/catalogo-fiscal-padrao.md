# Catálogo fiscal padrão — Lumeron ERP

Entrega na branch `feature/nfe55-motor-fiscal-completo`. Código para revisão, sem deploy ou alterações nas configurações de produção. A autorização em homologação relatada pelo proprietário pertence ao fluxo anterior; não foi repetida pela sessão Linux para esta mudança.

## Estrutura e integração

O catálogo reutiliza `OperationNature`, `FiscalTaxGroup`, `FiscalTaxRule`, o vínculo `products.fiscal_tax_group_id` e o motor NF-e existente. Não cria um segundo emissor, não altera documentos autorizados e não troca a ACBrLibNFe. Valores decimais importados são strings; os cálculos continuam nos serviços existentes de precisão decimal.

Cada versão contém referências oficiais com fonte, consulta, versão, escopo, condições e vigência quando comprovada. Versões instaladas são imutáveis; o importador rejeita conteúdo diferente sob a mesma versão, datas inválidas, sobreposição, fonte sem URL oficial HTTPS, números decimais em ponto flutuante, atualização com omissão de códigos anteriores e regressão da data de consulta. Revogações entram em uma nova versão como registros revogados, preservando o histórico. O mutex é por banco e a importação é transacional.

As personalizações permanecem nas naturezas, grupos e regras da instalação. Os modelos novos começam inativos; não há regra tributária habilitada pela instalação. Modelos revisados, ativos ou modificados são preservados por atualizações. Revisões de natureza e grupo guardam snapshot e autor; as regras continuam usando seu histórico existente. O nome do grupo não determina incidência, benefício ou alíquota.

A determinação existente passou a considerar natureza, grupo, município e indicador de ICMS, além dos critérios já disponíveis. No modo gerenciado pelo catálogo, exige modelo e regra revisados e vigentes, identifica conflitos/ausência e impede divergências tributárias sem revisão. O CFOP vem da regra revisada para a operação, sem modificar o produto. Contribuinte isento de inscrição (indIEDest 2) continua distinto de não contribuinte (9). Parâmetros do grupo revisado são reutilizados na determinação, com precedência da regra específica. As validações de ST, DIFAL, FCP, contribuições e reforma permanecem nos calculadores e no builder existentes, sem pressupor incidência por NCM ou CEST. O calculador RTC consulta os indicadores e vigências da versão instalada; uma classificação ausente ou incompleta não recebe fallback permissivo.

O formulário usa essa determinação na prévia e na validação; mostra regra, versão e códigos. Um rascunho incompleto pode ser salvo com a pendência, mas não passa à emissão. Classificações legadas inalteradas são preservadas no cadastro; produtos gerenciados pelo catálogo exigem classificação válida antes da emissão. Consultas, cancelamentos, numeração, fila, protocolo, XML e DANFE mantêm o fluxo existente.

## Conteúdo distribuído

Versão `2026.10.26`, com **11.063 referências**:

| Referência | Quantidade | Alcance |
| --- | ---: | --- |
| NCM | 10.516 | Todos os códigos de oito dígitos do download oficial Siscomex, snapshot vigente em 25/10/2026; vigências individuais preservadas |
| CFOP | 64 | Subconjunto com definições oficiais; publicação SEFAZ-PE com metadados de 2015, exige revisão da redação vigente |
| CEST | 207 | Subconjunto identificado em publicação oficial de SP, escopo SP e vínculos NCM; não é tabela nacional completa |
| Origem / CRT / CSOSN / CST ICMS | 9 / 4 / 10 / 15 | Códigos de referência, sem promessa de suporte a todos os grupos XML |
| CST PIS/COFINS / IPI | 33 / 14 | Classificações; não atribuem regime, alíquota ou direito a crédito |
| CST IBS/CBS / cClassTrib | 18 / 173 | Snapshot SVRS; indicadores e condições oficiais preservados |

Há **16 modelos de natureza** (vendas de terceiros e produção própria por perfil do destinatário, consumidor final, compras, devoluções, remessa/retorno de conserto e bonificação) e **13 modelos de grupo** (Simples, ST, monofásico, tratamento específico, imunidade, não tributação, isenção, devolução, interestadual, normal, sublimite e reforma). Todos começam inativos e sem alíquotas padrão. Os modelos legados existentes não são alterados.

Fontes, hashes dos downloads, condições e impedimentos de pesquisa: [catalog-sources.md](catalog-sources.md). Contrato do pacote: [schema-v1.json](../../resources/fiscal/catalog/schema-v1.json).

## Administração

Em **Fiscal → Configurações fiscais**, consultar CFOP, NCM/CEST, CST/CSOSN, reforma, parâmetros e benefícios; filtrar por versão, código, descrição, data, UF e situação. As configurações de natureza, grupo e regra continuam em seus cadastros existentes. O histórico permite consultar versões anteriores e suas fontes.

Para um modelo novo, abrir o cadastro, preencher a configuração e registrar revisão fiscal com fonte, fundamento, condições e vigência. Depois cadastrar regras específicas para CRT/UF/destinatário/natureza/grupo/produto, com os tributos comprovados. A revisão local registra responsabilidade e critérios; não equivale a parecer fiscal ou homologação SEFAZ.

O cadastro de produtos possui consulta NCM/CEST. Um CEST não torna a mercadoria automaticamente sujeita à ST; descrição legal, acordo, UF, responsabilidade e operação continuam necessários. Exceções podem ser configuradas por produto na regra específica, com prioridade e fundamento, sem alterar o cadastro original durante a emissão.

## Instalação e atualização

Executar primeiro em cópia de homologação da instalação escolhida, após backup. Uma instalação e um banco por CNPJ continuam sendo a arquitetura do SaaS; não há tabela de empresas compartilhada. Um pacote específico com `target_cnpj` de outra empresa é rejeitado. Não copiar banco, certificado, XML ou parâmetros entre empresas.

```bash
git pull --ff-only origin feature/nfe55-motor-fiscal-completo
composer install
php artisan migrate
php artisan optimize:clear
php artisan fiscal:catalog --install --templates
php artisan fiscal:catalog --json
```

Migrações novas: `2026_10_26_000001_create_fiscal_catalog_tables.php`, `2026_10_26_000002_add_catalog_metadata_to_fiscal_models.php`, `2026_10_26_000003_extend_catalog_rule_context.php`. São reversíveis tecnicamente, mas uma reversão remove o histórico novo: restaurar backup continua sendo a forma apropriada de desfazer uma atualização com dados importantes. Não executar rollback indiscriminado.

`--install` instala somente referências; `--install --templates` inclui os modelos inativos. A tela oferece a mesma escolha. O seeder explícito é `php artisan db:seed --class=FiscalCatalogSeeder`. Nada é habilitado automaticamente por migration ou atualização de código.

Para importar pacote revisado, usar SHA-256 do arquivo exato (no Windows: `Get-FileHash .\catalogo.json -Algorithm SHA256`):

```bash
php artisan fiscal:catalog --import=catalogo.json --sha256=HASH_DO_ARQUIVO_REVISADO
```

A interface também exige o hash. Hash e URL oficial verificam integridade e identificação declarada; não são assinatura da autoridade fiscal nem autenticação independente do conteúdo. A distribuição deve usar o release aprovado do Lumeron. Um pacote deve conter snapshot completo dos códigos anteriormente instalados, inclusive revogados; importação não sobrescreve regras ou personalizações locais. Uma nova tributação só passa a valer após revisão dos parâmetros aplicáveis à instalação.

## Verificação e limites

Resultados reais estão em `docs/fiscal/evidence/fiscal-catalog-*`. Os testes executados usam PHP 8.5.12 e SQLite neste ambiente, não MySQL/PHP 8.3 do Laragon. Validar também a atualização em MySQL e a interface no navegador local antes de publicar um release.

```bash
php artisan test --filter='FiscalCatalog|FiscalProductClassification|Nfe55CatalogForm'
php artisan test
```

Impedimentos e cobertura remanescente:

- O acesso ao texto consolidado do CONFAZ e páginas SEFAZ-BA retornou erros HTTP nesta consulta. Não foi produzido um catálogo nacional completo de CEST ou CFOP vigente; a cobertura parcial consta no pacote e na interface. Códigos fora da cobertura parcial não são declarados inválidos por ausência.
- Benefícios BA, protocolos ST, MVA, pautas, alíquotas estaduais/municipais, exceções jurídicas e enquadramentos individuais não vêm preenchidos. A estrutura de referências e regras admite importação/configuração fundamentada; sem regra revisada, não há aplicação automática segura.
- Classificações RTC não completam grupos de cálculo ainda não suportados pelo emissor. Grupos avançados de crédito/monofasia/ajuste e demais limitações previamente documentadas continuam bloqueados pelos validadores.
- Não foi executada nova transmissão ACBr/SEFAZ nem testada a DLL Windows nesta sessão. O certificado e a instalação Laragon do proprietário não são acessíveis pela nuvem. Nenhum resultado de homologação foi simulado.
- As regras legadas do emissor mantêm seu comportamento para preservar os cenários já autorizados. O bloqueio novo de ausência aplica-se aos modelos gerenciados pelo catálogo, sem reinterpretar retroativamente documentos ou cadastros existentes.
