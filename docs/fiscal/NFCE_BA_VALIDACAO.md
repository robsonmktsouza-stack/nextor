# NFC-e modelo 65 — Bahia — validação fiscal

Fontes oficiais consultadas em 09/10/2026:

1. Portal Nacional da NF-e — MOC 7.0; Anexo I (leiaute e regras); Anexo IV (Contingência NFC-e); DANFE NFC-e/QR Code v6.0:
   https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=ndIjl+iEFdE%3D
2. SEFAZ-BA — Regulamento do ICMS, Decreto 13.780/2012, arts. 107-G a 107-J, compilado atualizado em 2026:
   https://mbusca.sefaz.ba.gov.br/DITRI/normas_complementares/decretos/decreto_2012_13780_ricms_texto_2021.pdf
3. ACBrLib — configurações e formas de emissão (FormaEmissao=8 para teOffLine):
   https://acbr.sourceforge.io/ACBrLib/ConfiguracoesdaBiblioteca16.html
4. ACBrLib — cancelamento e inutilização:
   https://acbr.sourceforge.io/ACBrLib/NFE_Cancelar.html
   https://acbr.sourceforge.io/ACBrLib/NFE_Inutilizar.html

## Controles implementados

- Emissão: salvar XML assinado e chave antes do envio. Em caso de resposta incerta, consultar a chave sem gerar outra NFC-e.
- Cancelamento BA: somente autorizado, com chave e protocolo, em até 30 minutos da autorização da SEFAZ, e declaração expressa de que não houve circulação. Atualizar como cancelada somente após resposta individual confirmada, protocolo e ambiente.
- Inutilização: somente lacuna de números não utilizados, abaixo do próximo número, impedindo sobreposição com documentos ou pedidos registrados. Solicitação até o 10º dia do mês seguinte ao salto, conforme regra oficial.
- Contingência: tpEmis=9, dhCont e xJust; ACBr FormaEmissao=8; salvar XML assinado sem transmissão, DANFE de duas vias e QR Code de contingência; após conexão, transmitir o mesmo arquivo/mesma chave. Prazo Bahia: até o primeiro dia útil subsequente.
- Tributação: usar regras e grupos explicitamente configurados e vigentes, sem inferir CST, CSOSN, CFOP ou alíquota. Rejeitar situações sem suporte matemático/fiscal; nunca inventar valor histórico de ST.

## Matriz mínima de cenários para homologação

- CRT 1: CFOP 5102/CSOSN 102 com tributação configurada.
- CRT 1: CFOP 5405/CSOSN 500 com base e ICMS-ST retido explicitamente cadastrados.
- CRT 3: CST 00 com modalidade de base 3 e alíquota configurada.
- CRT 4: operações do MEI compatíveis com os códigos permitidos.
- PIS/COFINS de operações permitidas; NCM/CEST e alíquotas efetivas quando exigíveis.
- Venda com descontos, pagamentos múltiplos, PIX, cartão, troco, consumidor identificado e não identificado.
- Eventos: confirmação, rejeição, prazo vencido, falta de comunicação, número reservado e protocolos.
- Contingência: assinatura sem rede, QR Code e duas vias, envio do mesmo XML, consulta após timeout.

## Pendências de homologação para habilitação segura em produção

Os testes automatizados não substituem testes de eventos reais com a DLL instalada e a SEFAZ-BA em homologação. Precisam ser verificados retorno e protocolo de cancelamento e inutilização, DANFE e QR Code em contingência, cálculo de tributos por operações específicas, transmissão em atraso e reconciliação após indisponibilidade.

Ações de rede são de efeito externo. Em caso de resultado indeterminado, consultar a SEFAZ antes de qualquer nova tentativa.
