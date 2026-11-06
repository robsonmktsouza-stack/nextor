# Auditoria visual isolada

Estas ferramentas renderizam as telas reais do Laravel com dados sintéticos. Não são rotas do ERP e não devem ser servidas pelo servidor da aplicação.

Pré-requisitos: dependências Composer instaladas, Docker com imagem `composer:2`, Node 22 ou superior com `WebSocket` nativo e Chromium. Os comandos abaixo usam Bash (Linux/WSL); o ambiente Windows/Laragon de emissão não é acessado nem modificado.

## Preparação

Execute na raiz do repositório. Use uma pasta nova de evidências para uma execução nova; não copie banco de cliente.

```bash
mkdir -p /workspace/scratch/visual-standardization/storage/framework/{cache,sessions,views}
mkdir -p /workspace/scratch/visual-standardization/storage/logs
touch /workspace/scratch/visual-standardization/audit.sqlite

docker run -d --rm --name lumeron-visual-standardization \
  --user "$(id -u):$(id -g)" -p 127.0.0.1:8841:8841 \
  -e LUMERON_VISUAL_AUDIT=1 -e APP_ENV=testing \
  -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
  -e APP_URL=http://127.0.0.1:8841 \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=/evidence/visual-standardization/audit.sqlite \
  -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
  --mount "type=bind,source=$PWD,target=/app" \
  --mount type=bind,source=/workspace/scratch,target=/evidence \
  -w /app --entrypoint php composer:2 \
  -S 0.0.0.0:8841 -t public tools/visual-audit/router.php

docker exec lumeron-visual-standardization php tools/visual-audit/fixtures.php \
  > /workspace/scratch/visual-standardization/fixtures.json

docker exec lumeron-visual-standardization php artisan route:list --method=GET --json \
  > /workspace/scratch/visual-standardization/routes.json
```

Inicie Chromium em outro terminal, mantendo o processo aberto:

```bash
chromium --headless --no-sandbox --disable-dev-shm-usage \
  --disable-background-networking --lang=pt-BR \
  --remote-debugging-port=9228 \
  --user-data-dir=/workspace/scratch/visual-standardization/chromium about:blank
```

## Matriz de navegação

```bash
node tools/visual-audit/capture.mjs --phase final --widths 1920,1366,1024,390
node tools/visual-audit/gate.mjs /workspace/scratch/visual-standardization/final-matrix.json
node tools/visual-audit/focus.mjs
node tools/visual-audit/keyboard.mjs
node tools/visual-audit/fonts.mjs
```

O script aguarda o evento de carregamento do documento correto, as fontes e duas animações de tela antes de medir. Registra estilos computados, capturas, erros JavaScript, respostas HTTP, largura do documento, controles cortados e entradas fora dos respectivos grupos. Campos compostos são medidos pelo grupo externo; seus campos internos são identificados separadamente.

Cada estado exige que a rota esperada, o formulário e o painel/modal específico estejam abertos. Uma navegação inesperada não é aceita como cobertura. O roteador inclui nos cabeçalhos os nomes das views efetivamente renderizadas. `gate.mjs` verifica altura, raio e tipografia dos papéis comuns, registra papéis desconhecidos e termina com erro quando há divergências. Ações das barras compactas, ações de página, campos de grade, componentes compostos e entradas grandes do caixa possuem papéis distintos. `focus.mjs` compara foco, somente leitura e desabilitado após aguardar as transições, sem salvar alterações.

As capturas de abas e modais usam apenas alterações no DOM e cliques que abrem a interface. Nenhum formulário é enviado. O roteador recusa métodos diferentes de GET e exige ambiente de teste com SQLite na pasta de evidências. Não há uso de certificado, assinatura, transmissão fiscal, APIs cadastrais ou dados reais. Fontes remotas e consultas cadastrais externas são bloqueadas; a disponibilidade da fonte Roboto depende dos arquivos locais, e a renderização pode usar Arial como alternativa.

Opções úteis:

```bash
# Repetir somente telas afetadas por uma correção.
node tools/visual-audit/capture.mjs --phase targeted \
  --names product-create,customer-create,nfe-create --widths 1366,390

# Comparação dos cinco formulários principais, sem variantes de abas/modais.
node tools/visual-audit/capture.mjs --phase comparison --baseline-forms --widths 1366,390

# Densidade confortável, sem alterar a configuração persistida.
node tools/visual-audit/capture.mjs --phase density --comfortable \
  --names products,product-create --widths 1366
```

`--base` aceita apenas endereço HTTP em `127.0.0.1`. `--output` altera a pasta de evidências. `--source-root` e `--source-ref` identificam uma árvore extraída com `git archive`, para comparar uma revisão anterior sem trocar a branch do trabalho. O servidor desse arquivo deve usar seu próprio diretório de views compiladas para não misturar versões.

## Limites da validação

As rotas de download de XML, DANFE/PDF, anexos, pesquisa JSON e consultas externas são inventariadas separadamente das telas HTML. A auditoria de apresentação não executa emissão nem altera documentos autorizados. A renderização de dados fiscais de demonstração serve exclusivamente para conferir a interface; não representa conformidade tributária nem autorização de documento.

Encerramento:

```bash
docker stop lumeron-visual-standardization
```

Feche também o processo Chromium iniciado para a auditoria.
