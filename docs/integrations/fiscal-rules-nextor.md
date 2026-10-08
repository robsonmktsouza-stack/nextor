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
