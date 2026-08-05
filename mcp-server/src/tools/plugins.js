import { z } from "zod";
import { readFileSync } from "node:fs";

export function registerPluginTools(server, client) {
  server.registerTool(
    "wp_list_plugins",
    {
      title: "List WordPress Plugins",
      description: "List all installed plugins on the connected site, with active status.",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/plugins");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_deploy_plugin",
    {
      title: "Deploy WordPress Plugin",
      description: "Upload a local plugin zip file and install it on the connected site.",
      inputSchema: {
        zipPath: z.string().describe("Absolute local path to the plugin .zip file"),
        target: z.enum(["plugins", "mu"]).default("plugins").describe("Install into wp-content/plugins or wp-content/mu-plugins"),
      },
    },
    async ({ zipPath, target }) => {
      const bytes = readFileSync(zipPath);
      const data = await client.request("POST", "/plugins", {
        params: target === "mu" ? { target: "mu" } : undefined,
        rawBody: bytes,
      });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_install_plugin_from_source",
    {
      title: "Install WordPress Plugin From WordPress.org or a URL",
      description:
        "Install a plugin server-side, without downloading it locally first. Use slug for a WordPress.org " +
        "plugin (the site looks up the current download link itself), or url for a direct zip URL.",
      inputSchema: {
        slug: z.string().optional().describe("WordPress.org plugin slug, e.g. \"elementor\""),
        url: z.string().optional().describe("Direct URL to a plugin zip, used if slug is not given"),
        target: z.enum(["plugins", "mu"]).default("plugins").describe("Install into wp-content/plugins or wp-content/mu-plugins"),
      },
    },
    async ({ slug, url, target }) => {
      const data = await client.request("POST", "/plugins", {
        json: { action: "install", slug, url, target },
      });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_activate_plugin",
    {
      title: "Activate WordPress Plugin",
      description: "Activate an installed plugin by slug.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("POST", "/plugins", { json: { action: "activate", slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_deactivate_plugin",
    {
      title: "Deactivate WordPress Plugin",
      description: "Deactivate an active plugin by slug.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("POST", "/plugins", { json: { action: "deactivate", slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_delete_plugin",
    {
      title: "Delete WordPress Plugin",
      description: "Permanently delete an installed plugin by slug.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("DELETE", "/plugins", { params: { slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
