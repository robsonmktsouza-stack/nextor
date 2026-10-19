# NFC-e — meios de pagamento, legado e ACBrLib (outubro/2026)

Referência de implementação: `feature/acbr-nfe-bootstrap`. A classificação no cadastro é distinta do código `tPag` do XML. **Não considerar esta matriz autorização real da SEFAZ.** As regras variam por Nota Técnica, UF, operação e versão dos schemas/ACBrLib.

## Fluxos suportados neste momento

| Tipo do cadastro | tPag | Grupo eletrônico | Condição |
| --- | --- | --- | --- |
| `cash` / `money` | 01 — Dinheiro | Não | Troco somente quando há parcela em espécie; `vTroco` no último `pag` |
| `credit_card` | 03 — Cartão de crédito | Sim | `tpIntegra` 1 ou 2 informado explicitamente; TEF integrado exige adquirente e autorização |
| `debit_card` | 04 — Cartão de débito | Sim | Mesmas verificações de integração, sem presumir crédito |
| `bank_slip` | 15 — Boleto | Não | Parcela vinculada ao respectivo meio |
| `pix` | 17 — Pagamento instantâneo (PIX) | Quando houver dados | Não inventar `tpIntegra`, autorização ou adquirente |
| `bank_transfer` / `transfer` | 18 — Transferência bancária | Não | Código 18, nunca convertido em PIX por similaridade |

Os campos `CNPJPag/UFPag` identificam o local do pagamento; `CNPJ` é a adquirente no grupo eletrônico; `tBand` é a bandeira, `cAut` é o código de autorização, `CNPJReceb` é o beneficiário e `idTermPag` é o terminal. Valores `vPag` são conciliados em centavos com `vNF`; o dinheiro recebido a mais compõe `vPag` da parcela em espécie e `vTroco` é informado separadamente. Pagamentos distintos permanecem em grupos `[pagNNN]` separados no INI. A ACBrLib é responsável pela geração, assinatura e schema do XML final.

## Outros códigos disponíveis nas tabelas oficiais

O catálogo de códigos inclui 02 (cheque), 05 (crédito loja), 10 (vale-alimentação), 11 (vale-refeição), 12 (vale-presente), 13 (vale-combustível), 14 (duplicata mercantil), 16 (depósito bancário), 19 (fidelidade), 20 (PIX estático), 21 (crédito loja por devolução), 22 (falha de hardware), 90 (sem pagamento), 98 (regime especial) e 99 (outros; exige descrição `xPag` quando aplicável).

**Não foram liberados genericamente para a NFC-e do Lumeron.** Cada opção adicional depende da modalidade fiscal, sua aplicabilidade no varejo/NFC-e, campo `xPag` e regras específicas, inclusive cronograma NT 2025.001 e normas da UF. Um `other` ou `card` ambíguo não é convertido para `99`, `03` ou `04` automaticamente. A operação comercial antiga continua legível, mas uma nova NFC-e exige classificação fiscal explícita.

## Migração sem alterações destrutivas

A migração `2026_10_19_210000_reconcile_card_payment_kinds.php` reconhece somente os códigos originais `credit_card` e `debit_card`. Se existirem ainda com `kind=card`, atualiza exclusivamente o tipo. Se não existirem, cria novos registros com parâmetros básicos. **Não** reativa contas desligadas, muda código, nome, taxa, prazo, conta financeira nem ordem de métodos existentes. Métodos personalizados antigos de tipo `card` podem ser editados nas Configurações e classificados como crédito ou débito; permanecem preservados até então.

## Escopo dos testes

PHPUnit verifica migração idempotente, configurações, escolha real no formulário, gravação da venda sem duplicação, pagamento dividido, snapshot fiscal, compatibilidade com códigos originais, validação de cartão TEF/POS, rejeição de legado ambíguo e INI ACBr (tPag, vPag, tBand, tpIntegra, CNPJ e cAut). O CI **não** chama ACBrLib/FFI com certificado A1 e não valida XML autorizado pela SEFAZ-BA. Exige-se homologação assistida dos cenários alterados (incluindo PIX, cartões e troco) antes da liberação individual para produção.

Fontes consultadas:
- ACBrLib NFC-e INI: https://acbr.sourceforge.io/ACBrLib/Modelo2-NFCeINI.html
- ACBrMonitor, códigos de formas de pagamento: https://acbr.sourceforge.io/ACBrMonitor/ECONF.html
- Portal oficial NF-e, manuais e NT: https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=04BIflQt1aY%3D
