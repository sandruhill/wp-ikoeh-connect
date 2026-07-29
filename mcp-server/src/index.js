#!/usr/bin/env node
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";

import { loadSiteConfig } from "./config.js";
import { IkoehClient } from "./client.js";
import { registerSiteInfoTools } from "./tools/site-info.js";
import { registerPluginTools } from "./tools/plugins.js";
import { registerContentTools } from "./tools/content.js";
import { registerDbTools } from "./tools/db.js";
import { registerLogTools } from "./tools/logs.js";
import { registerCacheTools } from "./tools/cache.js";
import { registerElementorTools } from "./tools/elementor.js";
import { registerThemeTools } from "./tools/theme.js";
import { registerMediaTools } from "./tools/media.js";
import { registerPostsTools } from "./tools/posts.js";
import { registerAdminAccessTools } from "./tools/admin-access.js";
import { registerGutenbergTools } from "./tools/gutenberg.js";

const siteName = process.argv[2];

if (!siteName) {
  console.error("Usage: node src/index.js <site-name>");
  console.error("Expects a config file at ~/.config/wp-ikoeh-connect/<site-name>.conf");
  process.exit(1);
}

const config = loadSiteConfig(siteName);
const client = new IkoehClient(config);

const server = new McpServer({ name: `wp-ikoeh-connect-${siteName}`, version: "0.1.0" });

registerSiteInfoTools(server, client);
registerPluginTools(server, client);
registerContentTools(server, client);
registerDbTools(server, client);
registerLogTools(server, client);
registerCacheTools(server, client);
registerElementorTools(server, client);
registerThemeTools(server, client);
registerMediaTools(server, client);
registerPostsTools(server, client);
registerAdminAccessTools(server, client);
registerGutenbergTools(server, client);

const transport = new StdioServerTransport();
await server.connect(transport);
