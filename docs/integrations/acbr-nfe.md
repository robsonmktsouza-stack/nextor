# ACBrLibNFe — NFC-e no NEXTOR

## Estado atual

A biblioteca ACBr Pro (ACBrLibNFe MT/Cdecl) já foi inicializada e o serviço NFC-e da SEFAZ-BA em homologação respondeu **cStat 107**. O PDV possui agora integração em desenvolvimento para preparar NFC-e a partir de vendas, assinar/validar XML e transmitir pela fila fiscal. **Uma autorização cStat 100 ainda não foi comprovada**; não usar emissão em produção antes da homologação ponta a ponta.

## Dependências locais

- PHP 8.3+ com FFI habilitada na **CLI**, binários ACBrLib e dependências instaladas fora do repositório.
- Certificado A1 PFX salvo no armazenamento privado do NEXTOR com senha criptografada, CSC de homologação e configurações da empresa.
- `.env`: `ACBr_NFE_LIBRARY_PATH`, `ACBr_NFE_CONFIG_PATH`, `ACBr_NFE_SCHEMAS_PATH` (opcional se os schemas estiverem em pasta padrão), `QUEUE_CONNECTION=database`.
- `ACBr_NFE_PRODUCTION_ENABLED` deve continuar **false** até aprovação em homologação.
- `DB_QUEUE_RETRY_AFTER` precisa ser superior ao `$timeout=180` segundos do worker fiscal (recomendado 240+), especialmente quando múltiplos workers estiverem ativos.
- Binários, INI contendo senha, arquivos XML e certificados nunca ficam no diretório público ou no GitHub.

## Passos para verificar o ambiente

```bat
git pull
php artisan migrate
php artisan optimize:clear
vendor\bin\phpunit --filter NFCeIniBuilderTest
php artisan acbr:nfce-check
php artisan acbr:nfce-status
```

Para verificar a sintaxe, execute `php -l app\Services\Fiscal\NFCeIniBuilder.php` e `php artisan route:list --name=fiscal.nfce.emit`.

## Fluxo definitivo pelo PDV

1. Nas configurações, habilite **Fiscal** e **NFC-e**; ambiente **homologação**. Inicialmente deixe **Emissão automática do PDV** desativada.
2. Configure CFOP/CSOSN/PIS/COFINS **corretos para o produto e operação**. O pré-check atual aceita apenas produtos de CSOSN 102 e PIS/COFINS 49, por enquanto; não force essas classificações se não forem aplicáveis.
3. Finalize uma venda controlada no **PDV existente**. A venda já movimenta estoque e financeiro **mesmo que a NFC-e seja em homologação**.
4. O sistema prepara `fiscal_document_jobs` vinculado à venda, sem transmitir automaticamente. Acesse o documento pela tela do comprovante ou módulo Fiscal → NFC-e.
5. Confira as pendências na tela do documento. Ao aprovar, use **Emitir NFC-e** (usuário com permissão fiscal).
6. Mantenha um worker ativo em outro terminal Laragon:

```bat
php artisan queue:work database --queue=fiscal --tries=1 --timeout=180
```

7. Consulte a situação no módulo Fiscal. Uma falha de comunicação depois do envio gera estado **pendente**, sem reenvio automático. Não faça outra venda nem gere outro número para tentar corrigir uma falha incerta.

## Limitações e próximos requisitos

- Ainda falta comprovar assinatura, validação e retorno de autorização com um XML real em homologação.
- O recibo térmico atual é **comprovante interno, sem valor fiscal**: a impressão do DANFE NFC-e oficial com QR Code ainda deve ser integrada.
- Contingência offline, cancelamento/inutilização fiscal efetivos, consulta de chave pendente, regras fiscais mais amplas e separação completa por organização/emitente ainda não estão homologados.
- Nunca habilite produção apenas porque a consulta de status retornou cStat 107.
