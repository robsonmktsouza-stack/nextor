# Lumeron ERP — ponto de entrada para IAs

> Leia este arquivo antes de propor ou alterar código. Atualizado em 19/10/2026.
> **Projeto ativo:** `robsonmktsouza-stack/nextor`; `main` contém a linha estável e `develop` é a branch padrão para novos recursos. `feature/acbr-nfe-bootstrap` é a origem histórica da primeira promoção.
> **Decisão de 20/10/2026:** avançar para um piloto NFC-e com versão estável isolada, enquanto o desenvolvimento continua. **Nenhum agente pode implantar automaticamente a partir de pushes.** Ver a [política de publicação](../deployment/versioning-and-release-policy.md).
> **Produto:** Lumeron ERP (antigo Nextor). Não confundir com o repositório separado `lumeronerp` (protótipo desktop WPF).

## Como assumir o desenvolvimento em qualquer novo chat/agente

1. Abrir o repositório e conferir a branch/commit atuais com `git status -sb`, `git branch --show-current` e `git log -5 --oneline`. Não confiar apenas no nome do chat.
2. Ler `/AGENTS.md`, este índice, `PROJECT_STATE.md`, `ARCHITECTURE_AND_DECISIONS.md`, **`docs/deployment/versioning-and-release-policy.md`** e `HANDOFF.md`.
3. Ler `/README.md`, além dos documentos específicos do módulo afetado; comparar as afirmações com o **código atual**.
4. Antes de programar, identificar componentes existentes, impactos em banco, estoque, vendas, financeiro, emissões fiscais e permissões. Corrigir o fluxo real, não criar uma segunda implementação paralela.
5. Após cada mudança, registrar o resumo verificável, arquivos alterados, testes executados (ou não), pendências e próximo passo em `HANDOFF.md`. Atualizar as decisões se alguma for aprovada ou substituída.
6. Não dizer que está pronto, homologado ou em produção sem evidência observável e testes pertinentes. Não publicar na VPS sem autorização do proprietário.

## Fonte de verdade (em ordem)

1. Solicitação atual do proprietário + decisões explícitas mais recentes.
2. Código-fonte e testes da **branch efetivamente aberta**.
3. `AGENTS.md` e documentos de `docs/ai/`.
4. Documentação técnica e histórico antigos. Quando houver conflito, sinalizar, verificar e atualizar os arquivos em vez de repetir pressupostos desatualizados.

Documentos de referência existentes:
- [README principal](../../README.md)
- [Isolamento por CNPJ](../deployment/one-cnpj-per-instance.md)
- [Política permanente de branches, releases e produção isolada](../deployment/versioning-and-release-policy.md)
- [Login por subdomínio](../deployment/login-by-subdomain.md)
- [Estado verificado](PROJECT_STATE.md)
- [Arquitetura e decisões](ARCHITECTURE_AND_DECISIONS.md)
- [Passagem de bastão](HANDOFF.md)

## Para usar em outro chat do ChatGPT

Conecte/forneça acesso ao repositório e peça: **“Continue o Lumeron ERP a partir de `robsonmktsouza-stack/nextor`, branch `develop` para novas funcionalidades. Leia `AGENTS.md`, `docs/ai/README.md` e `docs/deployment/versioning-and-release-policy.md` antes de alterar qualquer coisa; confira o código e atualize `docs/ai/HANDOFF.md` ao terminar. Não publicar a `main` sem aprovação.”**

Esses arquivos persistem no GitHub, mas **não entram automaticamente no contexto de todos os chats**: a nova sessão precisa abrir o repositório/arquivos. Ferramentas que reconhecem `AGENTS.md`, `CLAUDE.md` ou instruções do Copilot poderão usá-los quando estiverem operando sobre esta cópia do código.
