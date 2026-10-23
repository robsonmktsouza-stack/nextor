# NF-e modelo 55 — implantação controlada (23/10/2026)

## Implementado em develop

- Editor persistente, com dados de destinatário, natureza, itens, impostos, transporte, pagamento e referências
- Endereço de entrega diferente do destinatário, com escolha entre endereços cadastrados e preenchimento manual
- Transportadoras pesquisáveis e cadastro rápido sem abandonar a NF-e
- Frete, seguro, outras despesas e desconto por item, com recálculo fiscal e persistência individual
- Múltiplas formas de pagamento com soma conciliada ao total real da nota
- Grupos ACBr INI de Entrega, Transportador/Volume, Fatura/Duplicatas, múltiplos pagamentos e NF-e referenciadas
- Campos IBS/CBS de alíquotas e reduções, vinculados ao payload fiscal; cenários ainda não homologados são recusados
- Validações de CEP, município, UF e valores; não substituir dados inválidos por zeros arbitrários
- Preparação ACBrLibNFe via PHP FFI, modelo 55
- Geração do INI com série/número reservados, código numérico e ambiente
- Pré-validação de dados e recusa explícita dos cenários tributários ainda não suportados
- Enfileiramento individual no worker fiscal, sem repetição automática de envio
- Assinatura digital, validação de schemas e arquivo XML assinado privado antes de contatar a SEFAZ
- Preservação da chave antes do envio para posterior consulta e conciliação
- Processamento de retorno individual, protocolo e XML autorizado nfeProc, sem inventar autorização
- Consulta de NF-e pendente pela chave de acesso, sem reenviar
- Download de XML e geração de DANFE somente mediante autorização verificável
- Bloqueio de alteração/exclusão depois de reservada a numeração
- Nova tentativa da preparação, usando o **mesmo número e cNF**, somente se nunca houve transmissão
- Testes de segurança, builder fiscal e regressão do editor no CI

## Escopo de autorização atual

A saída via ACBrLib está habilitada **apenas em homologação**, para operação normal interna de mercadorias com CRT 1 ou 4 e CSOSN 102, 103, 300 ou 400. PIS/COFINS CST 49/99 e IPI CST 53 permanecem no escopo inicial.
O cálculo IBS/CBS, quando configurado, usa a matriz existente, e rejeita qualquer perfil incompleto.

Operações que excedem esses limites são interrompidas antes da SEFAZ. Não substituir tributação ou dados fiscais por zeros presumidos para contornar o bloqueio.

## Ainda não concluído — NÃO liberar produção

- Emissão **real** em homologação com a ACBrLib instalada, certificado A1 válido e schemas atualizados; conferir XML, assinatura, regras de validação e protocolo real
- Homologação de regimes além dos casos mapeados (CRT 2/3, ST, ICMS normal e desonerações, monofasia, DIFAL/FCP, operações interestaduais, exportação)
- Rateio global proporcional com arredondamento em centavos para frete, descontos e acréscimos, além dos valores por item; código implementado, mas o XML final depende de homologação na biblioteca ACBr e na SEFAZ
- Devolução, notas complementares, ajuste, transferência, referências especiais e documentos fiscais correlatos
- Parametrização abrangente de IBS/CBS/IS conforme NT vigente
- Eventos de cancelamento, CC-e, inutilização, manifestação aplicável e respectiva conciliação
- Faturamento/financeiro e saída de estoque na autorização para emissores que não operam pelo PDV
- Impressão térmica ou A4 na infraestrutura real, envio por e-mail e distribuição
- Auditoria final de numeração por ambiente, diferenças de fuso/UF e coexistência com documentos anteriores
- Validação ACBrLib x64 Linux na VPS, certificados e filas segregadas por empresa

**Não inferir produção aprovada a partir de CI.** O GitHub Actions valida código e testes simulados; não possui certificado fiscal nem acesso autenticado à SEFAZ.

## Execução local

1. `git switch develop && git pull --ff-only origin develop`
2. `php artisan migrate && php artisan optimize:clear`
3. `php artisan acbr:nfe-doctor` (somente verificação de biblioteca)
4. Configurar NF-e em **homologação** e certificado A1, com biblioteca MT/Cdecl x64 e schemas.
5. Iniciar worker dedicado: `php artisan queue:work --queue=fiscal --tries=1 --timeout=180`.
6. Preencher uma NF-e de mercadorias simples, salvar, **validar**, emitir e verificar status/XML/DANFE sem usar um CNPJ de produção.

A migração de emissão não altera documentos pré-existentes nem a branch main.

## Estado do formulário após revisão de 23/10/2026

O editor agora persiste e reabre endereço de entrega, transportadoras independentes, pagamentos parciais e valores individuais por item. A documentação da ACBr confirma os grupos INI utilizados, mas **não há confirmação de execução real de NF-e modelo 55 na SEFAZ de homologação**.

O arquivo ZIP da mesma edição da ACBrLibNFe usada localmente (MT Cdecl x64, com exemplos e schemas) é necessário para validar de ponta a ponta a montagem do XML no Windows e sua equivalência no Linux. Não guardar certificados ou segredos no repositório.

## Ajustes de rateio, cobrança e cartões — 23/10/2026

- Valores gerais informados de frete, desconto e acréscimo são rateados em centavos entre os itens no INI fiscal, mantendo os valores originais no rascunho. Frete proporcional e acréscimo em vOutro usam o valor bruto dos itens; descontos proporcionais usam valor elegível após os acréscimos e descontos individuais. O total das parcelas rateadas confere com os totais da NF-e e valores negativos/incompatíveis são recusados. Não houve homologação SEFAZ real.
- Descontos individuais ou gerais que ultrapassem o valor da nota são recusados antes do salvamento, em vez de gerar NF-e zerada silenciosamente.
- Cobrança: até 120 duplicatas; número curto e único, vencimentos válidos e ordenados, valores positivos, soma conciliada. O tipo de pagamento 14 (Duplicata Mercantil) exige duplicatas, e as duplicatas exigem tipo 14 (regras SEFAZ 867/872). Rascunhos anteriores inconsistentes são recusados também no builder.
- Cartões de crédito/débito: o editor pergunta explicitamente integração 1 (TEF) ou 2 (POS), incluindo pagamentos adicionais. Tipo 1 exige CNPJ da credenciadora e autorização; o valor é persistido em payments[*].integration_type e emitido como tpIntegra em cada grupo [pagNNN]. Não é inferido da forma de pagamento. O formulário também preserva códigos numéricos após reabertura.
- Testes novos em NfeFormCompletenessTest: rateio de centavos e conciliação de total fiscal, sobre-desconto, parcelas inválidas/ordem/forma de pagamento, TEF/POS e round-trip do editor.
- Ainda pendente: executar CI e homologação fiscal real com a edição instalada da ACBrLibNFe MT Cdecl x64 e schemas atualizados, inclusive regras por UF. Não promover para main nem VPS com base apenas em código/testes adicionados.
