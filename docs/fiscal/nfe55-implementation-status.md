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
- Rateio **global** de frete, descontos e despesas adicionais entre itens; os valores informados **por item** já possuem persistência e mapeamento no INI do cenário simples, sujeitos à homologação
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
