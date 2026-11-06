# Contador, acesso ao XML e rejeição 486

O log local fornecido confirma preflight aprovado, tentativa de transmissão e
retorno SEFAZ **486**, com ausência do grupo de autorização de acesso ao XML.
O cadastro contábil já existia parcialmente, mas não alimentava esse grupo.

## Cadastro integrado

Configurações → NF-e e Configurações → Notas fiscais agora têm o atalho
**Contador e autorização de acesso ao XML da NF-e**, para a aba Contabilidade
(`settings?tab=accounting`). O cadastro contém escritório, responsável,
CPF/CNPJ, CRC, e-mail e telefone. Os documentos numéricos têm validação de
dígitos e normalização da máscara.

A opção **Autorizar o contador a acessar o XML completo da NF-e** concede
acesso ao documento completo. Cadastrar contato/e-mail isoladamente não concede
acesso. A NF-e usa `autXML001/CNPJCPF`, conferido no XML efetivamente retornado
pela ACBr. O contador é a primeira ocorrência; destinatário não pode ser repetido
como terceiro autorizado. O preflight de emissão BA impede nova rejeição por
grupo ausente antes de transmitir.

Há também seleção explícita da alternativa SEFAZ-BA, CNPJ público
13.937.073/0001-56, indicado no retorno do autorizador informado pelo usuário.
Não existe escolha automática de destinatário de XML, nem preenchimento
automático de dados particulares do contador.
A alternativa estadual só se aplica quando não há contador autorizado; ao
autorizar um contador, seu documento passa a ser utilizado em seu lugar.

## Corrigir a mesma NF-e 4 em homologação

No terminal normal do Laragon, após atualizar esta branch:

```bat
git pull --ff-only origin feature/nfe55-motor-fiscal-completo
php artisan migrate
php artisan optimize:clear
```

Preencha/salve o cadastro contábil e a autorização de acesso. Depois:

```bat
php -d ffi.enable=true artisan nfe:verify-flow 4 --correct-486 --process --wait=180 --consult
```

`--correct-486` exige prova arquivada de rejeição 486 da nota/chave/ambiente,
conteúdo reservado inalterado e autorização XML configurada. Preserva ID,
número, cNF e chave. Arquiva a tentativa anterior por hash em
`fiscal/nfe55/ID/attempts/rejection-486-HASH/` antes de preparar a correção.
O serviço existente assina/valida/transmite a nota corrigida. O comando não
reaproveita autorização, duplicidade, lote ou retorno ambíguo como prova de
rejeição. Consulta 217 compatível pode apoiar a prova original; outros retornos
conflitantes exigem conferência. NFC-e mantém sua política anterior.

Quando a nota está pendente e se pede consulta, o comando consulta imediatamente,
sem esperar 180 segundos por um worker que já encerrou a tentativa. O retorno
de envio é mostrado no terminal. Não é necessário alterar a nota por Tinker.

## Fila e migrations

O log também demonstrou que a tabela `failed_jobs` não existia. A migration
`2026_10_25_000005_create_failed_jobs_table.php` cria o armazenamento padrão do
Laravel já configurado em `queue.failed`, preservando uma tabela preexistente
e o histórico em rollback. Não altera dados/numeração fiscal. Nenhuma nova
biblioteca, integração ou configuração de certificado foi introduzida.

## Fontes e condições de aplicação

Consulta às cópias oficiais em 25/10/2026:

- MOC 7, Anexo I, versão de capa 7.00 (novembro/2020), regras **7GA01-10/486**
  e **7GA01-20/487**: obrigatoriedade a critério da UF e primeira ocorrência
  conforme cadastro do escritório na UF. Grupo GA: 0–10 ocorrências, CNPJ ou
  CPF. Regras GA02/GA03: rejeições 323–326 para documento inválido ou repetição
  do destinatário.
  https://dfe-portal.svrs.rs.gov.br/NFE/DownloadArquivoEstatico/?sistema=NFE&tipoArquivo=1&nomeArquivo=moc7-anexo-i-leiaute-e-rv.pdf
- Modelo INI oficial ACBr, autXML/CNPJCPF:
  https://acbr.sourceforge.io/ACBrLib/ModeloNFeINI.html
- Leitor oficial ACBr, índices de três ou dois dígitos e escolha CNPJ/CPF:
  https://svn.code.sf.net/p/acbr/code/trunk2/Fontes/ACBrDFe/ACBrNFe/Base/ACBrNFe.IniReader.pas
- Schema NF-e 4.00, grupo autXML, máximo dez, escolha CNPJ ou CPF:
  https://svn.code.sf.net/p/acbr/code/trunk2/Exemplos/ACBrDFe/Schemas/NFe/leiauteNFe_v4.00.xsd

O CNPJ alternativo da SEFAZ-BA foi informado no retorno real 486 apresentado
pelo usuário. A consulta independente à página estadual falhou por conectividade;
não atribuímos uma confirmação jurídica adicional a essa tentativa. A opção é
explícita e limitada à BA. Validade de inscrição do escritório na SEFAZ e
aceitação efetiva são verificadas pelo autorizador, não inferidas pelo ERP.

Os testes automatizados usam fixtures sintéticas e não transmitem à SEFAZ.
Nenhuma nova autorização real foi obtida por esta sessão Linux.
