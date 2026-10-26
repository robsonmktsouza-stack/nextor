# Arquivos da ampliação nacional

Branch `feature/nfe55-motor-fiscal-completo`, a partir de `448b6a9`.
Commits de implementação: `c539f16` e `a55be2f`; documentação, evidências e
integridade de checkout Windows no commit seguinte. Sem migrations adicionais.

| Alteração | Arquivo |
| --- | --- |
| Modificado | [.gitattributes](../../.gitattributes) |
| Modificado | [README.md](../../README.md) |
| Modificado | [app/Http/Controllers/FiscalCatalogController.php](../../app/Http/Controllers/FiscalCatalogController.php) |
| Modificado | [app/Http/Controllers/FiscalTaxRuleController.php](../../app/Http/Controllers/FiscalTaxRuleController.php) |
| Criado | [app/Services/Fiscal/FiscalCatalogCoverage.php](../../app/Services/Fiscal/FiscalCatalogCoverage.php) |
| Modificado | [app/Services/Fiscal/FiscalCatalogService.php](../../app/Services/Fiscal/FiscalCatalogService.php) |
| Modificado | [app/Services/Fiscal/FiscalCatalogTemplateService.php](../../app/Services/Fiscal/FiscalCatalogTemplateService.php) |
| Modificado | [app/Services/Fiscal/FiscalProductClassificationService.php](../../app/Services/Fiscal/FiscalProductClassificationService.php) |
| Modificado | [app/Services/Fiscal/Nfe55TaxRuleApplicationService.php](../../app/Services/Fiscal/Nfe55TaxRuleApplicationService.php) |
| Modificado | [docs/ai/HANDOFF.md](../../docs/ai/HANDOFF.md) |
| Modificado | [docs/ai/PROJECT_STATE.md](../../docs/ai/PROJECT_STATE.md) |
| Criado | [docs/fiscal/catalog-national-benefits-regions.md](../../docs/fiscal/catalog-national-benefits-regions.md) |
| Criado | [docs/fiscal/catalog-national-benefits-sources.md](../../docs/fiscal/catalog-national-benefits-sources.md) |
| Criado | [docs/fiscal/catalog-national-cest-sources.md](../../docs/fiscal/catalog-national-cest-sources.md) |
| Criado | [docs/fiscal/catalog-national-cfop-sources.md](../../docs/fiscal/catalog-national-cfop-sources.md) |
| Criado | [docs/fiscal/catalog-national-delivery-2026-10-26.md](../../docs/fiscal/catalog-national-delivery-2026-10-26.md) |
| Modificado | [docs/fiscal/catalogo-fiscal-padrao.md](../../docs/fiscal/catalogo-fiscal-padrao.md) |
| Criado | [docs/fiscal/evidence/fiscal-national-cli-upgrade.txt](../../docs/fiscal/evidence/fiscal-national-cli-upgrade.txt) |
| Criado | [docs/fiscal/evidence/fiscal-national-full-tests.txt](../../docs/fiscal/evidence/fiscal-national-full-tests.txt) |
| Criado | [docs/fiscal/evidence/fiscal-national-parsers-tests.txt](../../docs/fiscal/evidence/fiscal-national-parsers-tests.txt) |
| Criado | [docs/fiscal/evidence/fiscal-national-targeted-tests.txt](../../docs/fiscal/evidence/fiscal-national-targeted-tests.txt) |
| Criado | [docs/fiscal/evidence/fiscal-national-tests.json](../../docs/fiscal/evidence/fiscal-national-tests.json) |
| Criado | [docs/fiscal/evidence/fiscal-national-windows-eol.json](../../docs/fiscal/evidence/fiscal-national-windows-eol.json) |
| Criado | [resources/fiscal/catalog/2026.10.26.1.json](../../resources/fiscal/catalog/2026.10.26.1.json) |
| Criado | [resources/fiscal/catalog/current.json](../../resources/fiscal/catalog/current.json) |
| Modificado | [resources/fiscal/catalog/schema-v1.json](../../resources/fiscal/catalog/schema-v1.json) |
| Criado | [resources/fiscal/sources/confaz-cest-107-2026-10-26.txt](../../resources/fiscal/sources/confaz-cest-107-2026-10-26.txt) |
| Criado | [resources/fiscal/sources/confaz-cest-2026-10-26.txt](../../resources/fiscal/sources/confaz-cest-2026-10-26.txt) |
| Criado | [resources/fiscal/sources/nfe-cfop-2026-09-04.xlsx](../../resources/fiscal/sources/nfe-cfop-2026-09-04.xlsx) |
| Modificado | [resources/views/fiscal/catalog/index.blade.php](../../resources/views/fiscal/catalog/index.blade.php) |
| Criado | [scripts/fiscal/.gitignore](../../scripts/fiscal/.gitignore) |
| Criado | [scripts/fiscal/normalize_regional_benefits.py](../../scripts/fiscal/normalize_regional_benefits.py) |
| Modificado | [tests/Feature/FiscalCatalogAdministrationTest.php](../../tests/Feature/FiscalCatalogAdministrationTest.php) |
| Criado | [tests/Feature/FiscalCatalogNationalScopeTest.php](../../tests/Feature/FiscalCatalogNationalScopeTest.php) |
| Criado | [tests/Feature/FiscalCatalogOfficialDeterminationTest.php](../../tests/Feature/FiscalCatalogOfficialDeterminationTest.php) |
| Modificado | [tests/Feature/FiscalCatalogServiceTest.php](../../tests/Feature/FiscalCatalogServiceTest.php) |
| Criado | [tests/Feature/FiscalNationalCoverageViewTest.php](../../tests/Feature/FiscalNationalCoverageViewTest.php) |
| Criado | [tests/Unit/FiscalNationalCatalogManifestTest.php](../../tests/Unit/FiscalNationalCatalogManifestTest.php) |
| Criado | [tools/fiscal/.gitignore](../../tools/fiscal/.gitignore) |
| Criado | [tools/fiscal/build_national_catalog.py](../../tools/fiscal/build_national_catalog.py) |
| Criado | [tools/fiscal/parse_official_benefits.py](../../tools/fiscal/parse_official_benefits.py) |
| Criado | [tools/fiscal/parse_official_cest.py](../../tools/fiscal/parse_official_cest.py) |
| Criado | [tools/fiscal/parse_official_cfop.py](../../tools/fiscal/parse_official_cfop.py) |
| Criado | [tools/fiscal/test_parse_official_benefits.py](../../tools/fiscal/test_parse_official_benefits.py) |
| Criado | [tools/fiscal/test_parse_official_cest.py](../../tools/fiscal/test_parse_official_cest.py) |

Inclui este inventário e os resultados reais em `docs/fiscal/evidence/fiscal-national-*`.
