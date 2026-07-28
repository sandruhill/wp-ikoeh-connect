# Elementor abilities para wp-ikoeh-connect

## Contexto

Pesquisa do plugin Novamira (novamira.ai, AGPL-3.0, github.com/use-novamira/novamira) mostrou que a versao free dele nao tem suporte dedicado a Elementor, temas ou plugins de formulario: tudo cai em execucao de PHP arbitrario ou escrita bruta de postmeta (Elementor/Bricks tipados sao Pro, fechados). Essa lacuna e a oportunidade: construir abilities tipadas e seguras pra Elementor, temas de bloco do WP e (fase 2) Metform, sem a superficie de risco de PHP eval generico, que o wp-ikoeh-connect ja evita por decisao de design.

Objetivo do produto: acelerar e tornar mais confiavel a criacao e manutencao de sites Elementor/WordPress pela ikoeh, com plano de vender como produto proprio depois.

## Escopo v1: Elementor (ler + escrever)

Novo arquivo `plugin/includes/rest/class-ikoeh-rest-elementor.php`, seguindo o padrao dos arquivos irmaos (`class-ikoeh-rest-content.php`, `class-ikoeh-rest-plugins.php`). Novo scope de conexao `elementor` (mesma tabela multi-conexao ja existente).

Rotas (3 segmentos flat, mesma restricao de host ja documentada em `class-ikoeh-rest-content.php`):

- `GET /elementor-widgets` -- lista os widgets registrados no Elementor (`\Elementor\Plugin::$instance->widgets_manager->get_widget_types()`) e o schema de controles de cada um (`get_controls()`). Aceita `?type=` opcional pra filtrar um widget so.
- `GET /elementor-templates` -- lista templates salvos (CPT `elementor_library`): id, titulo, tipo (page/section/container).
- `GET /elementor-content?id=` -- retorna `_elementor_data` ja decodificado de JSON (array PHP, nao string), mais `_elementor_edit_mode`. Evita o Claude ter que fazer parse/serializacao de JSON na mao.
- `PUT /elementor-content?id=` -- recebe a arvore de elementos (array, nao string JSON), normaliza e escreve. Normalizacao automatica antes de salvar:
  - injeta `"elements": []` em qualquer no que nao tenha essa chave (bug: Elementor 4.x descarta a arvore inteira silenciosamente sem isso)
  - garante `settings.content_width: "full"` em containers de nivel superior quando ausente (senao fica boxed em 1140px)
  - seta `_elementor_edit_mode` pra `builder` se ainda nao estiver
  - aplica `wp_slash()` no JSON serializado antes de `update_post_meta()` (bug ja corrigido no `/content` generico, precisa valer aqui tambem)
  - ao final, deleta as tres chaves de cache do Elementor: `_elementor_css`, `_elementor_page_assets`, `_elementor_element_cache` (as tres, no mesma ordem -- deletar so uma nao basta, ja confirmado em producao)

MCP tools novos em `mcp-server/src/tools/elementor.js`: `list_elementor_widgets`, `get_elementor_widget_schema`, `list_elementor_templates`, `get_elementor_page`, `write_elementor_page`. Registrados em `index.js` como `registerElementorTools(server, client)`, mesmo padrao dos modulos existentes.

## Escopo v1.5: temas de bloco (logo em seguida, mesmo epic)

Novo arquivo `class-ikoeh-rest-theme.php`. Cobre so temas de bloco padrao do WP (Twenty Twenty-Four/Twenty Twenty-Five e afins) -- nao cobre Astra/GeneratePress/outros temas de terceiros nesta fase.

- `GET /theme-info` -- tema ativo, se e block theme (`wp_is_block_theme()`), se tem child theme.
- `GET /theme-json` / `PUT /theme-json` -- ler/escrever `theme.json` do tema ativo (cores, tipografia, espacamento).
- `POST /theme-activate` -- ativa um tema ja instalado por slug.

## Fora de escopo (fase 2, nao comeca agora)

- Metform e outros plugins de formulario (WPForms, Contact Form 7, Gravity Forms).
- Execucao de PHP arbitrario / eval generico -- decisao de design mantida, nao entra nem como fallback.
- Temas de terceiros (Astra, GeneratePress, OceanWP).
- Pagina de venda/produto (`ikoeh.com/wpikoehconnect`) -- fica pra quando a base tecnica estiver pronta. Quando for feita: tema claro, sem dark mode, usando a identidade visual que ja existe no ikoeh.com.

## Seguranca

Mesmo modelo de token por conexao com escopos ja existente (`plugins`/`content`/`db`/`logs_cache` + novo `elementor`). Nenhum endpoint novo aceita PHP ou shell arbitrario. Escrita continua exigindo o token ter o scope certo.

## Testes

Seguir o padrao ja existente (Docker Compose com WordPress limpo + CI do GitHub Actions). Adicionar Elementor ao ambiente de teste Docker. Teste de integracao minimo: criar post, escrever `_elementor_data` via `PUT /elementor-content`, ler de volta via `GET /elementor-content`, confirmar que a normalizacao foi aplicada (elements:[] presente, content_width full) e que as tres chaves de cache foram removidas apos a escrita.
