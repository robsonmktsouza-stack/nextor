# NF-e modelo 55 — implantação controlada (24/10/2026)

## Verificação de emissão em homologação — 24/10/2026

- Confirmado no manual ACBrLib o mapeamento `NFe.ModeloDF=0` (NF-e modelo 55), `NFe.VersaoDF=3` (4.00) e `NFe.Ambiente=1` (homologação). Estes valores já são usados pelo emissor.
- Nova ferramenta **somente para desenvolvedor/suporte** no PHP CLI: `php artisan nfe:preflight {id}`, onde `{id}` é o número do rascunho validado. A ferramenta testa a geração, assinatura, schema, regras fiscais e chave **sem reservar numeração, alterar o rascunho nem transmitir**; a interface comercial permanece inalterada.
- O retorno de autorização só é aceito quando os códigos de aprovação, a chave e o **ambiente SEFAZ** correspondem integralmente à nota. Uma autorização de produção não valida uma NF-e de homologação nem o contrário.
- Corrigida recuperação de falha antes do contato externo: caso haja XML **apenas assinado e nunca transmitido**, a preparação pode ser retomada depois do período de segurança com a mesma numeração/código. Notas com qualquer tentativa de transmissão continuam bloqueadas para reenvio automático.
- O CI passa a verificar sintaxe do novo comando, registro na lista do Artisan e testes de regressão de ambiente e numeração. **Ainda não foi confirmado resultado de execução do workflow nem do ensaio ACBr/SEFAZ real**.

### Teste inicial recomendado no Laragon

```bat
cd C:\\laragon\\www\\nextor
git pull --ff-only origin develop
php artisan optimize:clear
php artisan test --filter=Nfe55
php artisan nfe:preflight 1
```

Substituir `1` pelo ID real da NF-e salva e validada no Lumeron (sem incluir símbolos `#`). O comando é para operador técnico no ambiente de desenvolvimento, **não deve aparecer como botão no sistema vendido**. Não copiar certificados/senhas; se falhar, reportar somente a mensagem do comando e trechos de log sanitizados. Uma saída "Verificação concluída" **não é autorização SEFAZ**, e a liberação para produção continua bloqueada.

## Padrão de mensagens comerciais — 24/10/2026

- O botão e endpoint públicos de diagnóstico ACBr foram **removidos**. A conferência fiscal nativa continua ativa, mas **integrada ao fluxo normal de Emitir NF-e**, sem expor a tecnologia interna ao cliente.
- A tela mantém **Salvar, Validar e Emitir NF-e**; consulta à SEFAZ, recuperação segura, XML, DANFE e cancelamento aparecem somente quando apropriados ao estado da nota.
- Avisos comerciais não mencionam DLL, FFI, bibliotecas, schemas nem filas; falhas técnicas detalhadas são armazenadas em logs internos, com mensagens curtas e claras na interface.
- Situações da listagem e do editor usam nomes de negócio: **Aguardando envio, Enviando, Aguardando confirmação, Não autorizada, Não enviada**. Códigos oficiais de rejeição da SEFAZ podem permanecer disponíveis para a correção fiscal.
- Não remover ou contornar verificações criptográficas, regras fiscais, registro da transmissão ou controles de reenvio somente para simplificar a interface.

## Revisão da emissão e testes locais — 24/10/2026

- Na instalação Windows, o usuário confirmou pelo `php artisan acbr:nfe-doctor`: ACBrLibNFe **1.5.0.490**, FFI PHP CLI habilitada, `NFE_Inicializar: OK` e 0 falhas. Isso **não** equivale a XML assinado e homologação autorizada.
- Foi extraída e examinada a biblioteca Windows MT/Cdecl x64 do pacote fornecido: SHA-256 `a5c355c67913253dbce28c7f4157c4460321440a0e73cdc66fce28d938be47ba`; a DLL exporta realmente `NFE_VerificarAssinatura` e `NFE_ValidarRegrasdeNegocios`. O contêiner Linux não executa essa DLL Windows.
- Ao clicar **Emitir NF-e** em um rascunho validado, a conferência nativa de `Nfe55PreflightService` é executada **automaticamente e sem botão de diagnóstico**: usa CNPJ e certificado local, cria XML provisório, assina, valida XSD, regras fiscais e assinatura; verifica número, série, cNF e CNPJ; fecha a biblioteca e descarta o arquivo temporário.
- **Essa pré-validação não reserva série/número, não grava XML assinado definitivo, não altera o rascunho, não enfileira e não chama a SEFAZ.** O número usado é somente uma simulação do próximo número configurado.
- O worker de emissão agora também usa `NFE_VerificarAssinatura` antes do envio, além de `NFE_Validar` e `NFE_ValidarRegrasdeNegocios`. A chave é conferida nas posições corretas do CNPJ, modelo, série, número e cNF — não por pesquisa de substring.
- Reprocessamento sem contato com SEFAZ: uma preparação que travou em `processing` há mais de 15 minutos só pode voltar à fila se **não houver chave, XML assinado ou registro de tentativa de transmissão**; reaproveita o número e cNF originais. Notas que podem ter sido transmitidas devem ser consultadas, não reenviadas.
- Corrigida a interface que desabilitava inadvertidamente os botões **Consultar SEFAZ** e **Preparar novamente** em documentos somente-leitura. O DANFE modelo 55 força formato A4 retrato em vez de herdar configurações da NFC-e.
- Testes de unidade/feature adicionados para os cenários de pré-validação, chave divergente e retomada após travamento; CI verifica sintaxe dos novos arquivos. **Ainda não foi possível executar PHPUnit neste ambiente do assistente nem ver um retorno de homologação real.**

**Validação operacional ainda necessária no Windows:** abrir NF-e modelo 55 em homologação, salvar, clicar **Validar** e depois **Emitir NF-e**. O sistema confere a nota internamente **antes de reservar número e enfileirar**. Em caso de erro, conferir `storage/logs/laravel.log` pelo identificador do rascunho; nunca copiar certificado nem senha. Só após XML assinado aprovado, usar **Emitir NF-e** e verificar worker fiscal, cStat e protocolo da SEFAZ. Não liberar produção automaticamente.

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
- Cancelamento modelo 55: fluxo de solicitação, auditoria, fila fiscal e chamada ACBr implementados apenas em homologação, ainda sem validação de um evento autorizado real; casos de retorno indeterminado exigem conferência manual e nunca reenviam automaticamente. CC-e, inutilização e conciliação abrangente permanecem pendentes.
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

## Cancelamento modelo 55 — desenvolvimento controlado

- Migration `2026_10_23_000007_add_nfe55_cancellation_fields.php`: trilha de solicitação, tentativa de envio, protocolo separado e resposta preservada em disco privado.
- `Nfe55CancellationService` e `ProcessNfe55CancellationJob`: somente documento autorizado de modelo 55 e emitente correspondente; rejeita segundo pedido, restringe ao ambiente de homologação e reivindica o evento em banco ANTES da chamada externa. O job tem uma tentativa e não reenvia automaticamente eventos incertos.
- A operação exige permissão fiscal para acessar o módulo, permissão de configurações para solicitar o cancelamento, senha atual e confirmação explícita de que a operação não ocorreu. O documento original não é excluído; estoque e financeiro não são revertidos automaticamente.
- Resposta ACBr aceita como cancelamento comprovado somente quando cStat, chave, ambiente e protocolo conferem e existe prova individual do evento no XML; ausência de prova gera situação indeterminada que deve ser reconciliada na SEFAZ.
- Testes `Nfe55CancellationTest` verificam fila única, senha/permissão, produção bloqueada, documento imutável e prevenção de reenvio. Integração no workflow `nfe55-homologation.yml`.
- **Não é homologação real:** envio de evento não foi executado com DLL, certificado ou SEFAZ. Não utilizar para um cliente antes de validar a biblioteca e a prova de retorno em ambiente de testes. Não promover para main/VPS sem CI e validação operacional.
