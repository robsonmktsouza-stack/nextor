# Política de versões, branches e publicação — Nextor / Lumeron ERP

> **Decisão permanente do proprietário — 20/10/2026.**
> O Nextor/Lumeron entra em uma nova fase: **uma versão estável atendendo clientes e desenvolvimento contínuo de novas funcionalidades, sem interrupção das vendas ou da emissão de NFC-e**.
> Este documento é de leitura obrigatória para todos os agentes de IA e mantenedores. Preservar esta decisão em novos chats, PRs, mudanças de infraestrutura e tarefas fiscais.
> **Esta documentação não publica nada por si só**; não é autorização genérica para alterar produção, emitir documento real, mesclar branches ou executar migrations.

## 1. Regra inegociável

**Desenvolvimento, homologação/validação e produção são ambientes separados.**

- Um `git push`, commit ou `git pull` no Laragon **não pode atualizar automaticamente** o ambiente do cliente.
- O cliente usa o **release específico aprovado e instalado**, não a ponta mutável de uma branch de trabalho.
- Novas funcionalidades, mudanças de layout, ajustes fiscais e refatorações são desenvolvidos e testados fora da produção.
- Uma versão só chega a produção mediante **decisão explícita de publicar**, artefato/commit identificável, plano de implantação e possibilidade real de recuperação.
- É permitido continuar desenvolvendo NF-e, Financeiro, Estoque, PDV e outras funções enquanto a versão de NFC-e em produção permanece intacta.
- Não parar o desenvolvimento apenas porque existem clientes em operação. **Isolar as atualizações**, não congelar o produto.

## 2. Estratégia de branches (política de destino)

| Branch | Função definida | Regra |
| --- | --- | --- |
| `main` | Código correspondente à linha estável **aprovada** para lançamento | Não incorporar desenvolvimento incompleto; não atualizar automaticamente nenhum cliente |
| `develop` | Integração de funcionalidades em desenvolvimento | Nunca apontar deploy de produção para esta branch |
| `feature/*` | Funcionalidades específicas e experimentos | Nascer da linha de desenvolvimento adequada e voltar por revisão/testes |
| `hotfix/*` | Correções urgentes para uma versão já publicada | Partir do commit/tag em produção, validar, lançar patch e reconciliar também com `develop` |

**Estado real em 20/10/2026 (conferir novamente antes de agir):**

- O trabalho funcional mais recente está em `feature/acbr-nfe-bootstrap`, repositório `robsonmktsouza-stack/nextor`.
- A `main` foi **promovida por fast-forward** da linha funcional em 20/10/2026, preservando histórico; é a base estável aprovada em GitHub. Isto não realiza implantação em clientes.
- A branch `develop` **foi criada** a partir da base estável para receber novas funcionalidades; novas tarefas devem usar `develop` ou `feature/*`, não editar `main` diretamente.
- `.github/workflows/acbr-integration-checks.yml` foi atualizado para validar `main`, `develop`, a branch histórica `feature/acbr-nfe-bootstrap`, tags `v*` e PRs, com `composer.lock` exigido. CI comprova testes de código, não prontidão automática de SEFAZ.
- `composer.lock` **foi gerado, validado e versionado** usando PHP 8.3 em GitHub Actions. Publicações usam `composer install` a partir do lock; **nunca `composer update` no servidor do cliente**.
- A produção, os domínios, a infraestrutura real, a estratégia de hospedagem e a primeira tag **não estão declarados como provisionados ou criados** por este documento.
- A primeira promoção da branch funcional para `main` já foi executada com autorização expressa e após comparar histórico e dependências. **Promover futuras alterações só após nova aprovação**; o push em `develop` não atualiza `main` nem clientes.

## 3. Desenvolvimento, validação e produção

### Desenvolvimento

- Ambiente local: Windows + Laragon e banco **de desenvolvimento**; não conectar em banco ou A1/CSC reais de cliente sem autorização específica.
- Commits, experimentos e ajustes continuam nas branches de trabalho.
- Testes de UI, fiscais, banco, regressão comercial e pagamentos são executados aqui/CI com fixtures isoladas.
- `php artisan serve` é **somente para desenvolvimento** (não é serviço de hospedagem para loja/cliente).

### Homologação / staging

- Ambiente separado para receber uma candidata a release e reproduzir a configuração relevante à hospedagem de destino.
- Não compartilhar **banco, certificado, CSC, armazenamento fiscal ou credenciais** de produção para testes. Se for necessário analisar dados, usar material devidamente saneado e autorizado.
- Conferir migrações, permissões, login, PDV, impressão, worker fiscal, armazenamento de XMLs, recuperação de falhas e compatibilidade da ACBr com o **sistema operacional** de destino.
- Não inventar cStat/protocolo da SEFAZ como substituto de autorização real. A decisão do proprietário de iniciar um piloto NFC-e significa avançar para **publicação assistida**, não executar notas reais por iniciativa do agente.

### Produção

- Servidor HTTP persistente e gerenciado (na arquitetura Linux documentada: Nginx + PHP-FPM + banco próprio + worker fiscal systemd por empresa); nunca `php artisan serve`.
- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, permissões restritas, fila fiscal dedicada, logs protegidos e monitorados.
- Processo de publicação **controlado por CNPJ** e não por eventos de desenvolvimento; registrar a versão instalada, data e status da operação.
- Atualizações planejadas em janelas adequadas ao caixa, com reconciliação de jobs/estados NFC-e antes de reiniciar worker. O XML autorizado e dados comerciais permanecem preservados.

## 4. Versões e fluxo de publicação

1. **Escolher a candidata**: identificar o commit preciso da linha funcional atual; não presumir que a `main` velha é equivalente.
2. **Preparar o código reproduzível**: gerar/versionar `composer.lock` em desenvolvimento e fixar versões de bibliotecas; testes automatizados e fiscal/PDV pertinentes aprovados.
3. **Conferir staging**: aplicar a candidata num ambiente separado com as dependências da hospedagem pretendida; verificar migrações e operação real do worker.
4. **Aprovar a versão**: promover o código revisado à linha estável, criar tag imutável (`v1.0.0` para o primeiro release, depois `v1.0.1`/ `v1.1.0` etc.) e registrar notas de versão; usar **tag/commit aprovado**, não HEAD de `develop`, como origem da instalação.
5. **Publicar por cliente**: selecionar **uma instalação/CNPJ**; realizar simulação, backup e execução explícita do deploy. Monitorar login, PDV, fila, NFC-e, XML e DANFE após a atualização.
6. **Manter a versão anterior disponível**: rollback de **código** pode ser feito via release anterior, mas migrations incompatíveis não são desfeitas por trocar symlink; plano de restauração de banco precisa ser separado e testado.
7. **Prosseguir desenvolvimento**: seguir com novas `feature/*` e integração em `develop` sem atingir os clientes na versão anterior.

### Convenção de versão

- `v1.0.0`: candidata ao primeiro lançamento estável quando explicitamente aprovada. **Não criar tag ou declarar release final só por ler esta regra.**
- `v1.0.1`: correção pontual compatível (patch).
- `v1.1.0`: funcionalidade nova compatível (minor).
- Mudança de incompatibilidade contratual de dados/API exige análise própria e plano de migração, possivelmente release major.

### Hotfix urgente

Quando um defeito crítico afetar a venda, emissão/consulta ou segurança de cliente em operação:

1. Registrar versão/commit realmente publicado.
2. Criar `hotfix/*` a partir da **versão afetada**, não de `develop` com mudanças incompletas.
3. Corrigir com escopo mínimo, testes de regressão e verificação de efeitos fiscais.
4. Publicar nova tag de patch somente com autorização; atualizar apenas as instâncias afetadas.
5. Incorporar a correção à linha futura de desenvolvimento para que não seja perdida.

**Proibição:** usar o banco real do cliente para experimentar correções ou transmitir nova NFC-e para “ver se funciona”; primeiro verificar estados e idempotência.

## 5. Instalações por CNPJ (contrato já existente)

**Um repositório e um código-base; uma instalação isolada por CNPJ, cada qual podendo executar uma versão aprovada diferente durante o rollout.**

Cada instalação possui **URL, banco/usuário, dados fiscais, `APP_KEY`, sessão/cache, storage, certificado A1, CSC, XMLs/protocolos, fila/worker e configurações próprios**. Não copiar segredos nem bases de uma empresa para outra. Ver [one-cnpj-per-instance.md](one-cnpj-per-instance.md).

Scripts já existentes:

| Arquivo | Função comprovada pelo código | Observação |
| --- | --- | --- |
| `deploy/instances/provision.sh` | Criar instância isolada (Linux) | Modo padrão é simulação; `--apply` altera infraestrutura |
| `deploy/instances/update.sh` | Preparar release por cliente, backup MySQL, migrações, trocar symlink e reiniciar serviços | Requer `composer.lock`; rollback de DB não é automático |
| `deploy/instances/list.sh` | Inventariar instâncias | Não modifica banco de clientes |

**Scripts existentes não significam VPS já configurada.** Os scripts trabalham com `--source`; o operador deve garantir que essa fonte corresponda ao **commit/tag aprovado**, nunca simplesmente ao último push. Não executar os scripts sem pedido expresso e identificação da instalação/destino.

**Linux e Windows não são equivalentes para o emissor:** a DLL ACBrLibNFe do Laragon/Windows **não roda diretamente no Linux**. VPS Linux requer biblioteca Linux ACBr e dependências próprias; servidor Windows exigiria procedimento de implantação distinto ainda a definir.

## 6. Proteção de dados fiscais, backups e rollback

- Antes da primeira implantação: backup automático **externo/cifrado** e restauração verificada de **banco + storage privado/XMLs + certificados/configurações/APP_KEY**, com acesso restrito.
- Antes de cada atualização: conferir jobs fiscais pendentes, impedir execução concorrente com migrations, garantir janela operacional e snapshot de dados.
- Migrações são preferencialmente **aditivas/compatíveis**; renomear/remover colunas e alterar histórico fiscal exige plano separado e autorização.
- Reverter código **não reverte schema nem dados já alterados**. Não restaurar banco antigo sem avaliar vendas/NFC-e autorizadas desde o backup, para não perder notas ou repetir operações.
- Logs de implantação devem registrar versão e resultado **sem imprimir A1, senhas, CSC, dados privados ou XMLs de clientes**.
- Em erro fiscal de resposta incerta: consultar pela chave e reconciliar protocolo/estado antes de retransmitir; jamais gerar segunda venda para tentar recuperar nota.

## 7. Pendências organizacionais antes do primeiro deploy

Estas são tarefas de **preparação de publicação**, não autorização para uma rodada infinita de testes de NFC-e já feitos:

- [ ] Definir hospedagem real (VPS Linux, Windows ou servidor local), domínio/HTTPS e responsáveis pelo suporte.
- [x] Reconciliar `main` com a linha funcional aprovada e criar `develop`, mantendo todo o histórico; futuras promoções continuam controladas.
- [x] Gerar, versionar e validar `composer.lock` em GitHub Actions PHP 8.3.
- [x] Estender verificações do GitHub Actions às branches de integração/estável; aprovar cada novo lançamento individualmente.
- [ ] Criar tag `v1.0.0` **somente após aceite do proprietário** e registrar manifesto por CNPJ (versão, SHA, data, ambiente).
- [ ] Preparar staging e procedimento de deploy/rollback próprio da hospedagem escolhida.
- [ ] Implantar backup externo, teste de restauração, worker fiscal gerenciado, monitoramento e recuperação operacional.
- [ ] Efetuar primeiro piloto com acompanhamento e autorização expressa do proprietário; não declarar que cliente está no ar antes de evidência verificável.

## 8. Instruções expressas para agentes de IA

**Em toda nova tarefa:**

1. Ler `AGENTS.md`, `docs/ai/README.md`, `docs/ai/ARCHITECTURE_AND_DECISIONS.md`, este documento e o `docs/ai/HANDOFF.md`.
2. Identificar **branch real, commit, e se a tarefa envolve desenvolvimento, staging ou produção**; não adivinhar qual ambiente está sendo alterado.
3. Por padrão, **alterar somente a branch de desenvolvimento explicitamente indicada**. Não tocar na `main`, tags, DNS, VPS, banco ou instância real sem instrução específica.
4. Antes de alterar fluxo fiscal, considerar quais **releases já publicados** precisam continuar compatíveis com schema, jobs, dados e emissão.
5. Reportar honestamente testes executados e limites: CI não é autorização SEFAZ; script de deploy não é implantação efetuada.
6. Atualizar `docs/ai/HANDOFF.md` ao concluir, além deste documento caso o proprietário altere a política.
7. Se não houver confirmação de onde hospedar, **não supor Linux ou Windows** e não executar provisionamento.

**Decisão do proprietário:** seguir em frente com um **piloto controlado de NFC-e** e continuar desenvolvendo o produto em paralelo. Não reinterpretar isto como “pausar novos módulos até nova homologação completa”, nem como “produção já implantada”.
