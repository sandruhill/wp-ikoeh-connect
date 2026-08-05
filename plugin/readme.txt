=== WP iKOEH Connect ===
Contributors: ikoeh
Tags: ai, claude, rest-api, automation
Requires at least: 5.6
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conecta o Claude a este site WordPress para desenvolvimento e otimização assistida por IA, via uma API própria autenticada por token.

== Description ==

WP iKOEH Connect expõe uma REST API própria (`/wp-json/ikoeh-connect/v1/...`), autenticada por token, para gestão de plugins, edição de conteúdo, consultas ao banco e diagnóstico. Veja https://github.com/sandruhill/wp-ikoeh-connect para documentação completa.

== Installation ==

1. Envie a pasta do plugin para `wp-content/plugins/` e ative em Plugins, OU solte o arquivo principal em `wp-content/mu-plugins/` para carregamento automático sem ativação.
2. Acesse "iKOEH Connect" no menu do wp-admin para gerar o token de acesso (instalações com wp-admin), ou use o endpoint `/setup` (instalações mu-plugin sem wp-admin, veja o README do repositório).

== Changelog ==

= 0.1.0 =
* Primeira versão.
