# Chat embutido no wp-admin para wp-ikoeh-connect

## Contexto

Item #6 do lote Novamira. Diferente de tudo que ja foi construido neste plugin, o chat nao serve o mesmo usuario (o Sandru operando via Claude Code) -- serve o CLIENTE FINAL do Sandru (dono do site, ex: Dra. Catarine, Doctor Beats), que nao usa Claude Code nem sabe o que e MCP e so quer entrar no proprio wp-admin e pedir mudancas numa caixa de chat. E o jeito de vender "IA integrada" pra quem nao vai configurar nada externo.

**Modelo de custo confirmado com o usuario**: cada cliente usa a propria chave de API (Anthropic), nao uma chave do Sandru -- assim o consumo de IA de cada cliente e pago pelo proprio cliente, sem custo agregado pro Sandru conforme mais clientes usarem.

## Escopo v1: conversa sem execucao de ferramentas

**Decisao de escopo deliberada**: nesta v1, o chat SO conversa -- responde perguntas, da orientacao, explica como fazer algo -- mas NAO tem acesso a nenhuma ferramenta que modifique o site (nao chama nossas proprias rotas REST de conteudo/Elementor/etc). Dar a uma conversa de chat, autenticada so por quem sabe entrar no wp-admin, acesso de escrita direta ao site e uma superficie de risco real (falta de guardrails, sem confirmacao antes de aplicar mudancas, sem trilha de auditoria) que merece uma spec propria depois que o loop basico de chat estiver provado funcionando. Ferramentas viram fase 2, nao comeca agora.

## Armazenamento

- Nova option `ikoeh_chat_api_key` -- a chave de API Anthropic do proprio cliente. Nunca retornada em nenhuma resposta de REST/AJAX (nem mascarada parcialmente) -- so usada server-side pra chamar a API da Anthropic. Na tela de configuracoes, se ja houver uma chave salva, mostra so "Chave configurada (termina em ...XXXX)" com opcao de trocar, nunca reexibe a chave completa.
- Nova option `ikoeh_chat_model` -- qual modelo Claude usar (dropdown com as opcoes atuais, default o modelo mais recente disponivel no momento da implementacao).
- Nova option `ikoeh_chat_history` -- array de mensagens `{role: "user"|"assistant", content: string, timestamp: int}`, uma conversa continua por site (nao multiplas conversas nomeadas -- mantem simples pra v1). Limitada as ultimas 50 mensagens (trunca as mais antigas ao adicionar novas, pra nao crescer sem limite).

## Fluxo

1. Pagina wp-admin nova ("Chat iKOEH", submenu de Ajustes) com area de mensagens + campo de texto + botao enviar. So visivel/usavel por quem pode `manage_options` (mesmo nivel de administrador).
2. Se `ikoeh_chat_api_key` nao estiver configurada, a pagina mostra um aviso e um link direto pra tela de configuracoes em vez da interface de chat.
3. Envio de mensagem: JS faz uma chamada `admin-ajax.php` (acao nova, nonce de sessao -- igual ao padrao ja usado pelo botao "Testar API" existente, NAO o sistema de Bearer token/scopes, que e pra chamadas externas) com o texto digitado.
4. Handler PHP: acrescenta a mensagem do usuario ao historico, monta o payload pra API da Anthropic (`POST https://api.anthropic.com/v1/messages`, headers `x-api-key`, `anthropic-version: 2023-06-01`, corpo `{model, max_tokens, messages: [...historico completo, formatado como role/content]}`), envia via `wp_remote_post()` (funcao nativa do WP, ja usada em `class-ikoeh-admin.php` pro botao "Testar API"), le a resposta, acrescenta a resposta do assistente ao historico, salva a option, retorna o historico atualizado pro JS re-renderizar.
5. Erros da API da Anthropic (chave invalida, limite de uso, etc) sao mostrados na interface como uma mensagem de sistema, nao travam a pagina.

## Seguranca

- Chave de API nunca trafega pro navegador depois de salva (so no momento de salvar, via HTTPS, direto pro option -- nunca ecoada de volta).
- Handler AJAX exige nonce + `current_user_can('manage_options')`, igual ao padrao ja usado no restante da tela de configuracoes deste plugin.
- Sem execucao de ferramentas nesta v1 (ver acima) -- o unico risco de escrita e a propria option de historico/configuracao, nada no conteudo do site.

## Fora de escopo

- Chamada de ferramentas / modificacao do site pela conversa (fase 2, spec propria).
- Multiplas conversas nomeadas / historico navegavel por data (so uma conversa continua por site).
- Streaming de resposta token-a-token (resposta completa de uma vez -- mais simples de implementar e testar sem infraestrutura de streaming).
- Suporte a outros provedores de IA alem da Anthropic (OpenAI, etc) -- comeca so com Anthropic, que e quem o Sandru ja usa.

## Testes

Sem PHPUnit, padrao ja estabelecido. Verificacao ao vivo: configurar uma chave de API de teste (ou confirmar que a UI reage corretamente sem chave configurada), mandar uma mensagem simples, confirmar que a resposta aparece e o historico persiste entre reloads da pagina.
