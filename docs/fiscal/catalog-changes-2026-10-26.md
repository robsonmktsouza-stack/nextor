# Arquivos da entrega do catálogo fiscal

Base preservada: `1117bc6`; branch `feature/nfe55-motor-fiscal-completo`.

Commits de implementação: `721fe1b`, `38c9c9e`, `ea542a8`. Evidências e passagem de bastão são registradas no commit seguinte.

| Alteração | Arquivo |
| --- | --- |
| Modificado | [README.md](../../README.md) |
| Criado | [app/Console/Commands/FiscalCatalogCommand.php](../../app/Console/Commands/FiscalCatalogCommand.php) |
| Criado | [app/Http/Controllers/FiscalCatalogController.php](../../app/Http/Controllers/FiscalCatalogController.php) |
| Modificado | [app/Http/Controllers/FiscalCodeCatalogController.php](../../app/Http/Controllers/FiscalCodeCatalogController.php) |
| Modificado | [app/Http/Controllers/FiscalTaxGroupController.php](../../app/Http/Controllers/FiscalTaxGroupController.php) |
| Modificado | [app/Http/Controllers/FiscalTaxRuleController.php](../../app/Http/Controllers/FiscalTaxRuleController.php) |
| Modificado | [app/Http/Controllers/NfeDraftController.php](../../app/Http/Controllers/NfeDraftController.php) |
| Modificado | [app/Http/Controllers/OperationNatureController.php](../../app/Http/Controllers/OperationNatureController.php) |
| Modificado | [app/Http/Controllers/ProductController.php](../../app/Http/Controllers/ProductController.php) |
| Modificado | [app/Http/Controllers/QuickCatalogController.php](../../app/Http/Controllers/QuickCatalogController.php) |
| Criado | [app/Models/FiscalCatalogEntry.php](../../app/Models/FiscalCatalogEntry.php) |
| Criado | [app/Models/FiscalCatalogRelease.php](../../app/Models/FiscalCatalogRelease.php) |
| Criado | [app/Models/FiscalOperationNatureRevision.php](../../app/Models/FiscalOperationNatureRevision.php) |
| Modificado | [app/Models/FiscalTaxGroup.php](../../app/Models/FiscalTaxGroup.php) |
| Criado | [app/Models/FiscalTaxGroupRevision.php](../../app/Models/FiscalTaxGroupRevision.php) |
| Modificado | [app/Models/FiscalTaxRule.php](../../app/Models/FiscalTaxRule.php) |
| Modificado | [app/Models/OperationNature.php](../../app/Models/OperationNature.php) |
| Modificado | [app/Models/Product.php](../../app/Models/Product.php) |
| Criado | [app/Services/Fiscal/FiscalCatalogModelReviewService.php](../../app/Services/Fiscal/FiscalCatalogModelReviewService.php) |
| Criado | [app/Services/Fiscal/FiscalCatalogService.php](../../app/Services/Fiscal/FiscalCatalogService.php) |
| Criado | [app/Services/Fiscal/FiscalCatalogTemplateService.php](../../app/Services/Fiscal/FiscalCatalogTemplateService.php) |
| Criado | [app/Services/Fiscal/FiscalProductClassificationService.php](../../app/Services/Fiscal/FiscalProductClassificationService.php) |
| Modificado | [app/Services/Fiscal/FiscalTaxRuleResolver.php](../../app/Services/Fiscal/FiscalTaxRuleResolver.php) |
| Modificado | [app/Services/Fiscal/NFCeTaxGroupTranslator.php](../../app/Services/Fiscal/NFCeTaxGroupTranslator.php) |
| Modificado | [app/Services/Fiscal/Nfe55OperationPolicy.php](../../app/Services/Fiscal/Nfe55OperationPolicy.php) |
| Modificado | [app/Services/Fiscal/Nfe55RtcClassificationCatalog.php](../../app/Services/Fiscal/Nfe55RtcClassificationCatalog.php) |
| Modificado | [app/Services/Fiscal/Nfe55TaxParameters.php](../../app/Services/Fiscal/Nfe55TaxParameters.php) |
| Modificado | [app/Services/Fiscal/Nfe55TaxRuleApplicationService.php](../../app/Services/Fiscal/Nfe55TaxRuleApplicationService.php) |
| Modificado | [database/.gitignore](../../database/.gitignore) |
| Criado | [database/migrations/2026_10_26_000001_create_fiscal_catalog_tables.php](../../database/migrations/2026_10_26_000001_create_fiscal_catalog_tables.php) |
| Criado | [database/migrations/2026_10_26_000002_add_catalog_metadata_to_fiscal_models.php](../../database/migrations/2026_10_26_000002_add_catalog_metadata_to_fiscal_models.php) |
| Criado | [database/migrations/2026_10_26_000003_extend_catalog_rule_context.php](../../database/migrations/2026_10_26_000003_extend_catalog_rule_context.php) |
| Criado | [database/seeders/FiscalCatalogSeeder.php](../../database/seeders/FiscalCatalogSeeder.php) |
| Criado | [docs/fiscal/catalog-sources.md](../../docs/fiscal/catalog-sources.md) |
| Criado | [docs/fiscal/catalogo-fiscal-padrao.md](../../docs/fiscal/catalogo-fiscal-padrao.md) |
| Modificado | [public/js/nfe-editor.js](../../public/js/nfe-editor.js) |
| Criado | [public/js/product-fiscal-catalog.js](../../public/js/product-fiscal-catalog.js) |
| Criado | [resources/fiscal/catalog/2026.10.26.json](../../resources/fiscal/catalog/2026.10.26.json) |
| Criado | [resources/fiscal/catalog/schema-v1.json](../../resources/fiscal/catalog/schema-v1.json) |
| Criado | [resources/views/fiscal/_catalog-review.blade.php](../../resources/views/fiscal/_catalog-review.blade.php) |
| Criado | [resources/views/fiscal/catalog/index.blade.php](../../resources/views/fiscal/catalog/index.blade.php) |
| Modificado | [resources/views/fiscal/index.blade.php](../../resources/views/fiscal/index.blade.php) |
| Modificado | [resources/views/fiscal/nfe/_nature_form.blade.php](../../resources/views/fiscal/nfe/_nature_form.blade.php) |
| Modificado | [resources/views/fiscal/nfe/editor.blade.php](../../resources/views/fiscal/nfe/editor.blade.php) |
| Modificado | [resources/views/fiscal/nfe/natures.blade.php](../../resources/views/fiscal/nfe/natures.blade.php) |
| Modificado | [resources/views/fiscal/rules/_fields.blade.php](../../resources/views/fiscal/rules/_fields.blade.php) |
| Modificado | [resources/views/fiscal/rules/index.blade.php](../../resources/views/fiscal/rules/index.blade.php) |
| Modificado | [resources/views/fiscal/tax-groups/form.blade.php](../../resources/views/fiscal/tax-groups/form.blade.php) |
| Modificado | [resources/views/fiscal/tax-groups/index.blade.php](../../resources/views/fiscal/tax-groups/index.blade.php) |
| Modificado | [resources/views/products/form.blade.php](../../resources/views/products/form.blade.php) |
| Modificado | [routes/web.php](../../routes/web.php) |
| Criado | [tests/Feature/FiscalCatalogAdministrationTest.php](../../tests/Feature/FiscalCatalogAdministrationTest.php) |
| Criado | [tests/Feature/FiscalCatalogDeterminationTest.php](../../tests/Feature/FiscalCatalogDeterminationTest.php) |
| Criado | [tests/Feature/FiscalCatalogRtcIntegrationTest.php](../../tests/Feature/FiscalCatalogRtcIntegrationTest.php) |
| Criado | [tests/Feature/FiscalCatalogServiceTest.php](../../tests/Feature/FiscalCatalogServiceTest.php) |
| Criado | [tests/Feature/FiscalCatalogTemplateTest.php](../../tests/Feature/FiscalCatalogTemplateTest.php) |
| Criado | [tests/Feature/FiscalProductClassificationTest.php](../../tests/Feature/FiscalProductClassificationTest.php) |
| Modificado | [tests/Feature/FiscalTaxGroupPersistenceTest.php](../../tests/Feature/FiscalTaxGroupPersistenceTest.php) |
| Modificado | [tests/Feature/Nfe55AdditionalFederalIntegrationTest.php](../../tests/Feature/Nfe55AdditionalFederalIntegrationTest.php) |
| Criado | [tests/Feature/Nfe55CatalogFormIntegrationTest.php](../../tests/Feature/Nfe55CatalogFormIntegrationTest.php) |
| Criado | [tests/Feature/Nfe55OwnProductionNonContributorIniTest.php](../../tests/Feature/Nfe55OwnProductionNonContributorIniTest.php) |
| Criado | [tests/Unit/FiscalCatalogManifestTest.php](../../tests/Unit/FiscalCatalogManifestTest.php) |
| Modificado | [docs/ai/HANDOFF.md](../../docs/ai/HANDOFF.md) |
| Modificado | [docs/ai/PROJECT_STATE.md](../../docs/ai/PROJECT_STATE.md) |
| Modificado | [docs/ai/ARCHITECTURE_AND_DECISIONS.md](../../docs/ai/ARCHITECTURE_AND_DECISIONS.md) |

Também criados este inventário e os arquivos `docs/fiscal/evidence/fiscal-catalog-*` com resultados reais, hashes e limitações.
