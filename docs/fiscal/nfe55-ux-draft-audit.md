# NF-e: auditoria funcional do editor

Base de observação: capturas do NFe+ e eGestor fornecidas pelo usuário e inspeção das rotas e componentes do Lumeron. Não inclui acesso aos sistemas internos de terceiros.

## Comparação de fluxos

| Aspecto | Referência observável | Regra para o Lumeron |
| --- | --- | --- |
| Emissão | Formulário contínuo organizado em dados, produtos, transporte e pagamento | Seções com separadores leves, sem caixas aninhadas |
| Busca | Lista compacta sob cliente e produto, opção de cadastrar quando necessário | Busca no campo com cadastro contextual |
| Edição do item | Detalhes comerciais seguidos de tributação | Mostrar o essencial; manter dados específicos recolhíveis |
| Rascunho | Salvar separado de transmitir | Permitir dados incompletos sem reservar número |
| Validação | Conferência antes do envio | Exigir regras fiscais completas só ao validar e emitir |

## Falhas encontradas no Lumeron

- Salvar exigia os mesmos campos obrigatórios de uma nota pronta. Impedia rascunhos parciais.
- O editor reconstruía dados a partir de tabelas normalizadas e descartava informação incompleta.
- Selecionar produto em janela contextual podia apagar frete, descontos, pedido, observações e ajustes de tributação.
- Cartões e molduras sobrepostos poluíam o formulário.
- A validação estrutural podia ser mais permissiva do que o emissor de XML.

## Implementação

- O editor envia `draft_action=draft`. Salvar guarda o formulário e os itens mesmo quando ainda estão incompletos.
- Dados completos continuam sendo gravados nas tabelas fiscais; o snapshot garante a recuperação dos campos incompletos.
- Validar salva antes de verificar a nota. Nunca define `validated_at` se as regras do gerador fiscal rejeitarem a operação.
- O seletor de produto preserva edições do mesmo item e valores manuais.
- O CSS da NF-e foi simplificado mantendo a tipografia e os controles Lumeron.

## Aceitação manual obrigatória

1. Salvar sem cliente ou natureza. Sair e reabrir: todos os dados permanecem.
2. Adicionar um item, alterar frete e desconto e selecionar produto novamente: alterações não desaparecem.
3. Preencher pagamento e observações, salvar e reabrir: valores são idênticos.
4. Tentar validar operação ou tributação não disponível: permanece rascunho.
5. Validar uma venda normal, interna e compatível: só então habilitar emissão.
6. Verificar emissão em homologação na própria interface e conferir resposta da SEFAZ.

A auditoria funcional não constitui homologação completa de todos os cenários tributários da NF-e.
