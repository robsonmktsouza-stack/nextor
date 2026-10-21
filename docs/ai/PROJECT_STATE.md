# Estado do projeto — Lumeron ERP

**Retrato de referência:** 19/10/2026, inspeção do código remoto da branch `feature/acbr-nfe-bootstrap` no repositório `robsonmktsouza-stack/nextor`. **Não é um atestado de funcionamento em produção**; não foram executados testes locais ou chamadas reais à SEFAZ para elaborar este retrato.

## Decisão de gestão de releases — 20/10/2026 (não é deploy)

O proprietário **decidiu avançar para piloto de NFC-e em produção e continuar desenvolvendo novas funções sem interromper clientes**. Política permanente em [docs/deployment/versioning-and-release-policy.md](../deployment/versioning-and-release-policy.md). A versão instalada por CNPJ deve ser aprovada, identificada por tag/commit e isolada de pushes de desenvolvimento. Estratégia futura: `main` estável, `develop` integração, `feature/*` recursos e `hotfix/*` patches. **Não inferir que o piloto já foi implantado**. Posteriormente, em 20/10/2026, a `main` foi promovida por fast-forward, a `develop` foi criada, o `composer.lock` foi versionado e o workflow principal passou a cobrir as branches estável/de integração. A tag `v1.0.0` deve ser verificada no GitHub antes de afirmar sua existência, assim como qualquer deploy real. Não executar merge, tag, deploy ou migração em ambiente real só por esta decisão.

## Identidade e base

- Nome de exibição: **Lumeron ERP**, antes **Nextor**. O nome GitHub `nextor`, caminhos `nextor.*`, classes históricas e alias `/api/nextor` permanecem quando necessários para compatibilidade; a API nova está em `/api/lumeron`.
- Stack verificada no `composer.json`: Laravel 13, PHP ^8.3, Blade, CSS/JavaScript, MySQL/MariaDB (SQLite disponível para testes, conforme configuração).
- Ambiente de desenvolvimento documentado: Windows + Laragon. Projeto Laravel **web**, não confundir com `robsonmktsouza-stack/lumeronerp` (**WPF desktop**, projeto distinto).
- Histórico de 19/10/2026: `feature/acbr-nfe-bootstrap` era a branch de trabalho e `main` era antiga. **Atualização de 20/10/2026:** `main` recebeu a linha estável e `develop` foi criada para novos trabalhos. Não alterar `main` diretamente; verificar commits antes de qualquer promoção.
- Em 19/10, `composer.lock` ainda não existia. **Em 20/10, foi gerado no CI PHP 8.3, versionado e validado.** Instalações de release devem usar `composer install` com lock; não executar `composer update` na VPS.

## Funcionalidades com código identificado

| Área | Evidência encontrada | Limite do status |
| --- | --- | --- |
| Login/segurança | `routes/web.php`, `AuthController`, recuperação de senha, `tests/Feature/LumeronLoginTest.php` | Fluxo codificado; validar testes e implantação de portal separadamente |
| Cadastros | Rotas para produtos, clientes, serviços e configurações, incluindo permissões | Conferir regras de negócio ao alterar |
| Comercial | `PdvController`, `SalesService`, `InventoryService`, estoque, devoluções | Transações e consistência de estoque/financeiro exigem testes de regressão |
| Financeiro | `FinanceController`, conciliação, transferências, recebimentos, recorrências | Não criar movimentações duplicadas |
| Fiscal | `FiscalController` lista NF-e, NFC-e, NFS-e, CT-e, MDF-e | Telas/rotas **não comprovam emissão completa** de todos os modelos |
| NFC-e (65) | `NFCeManualController`, `NFCePreflightService`, `NFCeTransmissionService`, `ACBr/ACBrNFeService`, jobs de emissão, consulta e eventos | Em desenvolvimento/testes assistidos, principalmente SEFAZ-BA; **não afirmar homologação ou autorização para produção** |
| Marca | `public/images/lumeron/logo.png`, `elephant.png`, `public/css/branding.css`, `tests/Feature/LumeronBrandingTest.php` | Usar imagens oficiais; nunca inventar marca substituta |
| Isolamento | `config/instance.php`, `InstanceIdentity`, `InstanceIsolationTest.php`, `deploy/instances/` | Scripts no repo **não significam VPS implantada** |

## Fluxo fiscal em implementação

Venda concluída / pedido explícito da interface -> preparação fiscal -> preflight -> documento preparado -> job/serviço de emissão -> ACBrLibNFe (INI, assinatura, validação e envio quando aplicável) -> processamento do retorno -> protocolo/XML e DANFE -> histórico/eventos. A contingência offline é um caminho distinto; não tratá-la como autorização online. A emissão não deve duplicar a venda nem movimentos de estoque/caixa.

Arquivos relevantes: `app/Services/Fiscal/`, `app/Services/FiscalPreparationService.php`, `app/Jobs/ProcessNFCeJob.php`, `app/Http/Controllers/NFCeManualController.php`, `app/Http/Controllers/FiscalController.php`, `app/Services/SalesService.php`.

## Orçamentos comerciais (20/10/2026)

O fluxo existente de orçamentos `sales.operation_type=quote` ganhou template de PDF A4 personalizado por empresa, geração privada com Chrome/Edge, visualização/download e conversão para venda vinculada a `sales.source_quote_id` único. Validade opcional em `sales.create`; consultas e listagens exibem ações correspondentes. Converter aciona o próprio serviço comercial com estoque/financeiro normais; salvar orçamento não movimenta estoque. Exige migration `2026_10_20_100000_add_source_quote_to_sales.php`. Ver `docs/commercial/quote-pdf.md` e testes `QuoteCommercialDocumentTest`. Não foi feito deploy ou envio real de arquivos a clientes.

## Próximas verificações prioritárias

1. Confirmar a branch ativa e rodar testes locais sem usar dados fiscais/credenciais reais.
2. Validar ciclo completo da NFC-e em homologação: preparação, rejeição, correção, reconsulta, cancelamento, inutilização e contingência; verificar efeito nulo sobre vendas/estoque/financeiro nas reemissões. Sem transmitir documento real de cliente sem autorização.
3. Verificar integridade dos XMLs e protocolos, logs sem segredos, locks/concorrência e impressão DANFE 80 mm.
4. Resolver `composer.lock` em desenvolvimento, revisar pipelines e preparar implantação isolada por CNPJ **somente com autorização**.
5. Revalidar se o foco continua sendo módulo fiscal antes de assumir uma nova tarefa; a solicitação atual pode alterar a prioridade.

## Comandos de conferência (executar no ambiente correto)

```bash
git status -sb
git branch --show-current
git log -5 --oneline
composer install
php artisan optimize:clear
php artisan test --filter=LumeronLoginTest
php artisan test --filter=InstanceIsolationTest
php artisan test --filter=LumeronBrandingTest
php artisan test --filter=NFCeManualEditorTest
php artisan test --filter=NFCeFiscalLifecycleTest
php artisan test --filter=UiRefinementTest
```

**Atenção:** usar o `composer.lock` agora versionado e não resolver novas versões diretamente em produção. Os comandos acima são sugestões de regressão, não alegações de execução local pelo proprietário. Chamadas reais à SEFAZ, FFI/ACBr e emissão dependem de instalação/configuração próprias e testes explícitos.


## Atualização de hospedagem SaaS econômica — 21/10/2026

Somente na develop, foram adicionados releases de código+vendor imutáveis,
deduplicados por hardlinks de inodes entre runtimes por CNPJ, sem compartilhar
banco ou arquivos privados, com controles de aprovação, versão, atualização,
rollback e limpeza. Consumo de RAM potencial dos workers permanentes reduzido
por opção fiscal batch oneshot; cliente exigindo baixa latência pode habilitar
worker rápido dedicado. A VPS ainda não recebeu esta arquitetura. Estado e
limites estão em docs/deployment/shared-immutable-releases.md. Consulte a
CI do último SHA da develop antes de declarar testes plenamente aprovados.
