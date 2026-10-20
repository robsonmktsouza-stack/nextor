# Orçamento comercial com PDF A4 — Lumeron

## Fluxo para o operador

1. Vá a **Vendas > Nova** e selecione **Orçamento** no tipo da operação.
2. Informe cliente, itens, preços, descontos, data e, opcionalmente, a validade (sem preencher, continua valendo a quantidade de dias padrão definida em Configurações).
3. Clique em **Salvar** para abrir o registro ou **Salvar e visualizar PDF** para salvar e abrir o documento diretamente.
4. No detalhe do orçamento, use **Visualizar PDF** (nova aba) ou **Baixar PDF**. Na listagem de Vendas, há atalho de impressão para linhas de orçamento. O download pode ser anexado manualmente no WhatsApp/e-mail; **não há envio automático de arquivo por WhatsApp ou e-mail nesta entrega**.
5. Em orçamentos ativos e dentro da validade, **Converter em venda** exige confirmação. A nova venda é vinculada ao orçamento original; o estoque e financeiro seguem `SalesService` e o estoque disponível no momento da conversão. Repetir o pedido de conversão não gera segunda venda.

## Personalização e segurança

O PDF utiliza automaticamente nome e CNPJ, endereço, contatos, logo (armazenada nas Configurações da empresa) e rodapé configurado de impressão; exibe cliente, número e validade, vendedor, itens com descontos, observações e total. **Não usa a logo do próprio Lumeron como se fosse a empresa emitente.** É um documento comercial, não DANFE, NF-e ou NFC-e.

A geração usa o Chrome/Edge instalado no host do Laravel, via `app/Services/QuotePdfService.php`; não depende de serviço pago nem carrega URL pública. No Windows/Laragon o Chrome do PC normalmente já está instalado. Pode configurar `services.chromium.path` quando necessário (mesma convenção do DANFE existente). Arquivos HTML e PDF temporários são excluídos; não são salvos em pasta pública. URLs exigem sessão e permissão **sales**, retornando `Cache-Control: no-store`. Emissão de nota fiscal não faz parte do PDF do orçamento.

A conversão requer migration `2026_10_20_100000_add_source_quote_to_sales.php`: `source_quote_id` opcional e exclusivo em `sales`, para vínculo auditável e proteção contra conversão duplicada. Orçamentos cancelados, vencidos e já convertidos são bloqueados. Orçamentos nunca geram baixa de estoque nem lançamento financeiro. Uma nova venda após conversão segue o fluxo comercial normal, inclusive permissões e validação de estoque.

## Instalação no Laragon

```powershell
cd C:\laragon\www\nextor
git status -sb
git pull origin feature/acbr-nfe-bootstrap
php artisan migrate
php artisan optimize:clear
```

Confirme que está na branch `feature/acbr-nfe-bootstrap`, preserve alterações locais e mantenha backup antes das migrações. Use Ctrl+F5. Teste com uma empresa de demonstração, confira o PDF A4, faça o download e valide conversão sem repetição da venda.

## Evidências e limitações

Testes automatizados: `tests/Feature/QuoteCommercialDocumentTest.php` (PDF em HTML e bytes reais quando Chromium existe, cabeçalhos HTTP, acesso por sessão, orçamento sem movimentação, conversão e idempotência), além da regressão geral do ERP. O workflow `ACBr integration checks` executa a suíte no CI. Não há conversão em massa, aprovação eletrônica, envio direto de mídia ao WhatsApp ou edição visual do layout A4 pelo operador nesta etapa. O PDF comercial não passa pela SEFAZ.
