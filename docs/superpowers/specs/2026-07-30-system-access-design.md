# Acesso a arquivo/PHP/WP-CLI (item mais arriscado) para wp-ikoeh-connect

## Contexto

Ultimo item do lote Novamira. Usuario confirmou explicitamente, no inicio desta sessao, querer a versao COMPLETA -- execucao de PHP arbitrario, execucao de comandos WP-CLI, e leitura/escrita/edicao/delecao de qualquer arquivo do site -- mesmo apos ser avisado que isso contraria a decisao de design original do projeto ("sem endpoint de eval de PHP/shell exec") e da controle efetivamente total do servidor pra quem tiver o token.

**Isso nao e "sem guardrails".** O Novamira, mesmo dando esse nivel de poder, tem UM mecanismo de seguranca real que vale replicar fielmente (nao e enfraquecer o pedido do usuario, e ser fiel ao que o Novamira de verdade construiu): toda operacao de arquivo e confinada a dentro do `ABSPATH` (raiz do WordPress) via checagem de limite baseada em `realpath()`, escrita atraves de symlink e rejeitada, e escrita/edicao de arquivos `.php` fica restrita a um diretorio sandbox com deteccao basica de crash. Execucao de PHP via `eval()` e comandos WP-CLI continuam sem restricao alem de limite de tempo, exatamente como no Novamira.

## Escopo novo

`system` -- nome deliberadamente assustador, desmarcado por padrao, o escopo mais sensivel do plugin ate agora (mais que `admin_access`). Todas as rotas abaixo exigem esse scope.

## Rotas REST (flat, 3 segmentos)

- `POST /system-execute-php` -- `{code}`. Roda `eval($code)` com o WordPress carregado (`$wpdb`, todas as funcoes, plugins ativos disponiveis). Limite de tempo (`set_time_limit`, restaurado ao final), captura de saida via `ob_start()`, captura de warnings/notices/deprecations via `set_error_handler()`, captura de excecao via try/catch. Retorna `{success, return_value, output, errors, error_message?, error_class?, execution_time_ms}`.
- `POST /system-wp-cli` -- `{command}` (ex: `"plugin list"`, sem o `wp` inicial). Verifica `function_exists('proc_open') && function_exists('exec')` primeiro -- se qualquer uma estiver desabilitada (comum em hospedagem compartilhada, incluindo Hostinger onde o doctorbeats.com.br roda), retorna erro claro em vez de falha silenciosa. Localiza o binario `wp` (checa `vendor/bin/wp` relativo ao ABSPATH, depois `wp` no PATH via `exec('which wp')`). Roda via `proc_open` com `cwd=ABSPATH`, timeout de 60s (usando `stream_select` ou similar pra nao travar indefinidamente num comando que nunca termina), retorna `{command, stdout, stderr, exit_code}`.
- `GET /system-file` -- `{path}` (relativo ao ABSPATH ou absoluto, ambos aceitos), `offset`/`limit` opcionais. Confinado a ABSPATH (ver "Limite de caminho" abaixo). Retorna texto UTF-8 puro ou base64 se binario/UTF-8 invalido, junto com `mime_type`/`size`/`truncated`.
- `POST /system-file` -- `{path, content, mode: "overwrite"|"append"}`. Confinado a ABSPATH. Se o caminho final for `.php`, exige que esteja dentro do diretorio sandbox (ver abaixo) -- senao retorna erro explicando que arquivos PHP so podem ser escritos no sandbox. Rejeita escrita atraves de symlink. Cria diretorios pai se necessario.
- `DELETE /system-file` -- `{path}`. Mesmo confinamento a ABSPATH.
- `GET /system-directory` -- `{path}`, lista arquivos/subdiretorios de um diretorio (nome, tamanho, e se e diretorio), confinado a ABSPATH.
- `POST /system-enable-file` / `POST /system-disable-file` -- `{path}`, so pra arquivos `.php` dentro do sandbox. Disable renomeia `foo.php` -> `foo.php.disabled` (idempotente se ja desabilitado); enable reverte. Nao apaga o conteudo -- e uma forma reversivel de desligar um pedaco de codigo gerado sem apagar.

## Limite de caminho (o guardrail real, replicado do Novamira)

Toda rota de arquivo/diretorio passa pela mesma funcao de resolucao:

1. Caminho relativo (nao comeca com `/`) e resolvido a partir de `ABSPATH`.
2. `realpath()` do resultado (ou, se o arquivo ainda nao existe -- caso de escrita de arquivo novo -- `realpath()` do diretorio pai + nome do arquivo).
3. Confere que o caminho resolvido esta dentro de `realpath(ABSPATH)` -- comparacao de string normalizada (barras uniformizadas, sem barra final), nao comparacao ingenua de prefixo (que teria bugs com `../etc-outro-caminho-parecido`). Fora da raiz = erro `path_outside_root`.
4. Pra escrita: se o caminho final e um symlink, rejeita (`symlink_write_rejected`).
5. Pra escrita/edicao especificamente de um caminho terminado em `.php`: confere que o caminho tambem esta dentro do diretorio sandbox (`wp-content/ikoeh-sandbox/`) -- senao, erro explicando que arquivos PHP so podem ser escritos ali. Leitura/delecao/listagem de `.php` fora do sandbox continuam permitidas (so a ESCRITA de novo conteudo PHP fora do sandbox e bloqueada).

## Sandbox de PHP com deteccao basica de crash

Diretorio `wp-content/ikoeh-sandbox/` (criado sob demanda no primeiro uso). Um mu-plugin minimo (`wp-content/mu-plugins/ikoeh-sandbox-loader.php`, instalado/garantido pelo proprio plugin principal via um metodo chamado no `init` que copia esse arquivo pra la se ainda nao existir) roda em toda requisicao do site:

1. Confere se `wp-content/ikoeh-sandbox/.crashed` existe. Se existir, NAO carrega nenhum arquivo do sandbox (modo seguro) -- so isso, sem tentar auto-recuperar sozinho (o agente pode ler/apagar/corrigir o arquivo problematico e depois apagar `.crashed` manualmente via a propria API de arquivo, exatamente como as outras rotas ja permitem).
2. Se `.crashed` nao existe: registra um `register_shutdown_function` que confere `error_get_last()` ao final da requisicao -- se for um erro fatal (`E_ERROR`, `E_PARSE`, `E_COMPILE_ERROR`, `E_USER_ERROR`) E a requisicao atual tinha acabado de incluir algum arquivo do sandbox (marcado por uma flag setada logo antes de cada `include`), escreve o arquivo `.crashed` com o nome do arquivo suspeito, pra a PROXIMA requisicao entrar em modo seguro.
3. Se `.crashed` nao existe e nao houve crash: inclui (`include`) cada arquivo `.php` de `wp-content/ikoeh-sandbox/` (nivel raiz do diretorio apenas, nao recursivo) que NAO termine em `.disabled`.

## MCP tools

`wp_execute_php`, `wp_run_wp_cli`, `wp_read_system_file`, `wp_write_system_file`, `wp_delete_system_file`, `wp_list_system_directory`, `wp_enable_system_file`, `wp_disable_system_file` -- 8 tools, arquivo `mcp-server/src/tools/system.js`.

## Seguranca (resumo)

- Scope `system`, desmarcado por padrao, mais sensivel que qualquer outro ja existente.
- Confinamento real a ABSPATH com `realpath()`, nao string matching ingenuo.
- Rejeicao de escrita atraves de symlink.
- Escrita/edicao de PHP restrita ao sandbox -- protege contra sobrescrever acidentalmente (ou por instrucao mal-interpretada) arquivos como `wp-config.php`, plugins existentes, ou o proprio wp-ikoeh-connect.
- Deteccao basica de crash no sandbox -- um erro fatal num arquivo gerado nao derruba o site indefinidamente, so ate a proxima requisicao entrar em modo seguro.
- `run-wp-cli` falha de forma clara (nao silenciosa) se `proc_open`/`exec` estiverem desabilitados pela hospedagem -- caso real e esperado no doctorbeats.com.br (Hostinger compartilhado).

## Fora de escopo

- Upload de arquivo binario/base64 via essas rotas -- ja existe `/media` pra isso (mídia do WordPress) e essas rotas de sistema sao so pra texto UTF-8 (o Novamira tem uma ability separada `create-upload-link` pra isso, que nao replicamos aqui -- fora do escopo deste item especifico).
- Execucao assincrona/em background de comandos WP-CLI de longa duracao (o Novamira tem um modo async pra isso -- fica de fora, todo comando roda sincrono com timeout).

## Testes

Sem PHPUnit, padrao ja estabelecido. CI: confirmar que um `system-execute-php` simples retorna o valor esperado, que uma tentativa de escrever `.php` fora do sandbox falha com o erro certo, que uma tentativa de escrever fora do ABSPATH (`../../../etc/passwd` por exemplo) falha com `path_outside_root`, que escrita de um arquivo `.php` DENTRO do sandbox funciona e o `.php` fica realmente la. Verificacao ao vivo: os mesmos casos contra doctorbeats.com.br, alem de confirmar se `proc_open`/`exec` estao mesmo desabilitados la (documentar o resultado real, nao assumir).
