# NFC-e modelo 65 — formulário, leiaute e SEFAZ-BA

**Data da análise e da intervenção:** 19/10/2026.
**Código:** `robsonmktsouza-stack/nextor`, branch `feature/acbr-nfe-bootstrap`.
**Estado:** formulário e controles aprimorados no repositório; **ainda não homologado com SEFAZ/ACBr após estas mudanças**. Nunca afirmar que está pronto para produção sem comprovação de autorização real e revisão do leiaute/NT aplicável.

## Fontes normativas consultadas

- [Portal Nacional NF-e — manuais, DANFE NFC-e e regras de validação](https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?AspxAutoDetectCookieSupport=1&tipoConteudo=ndIjl+iEFdE%3D)
- [Portal Nacional — Notas Técnicas vigentes, incluindo NT 2025.001 (QR Code v3) e NT 2025.002 (RTC)](https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?AspxAutoDetectCookieSupport=1&tipoConteudo=04BIflQt1aY%3D)
- [NT 2025.001 v1.03 — modelo de QR Code 3](https://www.nfe.fazenda.gov.br/Portal/exibirArquivo.aspx?conteudo=NvuzQGYd6E8%3D)
- [Receita Federal, 03/08/2026 — flexibilização de rejeições por ausência de IBS/CBS](https://www.gov.br/receitafederal/pt-br/assuntos/noticias/2026/julho/receita-federal-e-cgibs-flexibilizarao-obrigatoriedade-de-informacoes-em-documentos-fiscais)
- [Receita Federal, 16/10/2026 — calendário RTC e situações do Simples Nacional](https://www.gov.br/receitafederal/pt-br/acesso-a-informacao/acoes-e-programas/reforma-tributaria-do-consumo/orientacoes-da-reforma-tributaria)
- [Modelo de INI da ACBrLibNFe — estrutura e campos de pagamento](https://acbr.sourceforge.io/ACBrLib/Modelo2-NFCeINI.html)
- Referências adicionais da SEFAZ-BA em [pdv-nfce-bahia.md](pdv-nfce-bahia.md).

**Cuidado com versões:** o leiaute RTC e sua obrigatoriedade mudam por NT e atos conjuntos, por UF, regime e vigência. Nunca exigir IBS/CBS de todos os emitentes de maneira uniforme apenas pelo ano-calendário. Tampouco assumir que a NT do QR Code v3 eliminou imediatamente CSC em todas as configurações: o próprio cronograma e a biblioteca autorizadora determinam a modalidade ativa.

## Matriz do formulário de nova emissão

| Requisito | Como é atendido | Situação |
| --- | --- | --- |
| Modelo 65, série, numeração | Modelo/série no formulário em leitura; numeração reservada pelo preparador (sequência por instância) | Implementado; concorrência deve ser testada |
| Emitente, IE, CRT, endereço/município, UF BA | Obtidos do cadastro da instalação; preflight valida presença | Implementado; conferir dados reais |
| Ambiente e contingência | Exibidos no formulário, configurados na instância; fluxo offline separado | Implementado; exigir homologação real |
| Operação com consumidor final e presencial | Fluxo existente fixa `indFinal=1`, `indPres` e modelo 65 no INI | Verificar XML contra NT |
| Consumidor identificado/opcional | Campo CPF/CNPJ e cliente; **dígitos verificadores** validados no JS e PHP antes da venda | Implementado (documentos numéricos) |
| Produtos, quantidades, preço, desconto | Catálogo e editor existente; NCM exibido; venda valida soma e estoque | Implementado; conferir cenários especiais |
| NCM, CFOP, CEST, origem, ICMS, PIS, COFINS | Regras fiscais de cadastro aplicadas pelo preparador; preflight e calculadora não inventam tributos | Parcial: calculadora rejeita combinações ainda não suportadas |
| Pagamentos dinheiro/cartão/PIX, valores | Editor existente com múltiplas formas, validação de total | Implementado; testar divisão/troco |
| Cartões TEF/POS | Campos de integração, adquirente, bandeira, autorização, terminal | Implementado; preencher dados reais |
| CNPJPag/UFPag/CNPJReceb | Campos de CNPJ local+UF, beneficiário; informações preservadas no pagamento e snapshot | Implementado; exigibilidade varia por cenário/NT |
| Emissão e assinatura | Transmissão ACBrLib por fila `database/fiscal`, fora da requisição | Código preparado; execução/credenciamento pendentes |
| XML de resposta / protocolo / DANFE | Serviços existentes de consulta, estados e impressão | Depende de resposta real da SEFAZ |
| IBS/CBS integral, CST 000 / cClassTrib 000001 | Motor RTC independente lê as três alíquotas do grupo fiscal, calcula valores por item e totais inteiros em centavos, gera grupos de INI ACBr; preflight exige classificação para regime normal a partir de agosto/2026 | Implementado no código; testes PHPUnit e XSD/ACBr ainda precisam passar |
| RTC: redução, diferimento, monofásico, transferências, IS, CNPJ alfanumérico | Grupos que exigem fórmulas distintas são bloqueados; o cálculo padrão não os substitui | PENDENTE: implementar cada perfil com tabela vigente e homologação real |
| Operações especiais (combustíveis, ST, monofásicos, entre outros) | Alguns perfis bloqueados pela calculadora quando não implementados | PENDENTE: tratar individualmente antes de declarar suporte |

## Adequação RTC realizada após a revisão inicial (19/10/2026)

- **Novo serviço:** `app/Services/Fiscal/NFCeRtcTaxService.php` calcula apenas `CST=000`, `cClassTrib=000001` (tributação integral). Usa `ibs_uf_rate`, `ibs_municipal_rate`, `cbs_rate` cadastrados no grupo. **Não** presume alíquota-teste, redução, classificação nem legislação estadual.
- **Vínculo com regras:** `NFCeTaxGroupTranslator` preserva classificação e alíquotas no snapshot fiscal de cada produto; a configuração é feita em `Fiscal → Grupos tributários → IBS/CBS`.
- **INI conforme modelo ACBrLibNFe:** `NFCeIniBuilder` gera `[IBSCBSnnn]`, `[gIBSCBSnnn]`, `[gIBSUFnnn]`, `[gIBSMunnn]` (com índice de três dígitos), `[gCBSnnn]` e totalizações `[IBSCBSTot]`, `[gIBS]`, `[gIBSUFTot]`, `[gIBSMunTot]`, `[gCBSTot]`. Os grupos só são adicionados quando há cálculo aplicável.
- **Regime regular CRT 2/3:** considerando o cronograma de agosto/2026, o preflight bloqueia a NFC-e sem classificação RTC; o adiamento das **rejeições automáticas** não eliminou a obrigação de preenchimento. Fonte: [esclarecimento conjunto CGIBS/RFB de 06/08/2026](https://cgibs.gov.br/receita-federal-e-comite-gestor-do-ibs-esclarecem-adiamento-das-regras-de-validacao-dos-documentos-fiscais-eletronicos).
- **Simples Nacional CRT 1/4:** não atribui IBS/CBS por conta própria em 2026, de acordo com os efeitos previstos para 2027; o perfil especial de 2027 exigirá tratamento próprio, em vez de copiar a fórmula integral.
- **Testes adicionados:** `tests/Unit/NFCeRtcTaxServiceTest.php`, `tests/Feature/NFCeRtcIniTest.php`, com CI `.github/workflows/nfce-rtc-tests.yml`. Os testes validam cálculos em centavos, segregação das alíquotas, omissões e INI. CI `NFC-e RTC regression` executou os testes unitários e de INI com sucesso em 19/10/2026; os testes integrais e a homologação externa permanecem separados.
- **Atenção:** a documentação ACBr indica nomes das seções, mas a validação final depende da **versão instalada da ACBrLib** + schemas oficiais e autorização em homologação. Não liberar produção antes disso.

**Demais situações tributárias e cClassTrib:** redução, diferimento, alíquota zero com grupos específicos, transferência de créditos, crédito presumido, monofásicos, IS e benefícios estaduais ainda **não são tratados integralmente**. Recusar emissão desses perfis, sem inventar valores, é intencional.

## Contrato entre formulário, controller e emissão

- `resources/views/fiscal/nfce/create.blade.php`: orientações, emitente e configurações de leitura; cinco abas de trabalho.
- `public/js/nfce-editor.js`: captura pagamentos eletrônicos condicionais e os retém em `old('payments')`; valida CPF/CNPJ e valores. **Bug de `readOnly` indefinido corrigido**. JavaScript melhora UX, mas não substitui regras servidor.
- `app/Http/Controllers/NFCeManualController.php`: valida CPF/CNPJ com `App\\Support\\BrazilianTaxId`, verifica cartão integrado e CNPJ/UF antes de criar venda, normaliza e preserva os oito campos eletrônicos, consulta regras/nomes de meios de pagamento reais cadastrados; não aceita campos de pagamento eletrônico em dinheiro. Ao solicitar emissão usa fila `database/fiscal`, nunca `sync`.
- `app/Services/SalesService.php`: uma única venda com baixa de estoque e financeiro. `app/Services/FiscalPreparationService.php`: snapshot imutável de itens e pagamentos da venda; nova tentativa fiscal não duplica venda.
- `app/Services/Fiscal/NFCePreflightService.php`: configuração emitente, ACBr, tributos e pagamentos; erro impede envio.
- `app/Services/Fiscal/NFCeIniBuilder.php`: mapeamento ACBr, XML fiscal sem inventar autorização.
- `tests/Feature/NFCeManualEditorTest.php`, `tests/Unit/BrazilianTaxIdTest.php`: regressões de campos, ausência de venda inválida e persistência no snapshot.

## Testes que ainda precisam ocorrer

1. Executar `vendor/bin/phpunit --filter=NFCeManualEditorTest`, `vendor/bin/phpunit --filter=BrazilianTaxIdTest`, `vendor/bin/phpunit --filter=NFCeFiscalLifecycleTest`, `vendor/bin/phpunit --filter=PdvFiscalCheckoutFlowTest`.
2. Verificar navegador real: adicionar cartões/PIX/dinheiro, mudar a forma, dividir o pagamento e enviar formulário; abertura de selects dentro do modal com zoom 125%.
3. Testar SEFAZ-BA **em homologação** com certificado A1, CSC/QR Code/ACBr compatíveis, schemas vigentes, XML de venda completa, CFOP/CRT/CSOSN/CST, múltiplos pagamentos, identificador do consumidor, rejeição, reconsulta e impressão DANFE.
4. Somente depois ampliar grupos RTC aplicáveis e cenários tributários de exceção; sem copiar percentuais, cClassTrib ou regras específicas por palpite.
5. Conferir worker `php artisan queue:work database --queue=fiscal --tries=1`, sem transmitir NFC-e real sem autorização.

**Limite da entrega:** interface e validações fortalecidas, mas **não há garantia de conformidade completa para todos os contribuintes, UFs, regimes e situações tributárias até homologação e implementação dos grupos ainda pendentes**.
