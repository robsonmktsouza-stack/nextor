# PDV e NFC-e Bahia — fluxo fiscal e checklist de homologação

**Data da revisão:** 19/10/2026. **Branch:** `feature/acbr-nfe-bootstrap` no repositório `robsonmktsouza-stack/nextor`. Requisitos verificados na documentação pública; os testes externos com SEFAZ/ACBr **não foram executados nesta revisão**. Não declarar o sistema homologado/credenciado para produção.

## Princípio de operação aprovado

**A venda começa e termina no PDV.** O fechamento registra uma venda só (estoque/financeiro) e pode preparar uma NFC-e modelo 65 associada à mesma venda. Emitir, corrigir, consultar, cancelar e imprimir fiscal são operações vinculadas; nenhuma delas deve refazer ou duplicar o registro da venda.

Após finalizar a venda, `PdvController::store` redireciona a `pdv.receipt` para comprovante **interno**, claramente indicado como **SEM VALOR FISCAL**. Ao fechar/cancelar o diálogo automático de impressão, a view `resources/views/pdv/receipt.blade.php` **sempre volta para `pdv.index`**, nunca para `fiscal.show`. Um botão para consultar a nota no módulo Fiscal continua disponível **somente por decisão explícita** do usuário habilitado, não como redirecionamento de fechamento.

Ao voltar, `resources/views/pdv/index.blade.php` mostra a situação fiscal vinculada à última venda: preparada, em processamento, pendente de consulta, rejeitada, autorizada ou contingência. **Autorizada** exige protocolo em formato válido e caminho de XML autorizado; não tratar `prepared`, `processing`, `pending` ou `offline_signed` como autorização. Evitar segunda venda para “tentar gerar nota”.

## Emissão automática

Se a emissão automática estiver configurada via `pdv.auto_nfce` ou `nfce.auto_from_pdv`, `SalesService` enfileira `ProcessNFCeJob` na conexão **database** após confirmar a venda. Não executar ACBr/FFI dentro do HTTP, **mesmo quando QUEUE_CONNECTION=sync**. A instalação precisa de tabela de jobs, certificado, credenciais e worker dedicado consumindo a fila `fiscal`. Se a fila estiver indisponível, registrar problema e manter a venda e a NFC-e preparada. Não inventar autorização nem reenviar às cegas.

O modelo 65 precisa de **um** documento ativo por venda. Os serviços atuais preservam estados e armazenamento dos XMLs, regras fiscais de item (NCM, CFOP, CSOSN/CST, grupos/revisões) e conferência do emitente BA, CSC, A1, ambiente e valores. Erro de configuração não deve levar o operador a registrar outra venda.

## Pagamentos

O PDV coleta formas múltiplas, valor, troco, dados de cartão TEF/POS, metadados de PIX quando aplicáveis e identificação opcional do consumidor. Confere soma dos pagamentos, CPF/CNPJ quando informado, parcelamento e saldo em dinheiro. O preflight da NFC-e valida os pagamentos. Para cartões, exigir `integration_type` explicitamente **1 (integrado) ou 2 (não integrado)**; nunca completar automaticamente com 2 quando o caixa não informou. Cartão **integrado** exige CNPJ da adquirente e autorização, ambos capturados no caixa. CNPJ transacional e UF devem ser preenchidos juntos, respeitando UF válida; dados incompletos bloqueiam transmissão fiscal. O formulário permite registrar também dados eletrônicos do PIX sem inventar integração ou identificador de pagamento.

**Mapeamento ACBr revisado:** em `[pagNNN]`, `CNPJPag` e `UFPag` são o CNPJ/UF do **estabelecimento onde a transação ocorreu**; `CNPJ` é o CNPJ da **instituição adquirente** no grupo do meio eletrônico. `CNPJReceb` é o **beneficiário** e `idTermPag` é o terminal. A implementação anterior confundia CNPJ transacional com CNPJ da adquirente e não enviava alguns dos campos que já eram coletados; isso foi corrigido em `NFCeIniBuilder`. Para PIX (`tPag=17`), os dados do grupo eletrônico são encaminhados quando o operador os informa, sem gerar códigos fictícios. **Pendente homologar as regras de presença obrigatória e os casos de PIX na BA** conforme NT vigentes; regras de outras UFs não são assumidas como lei baiana.

## Impressão e contingência

O comprovante interno **não substitui** o DANFE da NFC-e. O DANFE autorizado só pode ser entregue mediante documento autorizado com XML/protocolo real. A impressão fiscal no PDV oferece links para documento autorizado, ou — somente em contingência, quando existe `signed.xml` efetivamente preparado e status válido — o **DANFE contingência (2 vias)**. O template fiscal já prepara uma via para consumidor e outra para estabelecimento e marca **pendente de autorização**. Não mostrar botão de DANFE para `offline_print_pending`, pois nessa situação o PDF ainda pode estar indisponível. A emissão offline exige situação real, justificativa e regularização posterior conforme prazo aplicável.

Na Bahia, o RICMS/BA art. 107-G prevê a contingência offline quando não for possível transmitir ou obter resposta, impressão em duas vias e regularização até o primeiro dia útil seguinte. A ativação em produção continua sujeita ao gate de homologação/autorizações do Lumeron; não ativar automaticamente por qualquer erro.

## Referências oficiais

- [Portal Nacional — Manual de Orientação ao Contribuinte MOC 7.0, NF-e e NFC-e e anexos](https://www.nfe.fazenda.gov.br/portal/consulta.aspx/scripts/listaConteudo.aspx?AspxAutoDetectCookieSupport=1&tipoConteudo=ndIjl+iEFdE%3D)
- [ACBrLib — modelo oficial de INI para NFC-e, campos tPag, tpIntegra, CNPJPag, UFPag, CNPJ, CNPJReceb e idTermPag](https://acbr.sourceforge.io/ACBrLib/Modelo2-NFCeINI.html)
- [Portal Nacional — NT 2025.001 (QR Code NFC-e v3 e regras de pagamentos)](https://hom.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=uthum2eC3is%3D)
- [SEFAZ Bahia — perguntas e respostas NFC-e (requisitos de certificado, CSC e credenciamento)](https://www.sefaz.ba.gov.br/docs/inspetoria-eletronica/icms/nfce_perguntas_respostas_.pdf)
- [SEFAZ Bahia — RICMS/BA, artigos 107-A a 107-I](https://mbusca.sefaz.ba.gov.br/DITRI/normas_complementares/decretos/decreto_2012_13780_ricms_texto_2021.pdf)
- [Receita Federal — orientações e cronograma 2026 da Reforma Tributária do Consumo](https://www.gov.br/receitafederal/pt-br/acesso-a-informacao/acoes-e-programas/reforma-tributaria-do-consumo/orientacoes-da-reforma-tributaria/orientacoes-da-reforma-tributaria)

## Verificações necessárias antes de produção

- Testar em **homologação da SEFAZ-BA** com emitente autorizado, certificado A1 e CSC válidos, schemas e NT vigentes, sem simular cStat/protocolo.
- Confirmar geração do XML, assinatura XSD, QR Code, retorno individual cStat, protocolo e consulta em caso de timeout; comparar valores de produtos, tributos e pagamentos.
- Testar cartão TEF/POS, pagamentos divididos, CNPJ/UF, PIX, troco e consumidor identificado/não identificado com dados de teste válidos; revisar eventuais campos de IBS/CBS exigidos pelo cronograma atual para o perfil fiscal do emitente.
- Confirmar worker da fila `fiscal` ativo. Verificar emissão automática após venda, rejeição/correção, idempotência, DANFE em 58/80 mm, contingência offline em duas vias e posterior regularização.
- Validar cancelamentos, prazos estaduais e inutilização apenas nos cenários efetivamente autorizados. Não utilizar documentos reais de cliente sem permissão.

**Comandos de regressão local:** `php artisan test --filter=PdvFiscalCheckoutFlowTest`; incluir também testes existentes do ciclo NFC-e. Esses testes de código não substituem a homologação real da SEFAZ-BA.
