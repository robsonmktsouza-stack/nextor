# NF-e 55 — entrega de desenvolvimento em 25/10/2026

Branch exclusiva: `feature/nfe55-motor-fiscal-completo`. Sem merge ou deploy.

Implementação integrada: regras fiscais por vigência/contexto com revisões e
rastreio; calculadores decimais ICMS/ICMS-ST/FCP, retenção anterior,
diferimento, desoneração, DIFAL, IPI e PIS/COFINS percentuais/quantidade;
adaptador IBS/CBS com centavos; builder ACBr; formulário e prévia calculada
no servidor; comércio exterior direto; rascunhos com locks; snapshot e
numeração atômica; filas sem retransmissão cega; cancelamento e inutilização 55.

## Evidência e limites

- Dependências instaladas de `composer.lock`, sem atualização de versões.
- Runtime de teste: PHP 8.5.12, PHPUnit 12.5.40, SQLite, contêiner `composer:2`.
- Baseline original: 619 testes, 4 falhas de expectativas antigas de interface,
  2 ignorados. As expectativas foram atualizadas preservando os controles.
- Primeira integração: 190 testes; encontrados erros Blade e integração,
  corrigidos. Segunda integração: 192 testes, somente 3 expectativas antigas
  remanescentes; corrigidas antes da execução completa.
- Suíte completa: 737 testes, 4695 assertions, 1 falha em expectativas antigas
  de texto da interface e 2 ignorados. Corrigidos os textos; reteste direcionado
  passou: 1 teste, 87 assertions. A suíte completa não foi repetida depois
  dessa última correção somente de teste.
- Lint PHP de app/migrations/tests, compilação Blade e `node --check` aprovados.
- SQLite/cache array não comprovam locks entre processos MySQL. Falta ensaio
  InnoDB com trabalhadores concorrentes e cache compartilhado.
- Não houve chamada nativa ACBr ou transmissão SEFAZ: ambiente sem biblioteca
  Linux ACBr, schemas e certificado A1 configurados. Não há homologação fiscal.

O módulo **não está completo nem liberado para produção**. Permanecem bloqueados
ou sem validação integral: Imposto Seletivo; demais CST/cClassTrib e créditos/
diferimentos RTC; PIS/COFINS-ST; complemento/ajuste/débito/crédito; devolução de
IPI; contingência 55; importação Simples, DUIMP e terceiros; exportação indireta;
matriz tributária de 2027 e períodos seguintes. O DIFAL está calculado e
serializado, mas o bloqueio conservador da operação permanece até validação
normativa e nativa. Não confundir classes existentes com cenários homologados.

Importação exige bases expressas e DI/adições; exportação normal em 2026 ainda
depende de classificação RTC específica. O teste de exportação de 2026 usa
Simples Nacional parametrizado, não uma classe RTC inventada. Valores dos testes
são fixtures aritméticas e não constituem enquadramento jurídico de clientes.

## Atualização em desenvolvimento/homologação

1. Atualizar esta branch por fast-forward; preservar backup e configuração local.
2. Executar `composer install --no-interaction --prefer-dist`.
3. Executar `php artisan migrate` em base de homologação. Novas migrations:
   `2026_10_25_000001_extend_fiscal_rules_for_nfe55.php` e
   `2026_10_25_000003_create_nfe55_inutilizations.php`.
4. Executar `php artisan optimize:clear` e `vendor/bin/phpunit`.
5. Revisar regras com fonte, referência, versão e vigência; informar alíquotas,
   bases e enquadramento IPI explicitamente. CST residual não presume taxa zero.
6. Usar worker `database/fiscal` com timeout 180 s e retry_after mínimo 240 s.
   Os jobs fazem retentativa de disputa de lock, não de transmissão incerta.
7. Configurar ACBr e certificado local de homologação; executar
   `php artisan nfe:preflight ID` e depois o fluxo de emissão de homologação.
   Conferir XML/XSD/assinatura/protocolo/eventos e DANFE com evidência real.

Notas antigas já reservadas sem fingerprint não recebem hash fabricado. Exigem
análise de recuperação e consulta antes de qualquer transmissão. Documentos
autorizados e XMLs originais permanecem preservados.

## Fontes efetivamente acessadas nesta sessão

- ACBr, modelo INI oficial, consulta 25/10/2026:
  https://acbr.sourceforge.io/ACBrLib/ModeloNFeINI.html
  (grupos ICMS, ICMSUFDEST, IPI, II, DI/LADI e Exporta).
- Fonte oficial ACBr `ACBrNFeNotasFiscais.pas`, consulta 25/10/2026:
  https://svn.code.sf.net/p/acbr/code/trunk2/Fontes/ACBrDFe/ACBrNFe/ACBrNFeNotasFiscais.pas

Esses documentos descrevem a integração, não determinam incidência, benefício ou
alíquota. A consulta atualizada integral das normas, tabelas RTC e exigências por
UF permanece pendente e impede declarar conformidade fiscal comprovada.
