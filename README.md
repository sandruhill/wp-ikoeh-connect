# WP iKOEH Connect

Conecta o Claude (Anthropic) a sites WordPress para desenvolvimento e otimização assistida por IA: deploy de plugins, edição de conteúdo (incluindo Elementor), consultas ao banco e diagnóstico. Tudo através de uma API própria, autenticada, sem depender de acesso irrestrito ao servidor.

> **Status: em desenvolvimento inicial.** O design está definido (`docs/superpowers/specs/`), a implementação ainda não começou. Não use em produção ainda.

## O que é

Dois componentes:

- **`plugin/`**: plugin WordPress que expõe uma REST API própria (`/wp-json/ikoeh-connect/v1/...`) com um conjunto restrito e documentado de capacidades: gestão de plugins, edição de conteúdo, consultas ao banco, logs, cache.
- **`mcp-server/`**: servidor MCP (Node.js) que fala com essa API, para uso com Claude Code ou qualquer cliente MCP.

## Postura de segurança

Este projeto assume que o token de acesso é a única barreira entre "ferramenta de produtividade" e "acesso total ao seu site". Por isso:

- **Sem execução de código arbitrário.** Não há endpoint de eval de PHP nem shell exec, mesmo que isso limite alguns casos de uso. O risco de um token vazado virar controle total do servidor foi considerado inaceitável.
- **Token com hash no banco.** O WordPress nunca guarda o token em texto puro. Só quem gerou a conexão (você) tem o valor real, guardado localmente.
- **HTTPS obrigatório.** Requisições em HTTP puro são recusadas.
- **Escrita no banco exige confirmação explícita** por chamada (`confirm_write: true`), não é o comportamento padrão.
- **Rate limiting** no fluxo de configuração inicial, para dificultar tentativas de força bruta.

Isso não elimina o risco: quem tiver o token tem acesso real ao site. Trate-o como trataria uma senha de administrador: não compartilhe, regenere se suspeitar de vazamento, e instale só em sites que você administra.

## Licença

- `plugin/`: GPLv2 or later
- `mcp-server/`: MIT

Um projeto [ikoeh](https://ikoeh.com).
