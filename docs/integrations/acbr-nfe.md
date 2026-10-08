# ACBrLibNFe — integração inicial

Esta etapa apenas inicializa a biblioteca e consulta sua versão. **Não emite NFC-e** e não altera PDV, banco de dados ou telas fiscais.

## Arquivos

- Windows local: ACBrLibNFe Windows x64, variante **Cdecl** (`ACBrNFe64.dll`) e DLLs dependentes.
- Ubuntu 24.04 x64: pacote ACBrLibNFe Linux, variante `Linux/MT/libacbrnfe64.so`, após verificar dependências com `ldd`.
- Mantenha binários e certificados **fora do repositório e do diretório público**.

## Variáveis de ambiente

Configure em `.env` (caminhos absolutos, exclusivos para diagnóstico, sem certificado):

```dotenv
ACBr_NFE_LIBRARY_PATH=
ACBr_NFE_CONFIG_PATH=
```

Registre as variáveis em `config/services.php` na chave `acbr_nfe`:

```php
'acbr_nfe' => [
    'library_path' => env('ACBr_NFE_LIBRARY_PATH', ''),
    'config_path' => env('ACBr_NFE_CONFIG_PATH', ''),
],
```

Habilite a extensão `ffi` no PHP **CLI** somente em ambiente confiável. Confirme com `php -m`, e depois execute:

```bash
php artisan config:clear
php artisan acbr:nfe-doctor
```

A biblioteca pode exigir diretórios auxiliares, OpenSSL e permissões adequadas. O comando deve rodar isoladamente, nunca como endpoint web aberto.

## Antes da emissão em produção

Implementar configuração isolada por emitente, gestão segura de A1, locks e filas por CNPJ/série/modelo, numeração idempotente, transmissão em homologação, persistência de XML/protocolo, consulta, cancelamento, inutilização, contingência e impressão separada.

**Atenção:** a ACBrLib usa estado global em muitas funções. Não alternar empresas dentro do mesmo processo sem estratégia validada de isolamento.
