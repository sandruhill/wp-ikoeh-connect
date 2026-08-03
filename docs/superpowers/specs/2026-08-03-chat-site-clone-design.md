# Clonagem de site externo via Chat iKOEH

## Contexto

O Chat iKOEH (wp-admin, autenticacao por sessao, cada cliente com a propria chave de API da Anthropic) hoje nao tem nenhuma chamada de ferramenta, so conversa. Este documento desenha uma extensao especifica: permitir que o cliente final peca pro chat clonar uma pagina de um site externo (concorrente, referencia) e o chat monte uma pagina equivalente no proprio site dele, usando blocos nativos do Elementor.

Publico: clientes finais nao-tecnicos, sem supervisao de ninguem da ikoeh olhando em tempo real. Isso exige um modelo de seguranca mais conservador que o usado em qualquer outra parte do plugin ate agora.

## Decisoes de escopo confirmadas com o usuario

- Escrita executa direto (sem passo de confirmacao previa), mas toda escrita e reversivel.
- Qualquer operacao que possa comprometer a estrutura ou saude do site/servidor fica de fora do chat pra sempre, reservada só pra acesso direto (FTP, banco de dados). Isso inclui: instalar/ativar/desativar plugin, trocar tema, banco de dados bruto, qualquer rota de arquivo/sistema, execute-php, wp-cli, e tambem **apagar pagina** (nao vira ferramenta nesta v1).
- Clonagem cobre site externo qualquer (nao so duplicar pagina propria), usando visao (screenshot) + comparacao iterativa entre o site de referencia e o resultado publicado, ate 4 rodadas por job.
- Custo de API (Anthropic + servico de screenshot) e pago pela chave do proprio cliente, nao da ikoeh. Estimativa: 60-150 mil tokens por clonagem completa, dependendo da complexidade do site de referencia e do numero de rodadas de ajuste.

## Arquitetura geral

Clonar um site externo com visao e comparacao leva minutos (buscar HTML, tirar print externo, gerar estrutura, publicar, tirar print do resultado, comparar, ajustar, repetir), muito alem do limite de uma requisicao PHP sincrona nesta hospedagem. Por isso o job roda em background via WP-Cron, seguindo o mesmo padrao de maquina de estados ja usado no Gutenberg pending-batch.

Novo CPT `ikoeh_clone_job`, um post por job, campos (postmeta):
- `source_url` (string, a URL do site de referencia)
- `target_post_id` (int, preenchido quando a pagina de destino e criada)
- `status`: `queued` -> `fetching` -> `generating` -> `publishing` -> `comparing` -> `refining` -> `done` | `partial` | `failed`
- `iteration` (int, comeca em 0)
- `max_iterations` (int, fixo em 4)
- `log` (array serializado de entradas: `{timestamp, step, note, reference_screenshot_url, result_screenshot_url}`)
- `created_by` (ID do usuario wp-admin que pediu, via sessao)
- `error_message` (string, preenchido so se `status=failed`)

Um hook `ikoeh_clone_job_tick` agendado a cada 1 minuto (`wp_schedule_event`) processa UM job pendente por tick (o mais antigo com status != done/partial/failed), avancando um passo da maquina de estados por tick. Lease/mutex simples via `add_option()` (mesmo padrao do Gutenberg) evita dois ticks processando o mesmo job.

## Site Inspector (peca nova, nao existe nada parecido no projeto)

Nova classe `Ikoeh_Connect_Site_Inspector`:
- `fetch_html_summary($url)`: `wp_remote_get($url)`, parse via `DOMDocument` (nucleo do PHP, sem dependencia nova), remove `<script>`/`<style>`/nav/footer repetitivo, extrai texto visivel por secao + lista de URLs de imagem + estrutura de headings. Retorna um resumo compacto (nao o HTML bruto, pra nao estourar contexto).
- `fetch_screenshot($url)`: chama um servico externo de screenshot (v1 suporta um unico provedor, urlbox.io) usando a chave de API que o proprio cliente configura em Ajustes > Chat iKOEH (mesmo padrao ja usado pra chave da Anthropic, nunca reexibida). Retorna a imagem (bytes ou URL temporaria).

Usada tanto pro site de referencia quanto pro proprio site do cliente (screenshot do resultado publicado, pra comparacao).

## Ferramentas expostas ao chat

O chat ganha `tools` na chamada da API Anthropic (hoje nao manda nenhuma). Todas as ferramentas chamam DIRETO os metodos PHP internos ja usados pelas rotas REST existentes (sem round-trip HTTP nelas mesmas, ja que o worker roda dentro do proprio processo WP autenticado por sessao), reaproveitando a mesma logica de normalizacao/validacao ja testada (ex: `Ikoeh_Connect_Rest_Elementor::normalize_elements()`):

- `create_page(title, slug?)`: cria post/pagina novo (equivalente a `POST /posts`).
- `write_page_content(post_id, elementor_data)`: escreve a estrutura Elementor (equivalente a `PUT /content`), com normalizacao automatica (`elements:[]`, `content_width:full` no container raiz) ja existente. Salva snapshot ANTES de sobrescrever (ver secao Desfazer).
- `upload_media(image_url)`: baixa uma imagem de uma URL externa e sobe pra midia do proprio WP (nunca hotlink), equivalente a `POST /media`. Dedupe por URL de origem dentro do mesmo job (cache no `log`), pra nao subir a mesma imagem varias vezes entre rodadas.
- `list_pages()`: lista paginas existentes (contexto pro modelo, equivalente a leitura de `GET /content`).

Lista negra permanente (nunca viram ferramenta, independente do prompt do usuario): instalar/ativar/desativar plugin, trocar tema, `/database`, qualquer rota de arquivo/sistema, execute-php, wp-cli, apagar pagina.

## Loop de comparacao visual

1. `fetching`: `Site_Inspector::fetch_html_summary()` + `fetch_screenshot()` do `source_url`.
2. `generating`: manda resumo de texto + screenshot de referencia pro Claude (chamada com `tools`), ele chama `create_page` + `write_page_content` (podendo tambem chamar `upload_media` pelas imagens que quiser usar).
3. `publishing`: pagina fica visivel no site do cliente (ja e o resultado de `write_page_content`, nao ha passo separado alem de confirmar que salvou).
4. `comparing`: `Site_Inspector::fetch_screenshot()` da PROPRIA pagina publicada. Manda os dois prints (referencia + resultado) pro Claude perguntando se esta proximo o suficiente ou o que ajustar.
5. Se o Claude responder so com texto (sem chamar nenhuma ferramenta), o loop termina com `status=done`, e a mensagem final no chat e o proprio texto do modelo (satisfeito ou apontando pequenas limitacoes, tanto faz, nao ha classificacao de sentimento, o texto do modelo vira o relato final direto pro cliente).
6. Se o Claude chamar `write_page_content`/`upload_media` de novo, incrementa `iteration`, volta pro passo `comparing` apos republicar.
7. Se `iteration` atingir `max_iterations` (4) e o Claude AINDA chamar ferramenta de novo (nao parou sozinho), `status=partial`: a distincao done/partial e puramente mecanica (parou sozinho vs bateu no limite), nunca baseada em interpretar o tom do texto. Mensagem final usa a ultima observacao do proprio modelo (o que ele ainda estava tentando ajustar) como base do aviso de revisao manual.

## Desfazer (mecanismo novo, reutilizavel por qualquer ferramenta futura, nao so clonagem)

Novo postmeta `_ikoeh_chat_snapshot_history` por pagina: array com no maximo 5 entradas `{timestamp, elementor_data, elementor_css, elementor_page_assets}`. Toda chamada de `write_page_content` salva o estado ANTES de sobrescrever (empilha, descarta o mais antigo se passar de 5).

Nova ferramenta `undo_last_change(post_id)`: retira a ultima entrada do historico, restaura as 3 chaves (`_elementor_data`, `_elementor_css`, `_elementor_page_assets`), limpa `_elementor_element_cache` (mesma licao do bug historico de cache do Elementor, sempre limpar as 3+1 chaves juntas). Tambem exposta como botao "Desfazer ultima alteracao" na propria conversa do chat, do lado de qualquer pagina mencionada.

## Interface no wp-admin

Cliente cola uma URL no Chat iKOEH e pede pra clonar. O chat cria o `ikoeh_clone_job` (status `queued`) e responde confirmando que comecou. A conversa mostra uma barra de progresso simples (buscando -> gerando -> comparando -> pronto), atualizada via polling AJAX (mesmo padrao ja usado na Fila de Blocos do Gutenberg), sem precisar sair da tela do chat. Ao terminar (`done` ou `partial`), o chat manda uma mensagem final com link pra pagina criada e, se `partial`, uma lista do que o modelo nao conseguiu resolver sozinho.

## Fora de escopo

- Suporte a mais de um provedor de screenshot (v1 e so urlbox.io).
- Clonar mais de uma pagina por job (site inteiro de uma vez).
- Edicao de pagina ja existente via clonagem (v1 sempre cria pagina nova).
- Qualquer ferramenta de escrita alem das 4 listadas (nada de menu, SEO, formularios nesta v1).

## Testes

Sem PHPUnit, padrao ja estabelecido no projeto. CI: mock do servico de screenshot (nao chamar API externa de verdade no CI), confirmar que `write_page_content` salva snapshot antes de sobrescrever, que `undo_last_change` restaura as 4 chaves corretas, que o loop respeita `max_iterations`. Verificacao ao vivo: clonar uma pagina simples de verdade contra doctorbeats.com.br, confirmar visualmente que o resultado se aproxima do original e que o desfazer funciona.
