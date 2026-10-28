# Orientação dos retornos SEFAZ — NF-e e NFC-e

O emissor apresenta o código, uma explicação, os campos a conferir e os próximos
passos no editor NF-e 55, no editor/detalhe NFC-e 65 e nas solicitações de
inutilização. Rejeições de cancelamento são explicadas a partir do retorno do
evento, sem confundi-lo com a autorização anterior da nota.

O catálogo `2026.10.26` contém **1.025 códigos cStat de 27 fontes oficiais**:
999 redações de rejeição, 24 retornos informativos e duas redações de denegação.
Inclui MOC 7.0 e NTs posteriores, inclusive RTC. Oito códigos são do serviço
de consulta GTIN, identificados nas fontes. Incluir um código não implementa
um novo serviço nem altera o suporte a operações fiscais do emissor.

Os 1.025 códigos publicados têm orientação em **86 perfis**, sem depender do
tratamento genérico de assunto desconhecido. As orientações incluem casos
específicos de homologação, circulação, complemento, EPEC, substituição,
numeração, notas de crédito/débito e previsão de entrega. São diagnósticos e
passos de revisão; não significam correção automática de todos esses cenários.

## Uso

Ao abrir um documento com ocorrência fiscal, o usuário vê a orientação e o
item quando o retorno contém `nItem`. Os detalhes mantêm o motivo recebido,
o erro salvo quando diferente e os links para as fontes oficiais. Textos
externos são escapados; os links de referência aceitam somente HTTPS oficial.
Atalhos GET abrem contabilidade, empresa, clientes, produtos ou regras fiscais
conforme o assunto e as permissões do usuário. Não concedem acesso adicional
nem executam gravações.

Duplicidade exige consulta/conciliação; ausência na consulta não comprova
rejeição do envio. Lote processado, evento registrado, EPEC autorizado e
autorização com alerta têm explicações próprias. A confirmação continua
dependendo dos verificadores de chave, ambiente, protocolo e XML existentes.

As redações históricas são preservadas. A correspondência exata normalizada
com o motivo recebido pode selecionar a variante antiga: a rejeição cadastral
302 atual e a antiga denegação 302 não recebem a mesma classificação.

Um código novo, não publicado neste catálogo, recebe aviso explícito de ausência
de orientação específica, conserva o motivo original e orienta conferência.
Não se presume o significado de códigos ausentes, nem se usam códigos de outros
modelos DF-e como se pertencessem à NF-e. Falhas locais sem cStat mantêm sua
mensagem, sem transformar números de produto, linhas de XML ou valores em códigos.

## Integridade

Esta camada é de apresentação e consulta. Ela não altera documentos, status,
chaves, numeração, vendas, estoque, financeiro ou filas; não libera correção,
reenvio, cancelamento ou inutilização. As políticas existentes continuam
verificando evidências antes de permitir qualquer ação fiscal. As recuperações
específicas existentes 486/487/321 permanecem separadas das orientações.

Retornos são lidos somente do diretório esperado do documento, com limite de
tamanho e conferência de chave/ambiente quando informados. Seções ou campos
duplicados, múltiplas notas e conteúdo com DTD/entidades não são usados para
escolher uma orientação. Um problema de leitura não interrompe a consulta da nota.

## Instalação e atualização

Na instalação de desenvolvimento já existente:

```powershell
git pull --ff-only origin feature/nfe55-motor-fiscal-completo
php artisan optimize:clear
```

Não há migration, seed ou instalação de catálogo em banco para esta entrega.
Não exige novos programas no Windows, alterações na ACBr nem credenciais.
O JSON acompanha o código e tem finais de linha LF preservados no Windows.
Atualizações dos dados exigem nova consulta/revisão e testes antes da publicação.
Produção segue o processo de release aprovado por instalação.

## Arquivos

| Componente | Arquivos |
| --- | --- |
| Dados e fontes | `resources/fiscal/rejections/2026.10.26.json`, `nt-sources.json` |
| Orientação e leitura | `app/Services/Fiscal/SefazRejectionGuidance.php`, `SefazRejectionActions.php` |
| Apresentação | `resources/views/fiscal/partials/sefaz-guidance.blade.php`, editores NF-e/NFC-e, detalhe fiscal, duas listagens de inutilização |
| Estilo | `public/css/ui-refinement.css` |
| Testes PHP | `SefazRejectionGuidanceTest`, `SefazRejectionGuidanceViewTest`, `SefazRejectionActionsTest` |
| Manutenção dos dados | `tools/fiscal/build_sefaz_rejections.py`, `extract_sefaz_nt.py` e respectivos testes |
| Integridade Windows | `.gitattributes` |

Fontes, versões, reprodução e limitações dos dados:
[fontes dos retornos](sefaz-rejection-sources.md).
Resultados reais e cobertura estão em `docs/fiscal/evidence/sefaz-rejections-*`.
As provas utilizam retornos sintéticos e documentos de teste; não houve nova
transmissão ACBr/SEFAZ ou acesso ao Windows/Laragon nesta entrega.

Commits de implementação: `ae6acdf` (catálogo e fontes), `21eef04`
(orientação e integração) e `3b262d4` (cobertura complementar e atalhos).
Não houve merge ou deploy.

## Verificação executada

Código de aplicação testado: `3b262d4`. Suíte completa: **1.179 testes,
8.142 asserções, zero falhas e zero erros**, em 2min20s; dois testes de PDF
com Chrome/Chromium real foram ignorados por ausência do navegador opcional.
Ambiente: PHP 8.5.12, PHPUnit 12.5.40 e SQLite em memória, no Docker Linux.
Não substitui validação visual no Windows nem execução em PHP 8.3/MySQL.

A seleção direcionada inicial passou com 168 testes/976 asserções. Os perfis
complementares e os atalhos também estão incluídos na suíte completa final.
Os 28 testes Python dos extratores fiscais passaram. A varredura do catálogo
confirmou 1.025 códigos, 86 perfis e zero códigos publicados no perfil genérico
`review`; o perfil seguro para códigos desconhecidos permanece disponível.

Evidências: `sefaz-rejections-full-tests.txt`, `sefaz-rejections-tests.json`,
`sefaz-rejections-coverage.json`, logs direcionados e dos extratores nesta pasta
`evidence`. O SHA-256 do catálogo é
`c5edaf663f36deea4c0d1818c65a1122aa121c7b5c1d16d826d9dde82e716981`.
