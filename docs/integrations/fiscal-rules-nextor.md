# NEXTOR — Regras fiscais (primeira etapa)

## Referência funcional e limites

O eGestor é uma referência para separar **natureza de operação**, **classificação de produtos** e **parâmetros fiscais**. O NEXTOR já possui naturezas de operação da NF-e e configurações tributárias globais; esta implementação adiciona regras próprias para NFC-e, sem duplicar o cadastro já existente.

A primeira versão é **deliberadamente restrita** a NFC-e (65), saída interna BA → BA, CRT 1 (Simples Nacional). Não contém uma base legal pronta nem calcula automaticamente ICMS-ST, DIFAL, IPI, IBS, CBS, monofásicos ou tratamentos especiais. A atribuição de CFOP/CST/CSOSN exige revisão contábil.

## Fluxo

1. Após executar migrations, acesse **Configurações → Tributação → Gerenciar regras fiscais**, ou **Fiscal → NFC-e → Regras fiscais**.
2. Cadastre regras específicas por produto ou prefixo NCM; é possível cadastrar regra genérica, porém ela só deve ser ativada após revisão.
3. Configure prioridade e datas de vigência, ative as regras aprovadas.
4. Ative o modo **Exigir regras fiscais** na tela de regras. Esse modo inicia **desativado** e não altera as notas já emitidas.
5. Uma venda concluída cria uma NFC-e preparada pelo fluxo anterior. Na tela da nota preparada, use **Aplicar regras fiscais** antes de transmitir.
6. O serviço valida o contexto, encontra a regra válida para cada item e congela CFOP/CSOSN/CST e identificador/revisão no snapshot. Ele não altera venda, estoque, financeiro, número, série ou valores.
7. Enquanto esse modo estiver ativo, a pré-validação **bloqueia** NFC-e preparada sem regra aplicada. Conflitos de prioridade também bloqueiam a aplicação.
8. A NFC-e inicial aceita CFOP 5xxx, CSOSN 102 e PIS/COFINS 49; regras de outros enquadramentos só poderão ser aplicadas depois que o construtor INI, o cálculo e os testes desses grupos estiverem implementados.

Nenhuma regra é populada automaticamente ou presume que todo produto esteja enquadrado no CSOSN 102.

## Critérios e precedência

- A resolução exige modelo NFC-e, UF de origem e destino, CRT, regra ativa, datas de vigência e, se especificados, identificação do produto e prefixo de NCM.
- Regra específica para produto prevalece sobre NCM; regra com NCM mais específico prevalece sobre regra genérica; prioridade desempata dentro da mesma categoria.
- Se duas regras elegíveis tiverem a mesma pontuação final, a aplicação falha de forma explícita. Não se escolhe uma classificação aleatoriamente.
- Cada edição incrementa a revisão. Somente documentos preparados e ainda sem XML/assinatura podem receber outro snapshot; notas autorizadas não são alteradas.

## Próximas etapas

- Associar regras às naturezas de operação de NF-e existentes e ao tipo de destinatário; estender cobertura por UF/regime e por operações.
- Construir os grupos de cálculo e XML faltantes, com fórmulas parametrizadas e testes por enquadramento, evitando bases e alíquotas genéricas.
- Acrescentar simulação em lote de produtos, histórico de quem aprovou cada regra e exportação para conferência.
- Revisar os fluxos e formulários com capturas do eGestor fornecidas pelo operador — documentação pública do eGestor não reproduz toda a navegação interna.


## Grupos de tributação — estrutura inspirada no eGestor

O NEXTOR adiciona **Configurações → Tributação → Grupos de tributação**.

### Cadastro

- Grupo de produtos ou serviços; ativo/inativo e, opcionalmente, um único padrão por tipo.
- CFOP base literal (por exemplo, 5102) ou prefixo parametrizado (por exemplo, x102). A tradução de x102 para 5102 **só ocorre** na NFC-e interna da Bahia já suportada.
- ICMS: CSOSN geral e alternativa específica para NFC-e, CST regime normal, campos de alíquota, crédito e ST.
- PIS/COFINS, IPI, ISS, IS e IBS/CBS possuem campos específicos de código e percentual. **Cadastrar é diferente de calcular ou transmitir**: os campos ainda não suportados pelo emissor são bloqueados se forem utilizados como regra de emissão, não descartados silenciosamente.
- Histórico por revisão numérica. Atualizar o cadastro não muda documentos já preparados/assinados, exceto por reaplicação explícita de snapshot **somente** antes de assinar/transmitir.

### Produtos e serviços

- No cadastro do produto/serviço, aba Dados fiscais, selecione um grupo existente. A associação usa `fiscal_tax_group_id` com chave estrangeira; o antigo campo livre `tax_group` permanece no banco para não perder dados legados.
- Sem vínculo explícito: primeiro verifica a regra específica por produto/NCM, depois o grupo padrão ativo de produtos (quando houver).
- Com grupo explícito: não substitui silenciosamente o grupo pela regra genérica. O grupo inativo ou incompatível bloqueia a aplicação.
- O grupo de serviços é cadastro preparatório: ele **não autoriza automaticamente** a NFS-e nem muda os cálculos atuais de serviços.

### NFC-e

O modo **Exigir regras fiscais** continua desativado por padrão. Quando ativado, antes da emissão é necessário abrir o documento preparado e acionar **Aplicar regras fiscais**. O snapshot da NFC-e recebe a identificação e revisão do grupo, mais CFOP, CSOSN e CST efetivamente utilizados. Nenhum cálculo comercial, venda, estoque, financeiro, série ou número muda.

A primeira etapa mantém a emissão validada da BA no Simples Nacional: CFOP 5xxx, CSOSN 102 e CST 49 para PIS/COFINS. Grupo com outros códigos ou dados de tributos que exigiriam cálculos/emitiriam grupos XML ausentes não pode ser aplicado neste emissor.

### Instalação e validação

Depois de atualizar a branch:

```bat
git pull
php artisan migrate
php artisan optimize:clear
vendor\bin\phpunit --filter "NFCe|FiscalTaxRule|FiscalTaxGroup"
```

Testar o cadastro e a aplicação somente com notas preparadas, sem gerar outra venda de PDV exclusivamente para isso. Se desejar testar transmissão de um novo perfil fiscal, usar cenário homologado, cadastro revisado e não reutilizar a chave de uma nota já autorizada.

### Próximas etapas, ainda não implementadas

- **Cálculos e aplicação** das variações por UF/município, FCP por UF/NCM, ANP e tratamentos específicos. Os formulários e armazenamento destas configurações já existem.
- Motor de cálculo por CST/CSOSN, incluindo ICMS-ST, PIS/COFINS não zerados e reforma tributária, com testes de totais.
- Integração da mesma classificação a NF-e interestadual e NFS-e, respeitando os respectivos modelos e legislações.
- Simulador de regras por produto/lote, aprovação por usuário e registro de fundamento por período.

As imagens do eGestor servem como referência de organização e variações; não são fonte de alíquotas ou de enquadramento fiscal.


## Conferência de cobertura da referência eGestor — outubro/2026

O formulário de grupos de tributação agora inclui, como **configurações editáveis**:

- **Geral:** tipo e descrição do grupo, cBenef/benefício fiscal da UF, CFOP `x102`, CFOP fixo para UF diferente e opção de forçá-lo.
- **ICMS:** CST/CSOSN, alternativa para NFC-e, crédito Simples, alíquota ICMS, ST/MVA e diferimento FCP.
- **IPI:** CST e alíquota.
- **PIS/COFINS:** CST, alíquotas, tipo de cálculo (porcentagem/quantidade/não usar), tipo de cálculo ST e alíquotas relacionadas.
- **IS:** CST, classificação tributária e alíquota.
- **IBS/CBS:** CST, classificação, CBS/IBS UF/IBS municipal com alíquota, diferimento e redução separados; variações repetíveis por código IBGE municipal e por UF de destino.
- **Combustíveis/ANP:** código e descrição ANP, percentual de mistura de biodiesel, indicador de origem, UF produtor/importador e percentual de origem.
- **Serviços:** exigibilidade de ISS, incentivo fiscal e alíquota ISS.
- **FCP:** página separada `/fiscal/fcp` com regras por UF, NCM, alíquota, vigência, situação ativa e “usar também no FCP próprio”.

**Não significam cálculos concluídos.** Fora da classificação já suportada pela NFC-e BA/Simples, qualquer código ou percentual que requeira emissão diferente deve ser bloqueado. FCP ativo aplicável à UF/NCM bloqueia aplicação das regras até que se implemente o cálculo correspondente. Exceções interestaduais não são executadas na NFC-e interna.

A referência eGestor serviu apenas para a cobertura de campos e a organização funcional; não foram copiados padrões de alíquotas, códigos tributários, nem assumidas regras legais.
