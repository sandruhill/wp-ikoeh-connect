# Multi-Connection Scopes: Design Spec

**Data:** 2026-07-27
**Status:** Aprovado, aguardando plano de implementação

## Contexto e objetivo

A v0.1.0 do WP iKOEH Connect usa um único token bearer por site: qualquer ferramenta que o possua tem acesso total (plugins, conteúdo, banco, logs/cache), e não há como saber qual cliente (Claude Code, ChatGPT, etc) fez uma chamada específica nem revogar o acesso de um sem afetar os outros.

O usuário quer conectar várias IAs diferentes (ChatGPT, Claude.ai, Claude Code, e outras) ao mesmo site, cada uma com seu próprio token e com controle de quais capacidades cada uma pode usar, além de um jeito rápido de confirmar que a API está no ar.

## Modelo de dados

Troca da opção única `ikoeh_connect_token_hash` por uma lista de conexões, salva na opção `ikoeh_connect_connections` (array serializado pelo WordPress):

```php
[
  'id'           => 'gerado via wp_generate_uuid4() ou random_bytes',
  'name'         => 'ChatGPT' | 'Claude.ai' | 'Claude Code' | 'Outro: <texto livre>',
  'token_hash'   => 'sha256 do token, nunca o texto puro',
  'scopes'       => ['plugins', 'content', 'db', 'logs_cache'], // subconjunto marcado na criação
  'created_at'   => timestamp,
  'last_used_at' => timestamp|null,
]
```

O texto puro do token só existe: (1) na resposta da criação (mostrado uma vez no admin), (2) no arquivo de config local do MCP server de quem o recebeu. O WordPress nunca guarda o valor real, só o hash, igual ao modelo anterior.

## Migração

Ao carregar, se a opção antiga `ikoeh_connect_token_hash` existir e `ikoeh_connect_connections` ainda não existir, uma rotina de migração roda uma única vez (guardada por uma opção `ikoeh_connect_migrated_v2`): cria uma conexão com esse hash existente, nome `"Claude Code"`, todos os 4 escopos marcados, e copia `ikoeh_connect_last_used_at` se existir. **O token que já está em uso continua funcionando sem precisar ser regerado**, crítico, porque o icp-la.com.br já tem uma conexão real ativa.

## Autorização por escopo

Cada grupo de endpoints REST passa a exigir um escopo específico, checado depois da validação do token:

| Endpoint | Escopo exigido |
|---|---|
| `GET /site-info` | nenhum (qualquer conexão válida) |
| `GET/POST/DELETE /plugins...` | `plugins` |
| `GET/PUT /content/{id}` | `content` |
| `POST /db/query` | `db` |
| `GET /logs/debug`, `POST /cache/flush` | `logs_cache` |
| `POST /setup` | não muda (bootstrap por `SETUP_KEY`, cria uma conexão nova chamada "Setup" com todos os escopos) |

Implementação: `Ikoeh_Connect_Auth::require_scope($scope)` retorna uma closure usável como `permission_callback`, que localiza a conexão pelo hash do token recebido (percorre a lista comparando com `hash_equals`), confere se `$scope` está entre os escopos dela, atualiza `last_used_at` **daquela conexão específica** em caso de sucesso, e devolve `WP_Error` 401 (token inválido) ou 403 (token válido mas sem o escopo) conforme o caso.

## "Testar API" (reformulação deliberada)

O pedido original era um teste por conexão que confirmasse "está funcionando". Isso não é possível de forma honesta: o servidor só guarda o hash de cada token, então não há como o WordPress (ou eu) reconstruir o token de uma ferramenta específica pra testá-lo depois de criado. Em vez disso:

- **"Última atividade"** (por conexão, já existente) continua sendo o indicador real de uso. Atualiza sozinho toda vez que aquele token específico é usado com sucesso.
- **Botão único "Testar API"** (global, não por conexão): dispara uma chamada autenticada via nonce do próprio wp-admin pra confirmar que a REST API do plugin está registrada e respondendo, sem depender de nenhum token de conexão. Prova que "a máquina funciona"; não prova que uma IA específica está usando.

## Tela do admin

- Tabela de conexões existentes: nome, badges dos escopos, última atividade (formato "há X" já existente), botão **Revogar** por linha (remove a entrada do array; o token para de funcionar imediatamente).
- Formulário "Nova conexão": select com as 4 opções fixas de nome (ChatGPT / Claude.ai / Claude Code / Outro, campo de texto aparece se "Outro"), checkboxes dos 4 escopos, botão "Gerar conexão". Ao submeter, mostra o token em texto puro uma única vez (mesmo padrão de aviso já usado).
- Botão "Testar API" acima da tabela, com resultado inline (sucesso/erro) via chamada AJAX autenticada por nonce.
- Mantém os componentes nativos do wp-admin (`.wrap`, `.form-table`, `button-primary`) e o destaque verde/vermelho de status já implementado, agora refletindo "existe pelo menos uma conexão ativa" em vez de "token configurado".

## Testes

- Migração: testada localmente simulando a opção antiga presente, confirmando que vira uma conexão válida com o mesmo hash.
- Escopos: cada endpoint testado com uma conexão SEM o escopo exigido (espera 403) e COM o escopo (espera sucesso), tanto no ambiente Docker isolado quanto no CI.
- Revogação: token revogado deve devolver 401 imediatamente na chamada seguinte.
- `php -l` em todo arquivo PHP modificado/criado, mesmo padrão do projeto.

## Fora de escopo

- Rate limiting por conexão (hoje só existe pro `/setup`): pode virar um pedido futuro, não faz parte desta mudança.
- Expiração automática de token por tempo: não pedido, não implementado.
