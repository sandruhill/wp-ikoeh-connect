# Design System para wp-ikoeh-connect

## Contexto

Segunda metade do item #4 do lote Novamira (a primeira, Skills, tem spec/plano proprios). No Novamira, "Design System" e um documento markdown por site (`DESIGN.md`) com tokens estruturados (cores, tipografia, espacamento, cantos arredondados, componentes) + "dials" (variance/density/motion, parametros abstratos de estilo) + orientacao textual (dos/donts) + um sistema de "preflight" de ~900 linhas que escaneia arquivos de codigo-fonte gerados (HTML/CSS/React) num repositorio git em busca de violacoes do design ativo ("anti-slop").

**Decisao de escopo deliberada, nao perguntada de novo ao usuario** (ja fizemos uma pergunta de escopo pra esse item e o usuario confirmou "completo" -- essa decisao aqui e sobre uma parte especifica que estruturalmente nao tem como replicar, nao uma tentativa de reduzir escopo por conta propria): o sistema de "preflight" do Novamira pressupoe um pipeline de geracao de codigo frontend (React/HTML/CSS) que o Novamira tem e o wp-ikoeh-connect nao tem -- nosso produto manipula conteudo WordPress via API REST (Elementor JSON, `theme.json`, posts), nao gera nem escaneia arquivos de codigo-fonte num repositorio. Nao existe "arquivo gerado" pra escanear. Por isso, o preflight anti-slop fica de fora -- nao e uma reducao de escopo por preguica, e uma peca que so faz sentido dentro do produto de geracao de frontend deles. O resto (tokens estruturados, documento por design, ativacao, biblioteca, checagem de prontidao) tem tradução direta e faz sentido pro nosso produto: a ideia central e "ter uma fonte unica de verdade pras cores/fontes/espacamento da marca do cliente, que o agente consulta antes de construir qualquer pagina Elementor nova" -- isso sim e usado ja manualmente nesta sessao (extrair cores de marca de um logo antes de montar uma pagina) e vale a pena tornar estruturado.

## Formato do documento

Cada "design" e um post com `post_content` em markdown, seguindo o mesmo formato de front-matter estruturado do Novamira (nao reinventado -- copiado de `includes/design/tokens.php`, que ja li na integra):

```
---
name: Doctor Beats
description: Marca da assistencia tecnica de equipamentos de DJ e fones Beats
colors:
  primary: "#E61B56"
  dark: "#0b0616"
  light: "#f2eefc"
typography:
  heading:
    fontFamily: Space Grotesk
    fontWeight: "700"
  body:
    fontFamily: Inter
spacing:
  md: "24px"
rounded:
  md: "12px"
components:
  button: "pill, sombra suave"
dials:
  variance: "0.4"
  density: "0.6"
  motion: "0.3"
---

## Orientacao

### Faca
- Use rosa (#E61B56) so em CTAs e destaques, nunca em blocos grandes de fundo.

### Nao faca
- Nao misture mais de 2 fontes por pagina.
```

Parser (porta direta do `Tokens\extract()` do Novamira, ja detalhado): le o bloco front-matter entre `---`/`---`, secoes de nivel 0 (`colors:`, `typography:`, `spacing:`, `rounded:`, `components:`, `dials:`) com pares chave-valor indentados por 2 espacos (typography e de 2 niveis: papel -> `fontFamily`/`fontWeight`). Sem front-matter ou secao vazia, cai pra extracao heuristica em prosa (regex simples: primeiro grupo de codigos hex mencionados no texto vira `colors`, primeiro nome de fonte mencionado vira `typography.body`) -- fallback simples, nao precisa ser tao sofisticado quanto o do Novamira pra ser util.

## Armazenamento e rotas REST

CPT `ikoeh_design` (`post_title`=nome, `post_name`=slug, `post_content`=documento markdown completo). Uma WordPress option `ikoeh_active_design` guarda o slug do design ativo (vazio = nenhum). Novo scope `design`.

- `POST /design` -- salva (cria ou atualiza por slug) um design a partir do markdown completo. Parametros: `content` (o markdown), `slug` opcional (senao deriva do `name` do front-matter). `wp_slash()` no `post_content`.
- `GET /design?slug=` -- retorna o design (metadata + `content` raw + tokens ja extraidos via `Tokens\extract()` equivalente, pra nao forcar o agente a fazer parse de markdown na mao).
- `GET /design-library` -- lista todos (slug, nome, descricao -- sem o markdown completo).
- `DELETE /design?slug=` -- apaga. Se era o design ativo, limpa a option.
- `POST /design-activate` -- seta a option `ikoeh_active_design` pro slug informado (erro 404 se o slug nao existe).
- `GET /design-active` -- retorna o design ativo completo (mesmo shape do `GET /design`), ou `{active: null}` se nenhum estiver ativo.
- `GET /design-check?slug=` -- roda a checagem de prontidao (`readiness`): `ready:true` exige pelo menos uma cor e uma tipografia definidas; tudo mais (spacing/rounded/components/dials) e aviso, nao erro -- mesma logica do Novamira (`Contract\readiness()`), so sem o `sync_ready` mais estrito deles (que exige tokens explicitos vs herdados, distincao que nao se aplica aqui already que so temos uma fonte).

## MCP tools

`wp_save_design`, `wp_get_design`, `wp_list_design_library`, `wp_delete_design`, `wp_activate_design`, `wp_get_active_design`, `wp_check_design` -- 7 tools, arquivo `mcp-server/src/tools/design.js`.

## Fora de escopo

- Preflight anti-slop de codigo gerado (motivo explicado acima).
- `sync_ready` (distincao entre tokens explicitos vs inferidos por heuristica de prosa) -- fica tudo como `ready`/nao-ready simples.
- Revisao/historico de versoes de um design (o Novamira usa `supports:['revisions']` do proprio WP pra isso -- pode entrar depois se virar necessidade real, e barato de adicionar).
- Painel visual proprio no wp-admin (biblioteca de templates com preview, historico navegavel) -- gerenciamento e so via API/MCP, igual a todo outro recurso deste plugin.

## Seguranca

Scope `design` novo, desmarcado por padrao. Documento e tratado como texto/markdown puro -- nunca executado, nunca vira HTML renderizado diretamente (a extracao de tokens e so parsing de string, sem `eval` nem interpolação perigosa).

## Testes

Curl+wp-cli na CI: salvar design com front-matter completo, checar `GET /design-check` retorna `ready:true`, ativar, confirmar `GET /design-active` retorna o mesmo slug, listar na biblioteca, apagar (confirmar que a option de ativo foi limpa). Verificacao ao vivo: deploy no doctorbeats.com.br, criar um design de teste com as cores/fontes reais da marca Doctor Beats (ja documentadas em memoria de sessoes anteriores), ativar, apagar ao final.
