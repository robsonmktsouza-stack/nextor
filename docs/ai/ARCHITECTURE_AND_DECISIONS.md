# Arquitetura, contratos e decisões aprovadas — Lumeron

Referência: código e documentação da branch `feature/acbr-nfe-bootstrap` conferidos em 19/10/2026. Decisões novas explícitas do proprietário substituem estas; registrem a mudança e seus impactos.

## D01 — Identidade e compatibilidade

O produto se chama **Lumeron ERP** (antigo Nextor). O repositório Laravel continua `robsonmktsouza-stack/nextor` e tem nomes técnicos legados. Não executar renomeação massiva que quebre migrações, classes, cookies, filas, integrações ou históricos. A API `/api/lumeron` convive com o alias `/api/nextor`. Não misturar com `robsonmktsouza-stack/lumeronerp`, protótipo WPF desktop separado.

A marca oficial utiliza `public/images/lumeron/logo.png` (logo completa) e `public/images/lumeron/elephant.png` (elefante isolado). Reutilizar os arquivos existentes; não regenerar/reinterpretar a logo. Não colocar a marca do ERP no lugar da identificação legal do emitente nos documentos fiscais.

## D02 — Produto e interface

ERP web em Laravel/Blade/CSS/JavaScript; frontend atual sem dependência necessária de build Node no README. Preservar as experiências de painel administrativo e PDV dedicado. O PDV usa `resources/views/layouts/pdv.blade.php`, **sem herdar o sidebar administrativo**; painel usa os layouts convencionais. Estilo compartilhado em `public/css/erp.css`, `public/css/ui-refinement.css`, `public/css/branding.css` e folhas específicas do PDV. Respeitar padrões de tipografia, cores, densidade de tabela, controles e responsividade já implantados; não aplicar redesign ou framework novo sem pedido. Conferir `UiRefinementTest` e `LumeronBrandingTest`.

## D02-A — Tipografia do ERP (definida em 19/10/2026)

O proprietário rejeitou a Segoe UI e a escala tipográfica anterior (muitos rótulos de 9–11 px). **Usar Roboto em toda a interface**: administrativo, Financeiro, tabelas, cadastros, Fiscal, PDV, login e recuperação de senha. A família está definida em `public/css/erp-typography.css` e é carregada pelas views do layout com Google Fonts (pesos 400/500/600/700/800); `Arial, Helvetica, sans-serif` são o fallback offline quando o serviço externo não está disponível. Os arquivos de base `public/css/erp.css`, `public/css/pdv.css`, `public/css/pdv-lumeron.css` e `public/css/login.css` também foram alinhados, sem referências a Segoe/Open Sans.

Padrão de legibilidade: texto geral **14 px**, registros em tabelas **13 px**, títulos de coluna **12 px**, rótulos **13 px**, descrições secundárias **12–12,5 px**. Mantenha a densidade operacional e adapte largura/rolagem, sem voltar a minúsculos textos nem recorrer a zoom global. A folha `erp-typography.css` deve carregar **após** `desktop-polish.css` e `branding.css`, sem afetar os arquivos do DANFE ou impressão fiscal. Ao modificar tipografia, manter `tests/Feature/ErpTypographyTest.php`.

## D02-B — Configurações compreensíveis (19/10/2026)

**Decisão de produto:** termos técnicos não devem dominar a navegação de uso diário. Os controles devem dizer o que o usuário quer fazer ou o que acontece ao ativá-los. A referência funcional são práticas publicamente documentadas por eGestor, Omie, Bling e Conta Azul, sem replicar material proprietário. Guia e links: [docs/ux/erp-settings-language.md](../ux/erp-settings-language.md).

Em `resources/views/settings/index.blade.php`, manter os seis grupos principais **Minha empresa; Vendas e estoque; Financeiro; Notas fiscais; Equipe e acessos; Outras opções** e as subabas organizadas por tarefa. Os 20 valores internos de `SettingsController::TABS`, rotas `?tab=...`, actions, nomes de input, validações e permissões são contratos e não podem ser trocados apenas para simplificar a linguagem visível. A UI explica termos como CRT, IBGE, CSC, NF-e, NCM, RPS sem omiti-los quando são necessários. As configurações de API/webhook/SMTP ficam em **Outras opções → Integrações avançadas**; não esconder acesso administrativo ou alterar segurança.

**Padrão para botões:** verbo + objeto quando houver ambiguidade (ex.: “Adicionar categoria”, “Adicionar conta”, “Adicionar forma de pagamento”, “Gerar arquivo contábil”). “Salvar” e “Cancelar” continuam adequados quando o contexto já está claro. Não prometer emissão, envio, baixa ou download quando o fluxo de código não faz isso. Atalhos e atributos `data-*` não devem ser alterados por mudanças de texto. Seções com opções fiscais de risco devem distinguir homologação/teste de produção real. O arquivo `tests/Feature/SettingsClarityTest.php` cobre navegação e compatibilidade.

## D02-C — Seletores sem recorte em formulários e modais (19/10/2026)

**Decisão visual:** todo `<select>` convencional deve continuar usando o componente global do Lumeron em `public/js/erp.js`. O `<select>` original continua no formulário, enviando os valores e acionando `change`. A lista personalizada é criada e controlada pelo componente; não instalar Select2/Choices/Tom Select por iniciativa da IA nem substituir dezenas de campos por bibliotecas distintas.

**Corrigido:** o menu da lista é exibido na **top layer** pelo Popover API (`popover="manual"`) sem mudar sua estrutura DOM. O posicionamento é calculado a partir do campo e do viewport; a lista abre para cima quando necessário, considera o rodapé fixo das Configurações e dos modais, e se reposiciona com zoom/rolagem. O popup deixa de ser cortado por cartões, `overflow`, `z-index` e containers roláveis. Ao selecionar ou fechar, deve executar `hidePopover`; nunca deixar menus órfãos no alto da tela. O campo pesquisável não deve selecionar automaticamente todo seu texto no foco (evita o destaque azul observado em Configurações → CRT).

**CSS:** `public/css/desktop-polish.css` possui regras restritas a `.ui-select .ui-select-menu[popover]`. **Não** corrigir esse problema globalmente com `overflow:visible!important` em todos os modais: isso quebraria rolagem, barras fixas e clipping legítimo do ERP. Para browser sem Popover API, o componente ainda utiliza seu modo anterior de posicionamento.

**Qualidade:** `tests/Feature/SelectOverlayTest.php` protege a integração, mas somente testes reais no Chrome do Laragon, inclusive campos CRT, tabelas, rodapés, modais e zoom 125%, confirmam a correção visual. Não declarar essa QA concluída sem observá-la.

## D03 — Isolamento contratual

**Um CNPJ = uma instalação Laravel independente = um banco e ambiente próprios**, mesmo se uma pessoa tiver várias empresas. Cada instalação possui usuários, sessões, APP_KEY, storage, certificados A1, CSC, fila/worker e configurações próprios. Não criar seletor multiempresa compartilhado nem banco único com tenant_id. `config/instance.php` define `LUMERON_INSTANCE_CNPJ` e identidade de instância; preflight/transmissão devem rejeitar emitente CNPJ divergente. Desenvolvimento local pode operar sem vínculo de CNPJ; VPS gerenciada não pode. Documentos: `docs/deployment/one-cnpj-per-instance.md`, `docs/deployment/login-by-subdomain.md`.

## D04 — Autenticação e segurança

Login por subdomínio + usuário + senha, controle de permissões por módulo, recuperação de senha por e-mail da instalação. Portal central é **planejado/não implantado**, não autentica com banco comum e não recebe senhas. Toda rota sensível deve checar autenticação, permissão e identidade da instalação. Nunca versionar `.env`, APP_KEY, CSC, senha de certificado, PFX/A1, documentos de cliente, XMLs privados ou dados reais; nunca imprimir tais segredos em logs/testes. Rejeitar IDs cruzados e proteger ações destrutivas com autorização, validação e transações.

## D05 — Comercial, estoque e financeiro

Uma venda/PDV concluída é a referência do fato comercial. `SalesService` coordena a operação; conferir serviços de estoque, financeiro, `FiscalPreparationService` e relações antes de alterar fluxos. Uma tentativa fiscal rejeitada/corrigida **não cria outra venda, cobrança ou baixa de estoque**. Transações SQL, locks, idempotência e rastreabilidade devem ser preservados; devoluções/cancelamentos exigem regras comerciais e fiscais distintas.

## D06 — Motor fiscal atual (substitui proposta antiga)

**Motor vigente: ACBrLibNFe via PHP FFI, com serviços próprios do Lumeron ao redor dela**. O antigo plano de motor NFC-e completamente próprio foi substituído; há protótipo histórico arquivado em `archive/nextor-native-nfce-2026-10-02.zip`. Não recriar um emissor paralelo nem migrar para serviço/API paga ou NFePHP por iniciativa da IA. Serviço principal da biblioteca: `app/Services/Fiscal/ACBr/ACBrNFeService.php` (ACBr MT/Cdecl x64 no Windows). Biblioteca Linux e dependências devem ser configuradas separadamente na futura VPS; DLL Windows não é a versão Linux. Isolar instância/configuração ACBr por processo/documento, sem reusar handle global concorrente.

Prioridade prática atual: **NFC-e modelo 65**, com foco de validação na **Bahia (BA)**. O código de preflight contém checagens específicas para BA: não declarar suporte a todas as UFs por analogia. NF-e 55, NFS-e, CT-e e MDF-e podem ter interface ou trabalho parcial; implementar/considerar completos somente com evidência.

## D06-A — Encerramento de venda no PDV e impressão fiscal (19/10/2026)

**Decisão operacional:** finalizar uma venda no PDV **não abre automaticamente o módulo Fiscal/NFC-e**. O operador permanece na frente de caixa após a impressão/cancelamento do comprovante interno. O link para revisão fiscal é explícito e sujeito a permissões. `resources/views/pdv/receipt.blade.php` usa sempre `route('pdv.index')` como destino do evento de fim da impressão. A situação da NFC-e fica visível no próprio PDV, com mensagens distintas para preparada, processamento, pendência de consulta, rejeição, autorizada e contingência; não declarar autorização sem protocolo e XML autorizado. Uma venda já concluída nunca deve ser registrada novamente para corrigir nota.

**NFC-e/ACBr:** emissão automática configurada é enfileirada na conexão `database`, independentemente da conexão padrão Laravel. A fila `fiscal` necessita worker efetivo; não enviar FFI/ACBr na requisição da venda. O recebimento de sucesso HTTP não significa autorização pela SEFAZ.

**Dados do pagamento eletrônico:** no INI, `CNPJPag/UFPag` representam local da transação, `CNPJ` é adquirente, `CNPJReceb` é beneficiário e `idTermPag` é terminal. Para cartão, exigir tipo de integração TEF/POS informado e, quando integrado, adquirente e código de autorização; nunca presumir `tpIntegra=2`. Dados de PIX são aceitos e mapeados quando informados. As regras completas de PIX e as NT vigentes da BA continuam dependendo de homologação assistida; **não afirmar que o modelo 65 está liberado para produção**.

**Impressão:** comprovante interno de venda (SEM VALOR FISCAL) e DANFE são coisas diferentes. O PDV somente fornece DANFE autorizado quando há XML válido arquivado; contingência offline assinada recebe DANFE marcado como pendente de autorização e **duas vias** (consumidor e estabelecimento). Nenhuma impressão interna substitui o DANFE. A regulamentação e checklist ficam em `docs/fiscal/pdv-nfce-bahia.md`; regressão em `tests/Feature/PdvFiscalCheckoutFlowTest.php`.

## D07 — Integridade dos documentos fiscais

Nunca fabricar QR Code, chave, autorização, cStat, recibo ou protocolo. Seguir schemas e documentação oficial vigentes para o cenário/UF/ambiente. Exigir configurações do emitente, CRT, CFOP/CSOSN/CST, NCM, regras tributárias, pagamentos, certificado e CSC válidos conforme o caso; cálculos monetários devem ser exatos. Fiscal prepara dados a partir da operação comercial, assina XML, valida XSD e conserva XMLs/protocolos sem exposição pública. Não confundir `prepared`, `processing`, `rejected`, `authorized`, `offline_signed`, `offline_sending` e outros estados; verificar código atual antes de ampliar transições. Nunca reenviar às cegas depois de timeout: consultar e reconciliar o estado primeiro. Correção de rejeição preserva vínculo e histórico da venda; só uma NFC-e autorizada/ativa para a mesma venda. Cancelamento, inutilização e contingência precisam das pré-condições legais e rastreio de eventos. Contingência offline depende de condição real de falha e não significa autorização SEFAZ.

## D08 — Implantação e mudanças de banco

Não implantar em produção, alterar DNS, certificados, infraestrutura, cobranças ou dados de clientes sem pedido expresso. Scripts `deploy/instances/` são material de preparação com dry-run. Rodar migrations primeiro em base descartável ou backup restaurável, evitar mutações destrutivas e não inventar credenciais. `composer.lock` faltava no momento da inspeção e é requisito antes de provisionar clientes. Só divulgar ambiente como pronto após testes automatizados e homologação assistida.

## D09 — Política de manutenção para agentes

Antes de cada tarefa: ler `AGENTS.md`, `docs/ai/README.md`, `PROJECT_STATE.md`, `HANDOFF.md` e os arquivos afetados. Preferir mudanças mínimas, aderentes ao código e cobrindo regressões. Depois: atualizar o handoff, marcar resultado real de testes e registrar nova decisão neste documento se houver mudança aprovada. Não reivindicar que arquivos de IA têm memória automática entre conversas; eles são referência versionada que cada novo agente precisa carregar.
