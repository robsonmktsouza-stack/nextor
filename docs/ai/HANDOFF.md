# Handoff entre chats e agentes — Lumeron ERP

Este documento deve ficar **curto, verificável e atualizado a cada tarefa**. É a primeira parada ao retomar o projeto depois dos arquivos de entrada. Quando desatualizado, conferir o código e corrigir, não repetir o texto como fato.

## Registro ativo — 19/10/2026

**Tarefa:** registrar orientação persistente para continuidade do desenvolvimento com IAs.
**Repositório:** `robsonmktsouza-stack/nextor`.
**Branch de trabalho usada para a documentação:** `feature/acbr-nfe-bootstrap`.
**Alterações desta tarefa:** `AGENTS.md`, `CLAUDE.md`, `.github/copilot-instructions.md`, `docs/ai/README.md`, `docs/ai/PROJECT_STATE.md`, `docs/ai/ARCHITECTURE_AND_DECISIONS.md`, este arquivo e link no `README.md`.
**Verificação feita:** arquivos e rotas relevantes inspecionados no GitHub, incluindo `composer.json`, código ACBr/NFC-e, testes de login, identidade, ciclo fiscal e implantação por CNPJ.
**Testes executados nesta tarefa:** nenhum teste PHP, banco de dados, interface ou SEFAZ; alteração documental.
**Estado:** documentação inicial registrada. O próprio código e os testes continuam sendo a fonte de verdade.
**Próximo foco técnico sugerido (não é ordem de execução):** revisar e validar de ponta a ponta NFC-e BA via ACBr, especialmente rejeição/correção/consulta, eventos, integridade comercial, contingência e impressão. Confirmar prioridade com solicitação atual.
**Pendência conhecida:** `composer.lock` não estava versionado em 19/10/2026; implantação comercial exige validações adicionais, não é tratada como concluída.

## Checklist de conclusão obrigatório para próximas tarefas

Preencha ou substitua o registro ativo a cada entrega que altera código, infraestrutura, regras de negócio ou design:

- **Data e branch/commit:** data real, referência do commit, eventual PR.
- **Pedido/objetivo:** uma frase.
- **O que foi alterado:** arquivos, rotas, tabelas, serviços e impactos em outros módulos.
- **Decisões tomadas:** registrar em `ARCHITECTURE_AND_DECISIONS.md` se for nova política; não inventar aprovação.
- **Testes executados e resultado:** comando e PASS/FAIL, com evidência. Se não executados, dizer por quê.
- **Pendências e riscos:** comportamentos não verificados, dependências externas, migrações ou bloqueios.
- **Próximo passo recomendado:** uma ação precisa, sem expandir escopo por conta própria.

## Como iniciar o próximo atendimento

1. Confirmar a branch e se há mudanças locais; não descartar trabalho em andamento.
2. Ler `AGENTS.md`, `docs/ai/README.md`, `PROJECT_STATE.md` e este registro.
3. Conferir as últimas alterações com `git log -5 --oneline` e os arquivos do módulo solicitado.
4. Trabalhar somente no escopo da solicitação atual, mantendo compatibilidade e testes.
5. Registrar a entrega neste arquivo e corrigir as seções desatualizadas dos outros documentos.

**Nunca** preencher um teste como aprovado sem executá-lo; nunca chamar um protótipo fiscal de pronto para produção.
