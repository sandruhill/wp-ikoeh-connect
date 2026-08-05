# Skills para wp-ikoeh-connect

## Contexto

Item #4 do lote de features vindas da comparacao com o Novamira (a outra metade do item original, Design System, virou spec separada por ser do tamanho do Gutenberg pending-batch sozinho -- usuario confirmou querer os dois completos, mas cada um merece seu proprio plano). "Skills" no Novamira sao prompts/procedimentos reutilizaveis guardados como conteudo do proprio WordPress -- valor real pra clientes MCP que NAO tem sistema de skills local (ChatGPT, Claude.ai), ao contrario do Claude Code que ja tem o proprio (o `superpowers` usado nesta sessao).

**Diferenca arquitetural que exige adaptacao:** o Novamira usa a WordPress Abilities API (`wp_register_ability()`) -- cada skill vira uma "ability" registrada dentro do proprio WordPress, e um MCP Adapter (dependencia deles) expoe isso automaticamente via protocolo MCP. O wp-ikoeh-connect nao usa esse modelo: nosso servidor MCP e um processo Node separado (`mcp-server/`) que chama nossa API REST via HTTP. Nao da pra "registrar uma ability dentro do WordPress" e ela aparecer magicamente no MCP -- precisamos buscar os skills da API e registra-los explicitamente no lado Node.

## Armazenamento (lado WordPress)

Novo custom post type `ikoeh_skill` (`public:false`, `show_ui:false`, `show_in_rest:false` -- exposto so pelos nossos proprios endpoints). Campos: `post_title` = slug (sanitizado, minusculo, hifen), `post_excerpt` = descricao curta, `post_content` = corpo em markdown (o prompt/procedimento em si). Dois metas booleanos: `_ikoeh_skill_enable_prompt` (default true), `_ikoeh_skill_enable_agentic` (default true) -- mesma distincao do Novamira: "prompt" e um texto que o cliente MCP pode escolher injetar na conversa; "agentic" e algo que o agente pode buscar sob demanda quando achar relevante.

Novo scope `skills`. Rotas REST (flat, 3 segmentos):

- `POST /skill` -- cria ou atualiza (`title`, `description`, `content`, `enable_prompt`, `enable_agentic`, `on_conflict`: `fail`/`replace`/`rename`, mesma logica de resolucao de conflito do Novamira: `fail` retorna erro com uma sugestao de slug livre, `replace` sobrescreve o existente, `rename` acha um sufixo livre tipo `-2`/`-3` e cria um novo).
- `GET /skills` -- lista todos (id, slug, descricao, flags), sem o corpo completo (evita respostas gigantes se houver muitos skills grandes).
- `GET /skill?slug=` -- retorna um skill completo (corpo incluido).
- `DELETE /skill?slug=` -- apaga.

`wp_slash()` em toda escrita de string vinda do request (mesma convencao ja estabelecida). Limite de tamanho do corpo: 1MB (mesmo do Novamira), retorna erro se maior.

## Exposicao (lado MCP)

No startup do `mcp-server/src/index.js` (antes de `server.connect()`), busca `GET /skills` e, pra cada skill com `enable_prompt:true`, registra via `server.registerPrompt(slug, {title, description}, callback)` -- o callback busca o corpo completo (`GET /skill?slug=`) e retorna `{messages: [{role: "user", content: {type: "text", text: corpo}}]}`, formato padrao de prompt MCP. Isso deixa os skills "prompt" aparecerem como prompts selecionaveis de verdade no cliente MCP (Claude.ai, ChatGPT), nao so texto solto.

Skills com `enable_agentic:true` NAO viram uma tool por skill (diferente do Novamira) -- em vez disso, uma tool generica `wp_get_skill(slug)` deixa o agente buscar o conteudo quando achar relevante e seguir as instrucoes ele mesmo. Mais simples, menos partes moveis, mesmo efeito pratico pro caso de uso do agente.

MCP tools (arquivo `mcp-server/src/tools/skills.js`): `wp_write_skill`, `wp_list_skills`, `wp_get_skill`, `wp_delete_skill` (4 tools de gerenciamento) -- a exposicao como prompt e automatica no startup, nao e uma tool.

## Fora de escopo

- Nao replicar o mecanismo de "ability por skill" do Novamira (exige a WordPress Abilities API que nao usamos).
- Nao ha versionamento/revisao de skills nesta v1 (o Novamira ativa `supports: ['revisions']` no CPT pra isso -- pode entrar depois se virar necessidade real).
- Nao ha UI propria no wp-admin pra gerenciar skills nesta v1 -- gerenciamento e so via API/MCP tools, igual a todo outro recurso deste plugin.

## Seguranca

Scope `skills` novo, desmarcado por padrao. Nenhuma escrita aceita HTML/JS executavel diretamente -- o corpo e markdown puro, tratado como texto, nunca executado (nem pelo WordPress, nem pelo MCP server -- e so texto que vira uma mensagem de prompt ou e devolvido como string pro agente ler).

## Testes

Curl+wp-cli na CI (padrao ja estabelecido, sem PHPUnit): criar skill, listar, ler, resolver conflito com `on_conflict:rename`, apagar. Verificacao ao vivo: deploy no doctorbeats.com.br, criar um skill de teste via API, confirmar que aparece em `GET /skills`, apagar.
