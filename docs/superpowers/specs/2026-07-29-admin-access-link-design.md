# Admin access link (temporary passwordless login) para wp-ikoeh-connect

## Contexto

Comparando com o Novamira (novamira.ai, AGPL-3.0), ele tem uma ability (`create-admin-access-link.php`) que gera um link de login temporario sem senha, pensado pra automacao de navegador (o agente entra no wp-admin sem precisar pedir a senha ao usuario). Isso nao existia no wp-ikoeh-connect. E o primeiro de um lote de features vindas dessa comparacao (depois vem Gutenberg+pending-batch, Skills+Design System, chat no wp-admin, e por ultimo a versao completa de acesso a arquivo/PHP/WP-CLI, que o usuario ja confirmou querer igual ao Novamira apesar do risco).

Diferenca de contexto importante: o Novamira assume que quem chama a ability ja esta logado no wp-admin (`get_current_user_id()`). O wp-ikoeh-connect e chamado por um token Bearer externo, sem sessao WP nenhuma -- entao nao existe "usuario atual" pra logar. A decisao (confirmada com o usuario): sempre logar como o administrador principal do site (`get_users(['role' => 'administrator'])[0]`), sem parametro de usuario no request.

## Escopo

Novo arquivo `plugin/includes/rest/class-ikoeh-rest-admin-access.php`, seguindo o padrao dos arquivos irmaos (`class-ikoeh-rest-elementor.php`, `class-ikoeh-rest-content.php`). Novo scope de conexao `admin_access` (adicionado a `Ikoeh_Connect_Auth::ALL_SCOPES` e `Ikoeh_Connect_Admin::SCOPE_LABELS` como "Acesso admin temporario") -- e o scope mais sensivel do plugin, precisa ser marcado explicitamente ao criar/editar uma conexao, nunca herdado por padrao.

Fluxo de 3 etapas (mesmo desenho do Novamira, adaptado pro modelo headless):

1. **`POST /admin-access`** -- gated por `Ikoeh_Connect_Auth::require_scope('admin_access')` (Bearer token normal). Aceita body opcional: `expires_in` (30-600s, default 300), `session_expires_in` (60-3600s, default 1800), `admin_path` (relativo ao wp-admin, ex: `plugins.php`; rejeita URL absoluta/`//`/CRLF). Alvo sempre o admin principal do site. Gera `token` (64 chars, `wp_generate_password`) e `nonce` (32 chars), guarda um payload (`user_id`, `redirect_url`, `expires_at`, `session_expires_in`, `nonce_hash`) num transient chaveado por `HMAC-SHA256(token, wp_salt('auth'))`. Retorna `exchange_url`, `exchange_method`, `access_token`, `token_header` (`X-Ikoeh-Connect-Admin-Access-Token`), `access_nonce`, `nonce_header` (`X-Ikoeh-Connect-Admin-Access-Nonce`), `expires_at`, `session_expires_in`, `redirect_url`, `one_time: true`, `curl_example`.

2. **`POST /admin-access-exchange`** -- rota publica (`permission_callback: __return_true`), protegida por posse do token+nonce nos headers. Le o transient pela chave HMAC do token, **apaga imediatamente** (uso unico), confere `hash_equals($payload['nonce_hash'], HMAC-SHA256(nonce, wp_salt('nonce').'|ikoeh-connect-admin-access'))`. Revalida que o usuario alvo ainda existe e ainda e administrator. Gera um `login_nonce` novo (48 chars) com expiracao `min($access['expires_at'], time() + 60)` -- ou seja, no maximo 60s, sempre menor que o token original. Guarda um segundo transient (chave HMAC do login_nonce) com `user_id`, `redirect_url`, `expires_at`, `session_expires_in`. Retorna `login_url` (`add_query_arg('nonce', $login_nonce, rest_url('ikoeh-connect/v1/admin-access-login'))`), `expires_at`, `session_expires_in`, `redirect_url`, `one_time: true`.

3. **`GET /admin-access-login?nonce=`** -- rota publica, uso unico (apaga o transient ao ler). Nonce vai como query param, nao como segmento de path: o host bloqueia qualquer rota REST com 4+ segmentos antes mesmo do WordPress ver o request (mesma restricao ja documentada em `class-ikoeh-rest-content.php`), e `/admin-access-login/{nonce}` teria 4 segmentos (`ikoeh-connect`, `v1`, `admin-access-login`, `{nonce}`). `?nonce=` mantem a rota em 3 segmentos, mesmo padrao ja usado por `/elementor-content?id=`. Revalida payload (expiracao, usuario ainda administrator, `redirect_url` comeca com `admin_url()`). Se valido: filtra `auth_cookie_expiration` pra `session_expires_in` (removido no `finally`), chama `wp_set_current_user($user_id)` + `wp_set_auth_cookie($user_id, false, is_ssl())`, responde 302 pro `redirect_url`, com `Referrer-Policy: no-referrer` (evita a nonce vazar via header Referer se a pagina de destino tiver links externos; `Cache-Control: no-store` ja e aplicado globalmente pelo filtro `rest_pre_serve_request` existente em `wp-ikoeh-connect.php` pra todo o namespace).

**Por que 3 etapas e nao 2:** a etapa 2 existe especificamente pra reduzir o tempo que um segredo passa dentro de uma URL (que pode ir pra historico do navegador, logs de proxy, cache) -- o token/nonce da etapa 1 podem durar ate 600s mas viajam so em headers HTTP; a URL de login que de fato aparece na barra de enderecos dura no maximo 60s e so pode ser usada uma vez.

## Validacao de redirect_url

Mesma logica do Novamira: `admin_path` vazio vira `admin_url()`; rejeita CRLF, esquema de URL absoluto (`^[a-z][a-z0-9+.-]*:`), `//` no inicio; remove um `wp-admin/` inicial se presente; resultado final sempre prefixado por `admin_url()`.

## Auditoria

Sem storage novo: `error_log()` registra uma linha na criacao (nome da conexao, user_id alvo, expires_at) e uma linha no redirect bem-sucedido (user_id, timestamp). Aparece automaticamente no `GET /logs` ja existente (tail do `debug.log`), sem precisar de sistema de auditoria proprio.

## MCP tool

Novo arquivo `mcp-server/src/tools/admin-access.js`, mesmo padrao de `cache.js` (`registerAdminAccessTools(server, client)`, registrado em `index.js`). Uma tool: `wp_create_admin_access_link` -- chama `POST /admin-access`, retorna o JSON completo (incluindo `curl_example`) e uma instrucao no describe da tool dizendo pro agente: fazer o POST de troca, depois abrir o `login_url` retornado imediatamente numa ferramenta de navegador (a nonce da URL expira em ate 60s).

## Fora de escopo

- Nenhum parametro de `user_id` no request -- sempre o admin principal. Se o usuario quiser escolher outro usuario no futuro, e uma spec separada.
- Nenhuma UI nova no wp-admin (Ajustes > iKOEH Connect) alem do checkbox de escopo `admin_access` que ja vem de graca por estar em `SCOPE_LABELS`.

## Seguranca

Mesmo modelo de token por conexao com escopos. Scope `admin_access` e o mais sensivel ate agora (equivale a login como administrador) -- mas o design de 3 etapas com nonces de uso unico e janelas curtas (max 600s pro token de criacao, max 60s pra URL de login) limita bem o dano de um vazamento parcial (ex: um proxy de log capturando a URL de login ainda teria so 60s de janela, e so funciona uma vez).

## Bug real encontrado e corrigido em producao (2026-07-29)

Headers de resposta (`Cache-Control`, `X-LiteSpeed-Cache-Control`) nao foram suficientes: confirmado em producao que uma segunda chamada pra mesma `login_url` voltava com `x-litespeed-cache: hit` -- o LiteSpeed estava reproduzindo o 302 cacheado em vez de rodar a checagem de uso unico, quebrando a garantia de "so pode ser usado uma vez" completamente (o handler PHP nem chegava a executar na segunda chamada). O plugin LiteSpeed Cache toma a propria decisao de cache independente dos headers de resposta do PHP. Corrigido chamando `do_action('litespeed_control_set_nocache', 'motivo')` no inicio do handler -- esse e o hook documentado do proprio plugin LiteSpeed Cache pra marcar um request como nao cacheavel. Validado: reusar a mesma `login_url` agora retorna 401 de verdade em vez de um 302 cacheado.

**Vale lembrar pra qualquer endpoint publico futuro que precise ser genuinamente nao cacheavel neste host** (ex: futuras rotas do Gutenberg pending-batch): headers de Cache-Control sozinhos nao bastam, precisa do `litespeed_control_set_nocache`.

## Testes

Seguir padrao Docker Compose + CI existente. Teste de integracao minimo: criar conexao com scope `admin_access`, `POST /admin-access`, `POST /admin-access-exchange` com o token/nonce recebidos, `GET /admin-access-login?nonce=` e confirmar redirect 302 + cookie de auth setado. Confirmar tambem que reusar o mesmo token ou nonce uma segunda vez retorna erro (uso unico).
