# Lumeron ERP — linguagem simples e configurações por tarefa

**Referência de pesquisa:** 19/10/2026. O estudo usa **materiais públicos oficiais** de eGestor, Omie, Bling e Conta Azul Pro. Não constitui acesso ao painel privado desses fornecedores nem cópia de código, telas protegidas ou fluxos de conta autenticada.

## Referências usadas (fontes oficiais)

- eGestor — [funcionalidades](https://egestor.com.br/funcionalidades.php) e [controle de estoque](https://egestor.com.br/recursos/controle-de-estoque-online.php): apresenta tarefas integradas como fazer venda, movimentar estoque, registrar recebimentos e emitir nota.
- Omie — [principais configurações](https://ajuda.omie.com.br/pt-BR/articles/9762562-principais-configuracoes-da-omie) e [configurações iniciais](https://ajuda.omie.com.br/pt-BR/articles/8637701-primeiros-passos-omie-2-configuracoes-iniciais): organiza preferências por áreas do negócio e explica para que servem.
- Bling — [configurações de NF-e](https://ajuda.bling.com.br/hc/pt-br/articles/14874030705175-Como-configurar-campos-obrigat%C3%B3rios-no-preenchimento-das-notas-fiscais): usa títulos de tarefa como “Configurações de NF-e” e “Configurações de preenchimento”.
- Conta Azul Pro — [primeiros passos para vender online](https://ajuda.contaazul.com/hc/pt-br/articles/46751252988173-Primeiros-passos-como-configurar-a-Conta-Azul-Pro-para-vender-online): introduz “Dados da empresa”, “Certificado digital”, “Usuários”, “Categorias financeiras” e “Contas financeiras” conforme o usuário prepara a operação.

A decisão do Lumeron não é replicar produto concorrente, mas adotar **linguagem orientada a resultado** e usar termos fiscais legais quando necessários.

## Organização aprovada no Lumeron

A implementação está em `resources/views/settings/index.blade.php`. Permanecem as 20 abas internas da controller `SettingsController::TABS`; somente sua navegação visível foi agrupada.

| Grupo visível | Abas internas preservadas | Exemplos de rótulos |
| --- | --- | --- |
| **Minha empresa** | `general`, `printing`, `catalog` | Dados da empresa; Logotipo e impressão; Produtos e serviços |
| **Vendas e estoque** | `operations`, `inventory`, `pdv` | Vendas; Estoque; Frente de caixa (PDV) |
| **Financeiro** | `chart`, `accounts`, `payments`, `billing`, `accounting` | Categorias financeiras; Caixa e bancos; Formas de pagamento; Cobranças e PIX; Contabilidade |
| **Notas fiscais** | `fiscal`, `nfe`, `nfce`, `nfse`, `cte`, `tax` | Informações fiscais; Nota de produto; Nota do consumidor; Nota de serviço; Transporte; Impostos e tributação |
| **Equipe e acessos** | `users` | Cadastro, funções e áreas permitidas |
| **Outras opções** | `system`, `integrations` | Aparência e preferências; Integrações avançadas |

As URLs antigas `/settings?tab=...`, actions POST, nomes dos inputs, permissões e dados salvos não mudam. O primeiro item de cada grupo é a página de entrada; por exemplo, “Financeiro” abre `?tab=chart` e “Outras opções” abre `?tab=system`. Os outros itens são subabas com rolagem horizontal. Todo grupo informa em frase curta quais tarefas cobre.

## Glossário da interface

| Evitar na navegação comum | Preferir para o usuário | Motivo |
| --- | --- | --- |
| Plano de contas | Categorias de receitas e despesas | Explica a finalidade, preservando a estrutura financeira interna |
| Contas caixa | Caixa e contas bancárias | Abrange bancos, caixa e contas digitais |
| Operações | Vendas e estoque | Nome do trabalho executado |
| API / Integrações (menu principal) | Outras opções → Integrações avançadas | Não expõe detalhes de desenvolvedor a todos |
| Cód. IBGE | Código do município (IBGE) | Explica o que deve ser informado |
| ID CSC | Identificador do CSC | Mantém nomenclatura fiscal sem abreviação solta |
| Busca dinâmica | Tempo de espera da pesquisa | Indica o efeito da configuração |
| Confirmar ações destrutivas | Pedir confirmação antes de excluir ou cancelar | Deixa claro o risco real |
| Interface compacta | Exibir mais informações na tela | Fala sobre o resultado |
| Permissões | O que esta pessoa pode acessar? | Indica qual escolha se espera do responsável |
| Gerar CSV | Gerar arquivo contábil | Descreve o uso; o arquivo continua no formato CSV |

Termos técnicos **não devem ser substituídos quando a precisão legal ou operacional depende deles**: NF-e, NFC-e, NFS-e, CT-e, MDF-e, CRT, NCM, CFOP, CST, CSOSN, CSC, alíquotas, certificado A1 e ambiente de homologação/produção. Nesses casos, apresentar contexto curto e linguagem objetiva.

## Regras para qualquer novo agente

1. **Títulos por objetivo:** “Como a venda funciona”, “Caixa e bancos”, “Dados da empresa”.
2. **Botões por ação verdadeira:** “Salvar”, “Adicionar usuário”, “Gerar arquivo contábil”, “Remover certificado”. Não usar “Enviar” se a ação só baixa arquivo, nem “Emitir” se só prepara documento.
3. **Descrições curtas:** o que muda se a opção for ativada; quando há risco fiscal, explicar “teste” versus “nota real”.
4. **Detalhes especializados em local próprio:** usar subabas, nunca apagar o recurso ou alterar os valores dos campos sem pedido específico.
5. **Preservar semântica de formulário:** refatoração de copy não muda `name`, `value`, `id`, `method`, `action`, `data-*`, autorização ou rotas.
6. **Qualidade:** testar o menu, cada aba antiga, permissões, leitor de tela, responsividade e o fluxo de salvar. Rótulos não podem prometer comportamento ainda não implementado.
7. **Visual:** manter aparência desktop, Roboto e os controles existentes. Componentes não devem diminuir a fonte para caber mais opções; navegar por categorias e permitir rolagem.

Teste de referência criado: `tests/Feature/SettingsClarityTest.php`. Os checks em testes não substituem a validação visual no Laragon.
