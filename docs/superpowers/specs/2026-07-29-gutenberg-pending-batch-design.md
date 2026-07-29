# Gutenberg + pending-batch para wp-ikoeh-connect

## Contexto

Item #2 do lote de features vindas da comparacao com o Novamira (novamira.ai, AGPL-3.0). E de longe o subsistema mais sofisticado do Novamira -- nao e um endpoint de request/response simples como tudo que ja construimos (admin-access-link, elementor, theme, media, posts): e um fluxo assincrono coordenado entre quem chama a API (o agente) e uma aba de navegador operada por um humano, porque validar/serializar blocos do Gutenberg com seguranca exige rodar o JS real do editor de blocos (`wp.blocks.isValidBlock()`/`wp.blocks.serialize()`), nao tem equivalente confiavel em PHP puro.

Usuario confirmou explicitamente querer a versao completa (nao uma versao simplificada so-PHP), ciente de que e uma engenharia de porte bem maior que qualquer coisa ja construida neste projeto. Fonte de referencia: `includes/abilities/gutenberg/bootstrap.php` do Novamira (~2000 linhas), lido na integra para esta spec.

**Unica divergencia deliberada do desenho do Novamira, confirmada com o usuario:** Novamira usa Server-Sent Events (SSE) de verdade pra aba do finalizador reportar "estou online". Numa hospedagem compartilhada com LiteSpeed/PHP-FPM (Hostinger), manter uma conexao HTTP aberta por muito tempo e fragil e pode esgotar o pool de workers do PHP-FPM se a aba ficar aberta por horas. Substituido por polling de intervalo curto (heartbeat a cada poucos segundos) -- mesma garantia funcional, sem o risco de infraestrutura.

## Armazenamento e maquina de estado

Novo custom post type `ikoeh_gb_change` (`public:false`, `show_ui:false`, `show_in_rest:false` -- dado exposto so pelos nossos proprios endpoints REST, igual a todo outro recurso do plugin). Dois "kinds" via postmeta (`_ikoeh_gb_kind`): `batch` (post pai) e `item` (post filho, um por post-alvo, `post_parent` = batch).

Estados, mesmos do Novamira: `draft -> ready -> running -> prepared -> finalized`, com `failed`/`conflicted`/`canceled`/`stale` como saidas de erro/abandono. Transicao atomica de estado via o mesmo truque do Novamira: `add_option()` como mutex (atomico no nivel do banco -- falha se a option ja existe, funciona como compare-and-swap), com lease de posse (`_ikoeh_gb_lease_owner`/`_ikoeh_gb_lease_expires_at`, 5 min) pra uma claim abandonada nao travar o batch pra sempre.

Deteccao de conflito: hash SHA-256 do `post_content` do alvo no momento em que o item entra na fila (`_ikoeh_gb_base_content_hash`); reconferido antes do commit final -- se o alvo mudou nesse meio tempo, o item vai pra `conflicted` e nada e escrito (rollback dos itens ja escritos do mesmo batch, se houver, restaurando o `post_content` original de cada um).

## Rotas REST (flat, 3 segmentos, scope novo `gutenberg`)

Rotas voltadas pro agente (auth por Bearer token + scope, mesmo padrao de sempre):

- `POST /gutenberg-batch` -- cria um batch pendente (`label`, `agent_note`). Retorna `batch_id`.
- `GET /gutenberg-batches` -- lista batches, `?status=` opcional.
- `GET /gutenberg-batch?id=` -- retorna um batch com seus items.
- `DELETE /gutenberg-batch?id=` -- cancela um batch (e todo item nao-terminal dele).
- `POST /gutenberg-item` -- adiciona uma mudanca pendente a um batch (`batch_id`, `target_id`, `target_type`, `operation`, `block_spec`). Valida `block_spec` (array de objetos `{name, attributes, innerBlocks}`) em PHP antes de aceitar -- validacao estrutural, nao validacao de bloco (essa fica pro finalizador no navegador).
- `DELETE /gutenberg-item?id=` -- cancela um item pendente individual.
- `POST /gutenberg-enable-finalization` -- transiciona um batch de `draft` pra `ready` (equivalente ao `enable-batch-finalization` do Novamira).
- `GET /gutenberg-finalization-url?id=` -- retorna a URL wp-admin da pagina "Fila de Blocos".
- `GET /gutenberg-runtime?id=` -- se alguma aba da Fila de Blocos esta com heartbeat recente (online) e se ela consegue finalizar esse batch especifico.
- `GET /gutenberg-content?id=` -- le os blocos atuais de um post (`parse_blocks()` do `post_content`, decodificado) -- leitura simples, sem envolver batch.

Rotas internas (chamadas so pelo JS da propria pagina "Fila de Blocos" no navegador, autenticadas por sessao wp-admin normal + nonce, NAO pelo token Bearer -- nao fazem sentido pro agente chamar direto, entao nao viram MCP tool):

- `POST /gutenberg-claim-batch?id=` -- reivindica um batch `ready` pra finalizacao (seta lease).
- `POST /gutenberg-claim-item?batch_id=` -- reivindica o proximo item `ready` dentro de um batch ja reivindicado.
- `POST /gutenberg-complete-item` -- recebe o conteudo ja serializado (`item_id`, `lease_owner`, `content`, `validations`) depois que o JS validou/serializou de verdade com `wp.blocks`.
- `POST /gutenberg-heartbeat` -- a aba aberta chama isso a cada poucos segundos; atualiza um transient que `GET /gutenberg-runtime` consulta.

## Pagina "Fila de Blocos" (wp-admin)

Nova pagina em Ajustes > iKOEH Connect > Fila de Blocos (ou submenu proprio). Ao carregar, enfileira os scripts nativos do Gutenberg que ja vem registrados no WordPress fora do editor de posts (`wp-blocks`, `wp-block-library`, `wp-element`, etc via `wp_enqueue_script`) -- nao precisa da chrome inteira do editor, so a API JS de blocos.

JS da pagina, a cada ~5s: manda um heartbeat, busca batches `ready`, se achar um reivindica (`claim-batch`), percorre os items `ready` dele um a um (`claim-item`), reconstroi cada bloco a partir do `block_spec` armazenado, valida com `wp.blocks.isValidBlock()`, serializa com `wp.blocks.serialize()`, envia o resultado pra `complete-item`. Mostra uma lista simples de batches/items e seus status (visibilidade humana, nao so processamento silencioso).

## Limpeza e locking

Cron diario (`ikoeh_gb_cleanup`, agendado via `wp_schedule_event` no `init` se ainda nao agendado): marca drafts paradas ha mais de 24h como `stale`, marca batches `failed` antigos (14+ dias) como `stale`, apaga de vez (post + postmeta) batches e items em estado terminal ha mais de 14 dias.

## MCP tools

`wp_create_gutenberg_batch`, `wp_add_gutenberg_change`, `wp_list_gutenberg_batches`, `wp_get_gutenberg_batch`, `wp_delete_gutenberg_batch`, `wp_delete_gutenberg_change`, `wp_enable_gutenberg_finalization`, `wp_get_gutenberg_content` -- oito tools, um arquivo `mcp-server/src/tools/gutenberg.js`. As rotas internas de claim/complete/heartbeat NAO viram tool (so fazem sentido chamadas pelo JS da propria pagina wp-admin).

## Fora de escopo

- SSE de verdade (ver divergencia deliberada acima).
- Blocos dinamicos proprios tipo o `novamira/*` do Novamira (fast path que pula finalizacao) -- nao temos blocos customizados, todo bloco passa pelo fluxo completo de batch.
- Suporte a `core/html`/`core/freeform` como excecao especial (o Novamira valida que blocos nao sao 100% HTML bruto pra evitar "conteudo jogado num bloco HTML em vez de composto com blocos de verdade" -- pode entrar depois se virar problema real, nao e essencial pro MVP).

## Testes

Integracao minima: criar batch, adicionar item com `block_spec` valido, habilitar finalizacao (draft->ready), simular o fluxo do finalizador via chamadas diretas as rotas internas (claim-batch, claim-item, complete-item com conteudo serializado manualmente) sem precisar de navegador de verdade no teste automatizado, confirmar que o `post_content` do alvo foi atualizado e o batch foi pra `finalized`. Teste separado de conflito: mudar o `post_content` do alvo entre a criacao do item e a finalizacao, confirmar que vira `conflicted` e o conteudo original e preservado.
