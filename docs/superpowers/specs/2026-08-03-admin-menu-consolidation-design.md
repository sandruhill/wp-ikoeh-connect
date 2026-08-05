# Consolidacao do menu wp-admin do iKOEH Connect

## Contexto

O plugin tem hoje 3 itens separados em Ajustes: "iKOEH Connect" (conexoes + config do chat), "iKOEH Chat" (a conversa em si) e "iKOEH Fila de Blocos" (fila do Gutenberg). Usuario quer menos itens de menu e configuracoes que salvam sozinhas, sem botao "Salvar".

## Decisao

Juntar a conversa do Chat (hoje pagina propria, `class-ikoeh-chat-admin.php`) dentro da pagina "iKOEH Connect" como uma aba, ao lado de uma aba "Conexoes" (o que ja existe hoje). A Fila de Blocos continua separada -- e uma ferramenta operacional ao vivo (roda `wp.blocks`/polling proprio), nao uma tela de configuracao, misturar as duas seria estranho.

Resultado: 2 itens no menu (iKOEH Connect com 2 abas, Fila de Blocos), em vez de 3.

## Abas

- **Conexoes** (aba padrao): tudo que ja existe hoje na pagina principal -- status card, tabela de conexoes, formulario de nova conexao, revogar. Sem mudanca de comportamento nenhuma aqui.
- **Chat**: secao de configuracao (chave Anthropic, modelo, chave Google/screenshot) + a conversa em si (historico de mensagens + campo de texto + enviar), tudo que hoje vive em `class-ikoeh-chat-admin.php`.

Navegacao via `?page=ikoeh-connect&tab=chat` (padrao WP de abas em pagina de configuracao), sem JS de tab custom, so um link simples que recarrega a pagina com o parametro.

## Auto-save

A secao de configuracao do chat (chave Anthropic, modelo, chave Google) deixa de ter um formulario POST com botao "Salvar configuracoes do chat" e passa a salvar via AJAX:
- Trocar o modelo (select) dispara salvar imediatamente no `change`.
- Sair de um campo de chave (API key/screenshot key) dispara salvar no `blur`, so se o campo nao estiver vazio (mesma logica de "deixar em branco mantem a atual" que ja existe).
- Um texto pequeno perto do campo confirma "Salvo" por 2 segundos apos cada salvamento bem-sucedido, sem popup nem recarregar a pagina.

Nova rota AJAX `ikoeh_chat_save_setting` (uma chamada por campo, nao um form inteiro) recebendo `{field, value}`, onde `field` e um de `model`, `api_key`, `screenshot_api_key` (rejeita qualquer outro valor com erro 400), reaproveitando exatamente a mesma logica de validacao/salvamento que ja existe hoje no handler POST de `class-ikoeh-admin.php` (so que por campo individual em vez de tudo de uma vez): `model` faz `sanitize_text_field` + `update_option(Ikoeh_Connect_Chat::OPTION_MODEL, ...)`; `api_key`/`screenshot_api_key` fazem `trim()` e so chamam `update_option()` se o valor nao for vazio (campo vazio = nao mexe na chave atual), exatamente como hoje.

**Formulario de nova conexao e botao de revogar continuam exatamente como estao** -- acoes explicitas com efeito real (gerar/revogar token), nao configuracao passiva, entao mantem o botao de submit de sempre.

## Arquivos afetados

- `plugin/includes/class-ikoeh-admin.php`: adicionar navegacao de abas, mover a secao de config do chat + a conversa pra dentro da aba "Chat", adicionar a nova rota AJAX de auto-save por campo.
- `plugin/includes/class-ikoeh-chat-admin.php`: `register_menu()` para de chamar `add_submenu_page` (remove o item de menu separado); `render_page()`'s conteudo (historico + textarea + enviar) vira um metodo publico chamado pela aba "Chat" de `class-ikoeh-admin.php`, em vez de sua propria pagina.
- `plugin/assets/chat.js`: sem mudanca na logica de enviar mensagem/polling de clonagem/desfazer -- so precisa continuar funcionando quando carregado dentro da aba em vez de uma pagina propria (confirmar que os IDs de elemento que ele espera ainda existem, o que deve ser o caso ja que so estamos movendo o HTML, nao redesenhando).
- `plugin/includes/class-ikoeh-gutenberg-admin.php`: sem mudanca nenhuma, continua pagina propria.

## Fora de escopo

- Redesign visual das abas alem do padrao simples de link com `?tab=`.
- Auto-save no formulario de nova conexao ou no botao de revogar.
- Qualquer mudanca na Fila de Blocos.

## Testes

Sem PHPUnit, padrao ja estabelecido. Lint em todos os arquivos tocados. Verificacao ao vivo: abrir a pagina, trocar de aba, mandar uma mensagem no chat (confirmar que ainda funciona dentro da aba), trocar o modelo e confirmar que salva sozinho (sem apertar nada), sair de um campo de chave preenchido e confirmar o "Salvo" aparecendo, confirmar que a Fila de Blocos continua no menu normalmente.
