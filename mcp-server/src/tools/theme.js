import { z } from "zod";

export function registerThemeTools(server, client) {
  server.registerTool(
    "wp_get_theme_info",
    {
      title: "Get Theme Info",
      description: "Get info about the active WordPress theme: name, whether it's a block theme, and child-theme parentage.",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/theme-info");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_get_theme_json",
    {
      title: "Get theme.json",
      description: "Get the active block theme's theme.json (colors, typography, spacing, layout).",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/theme-json");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_update_theme_json",
    {
      title: "Update theme.json",
      description: "Overwrite the active block theme's theme.json with a full replacement object.",
      inputSchema: { theme_json: z.record(z.any()) },
    },
    async ({ theme_json }) => {
      const data = await client.request("PUT", "/theme-json", { json: theme_json });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_activate_theme",
    {
      title: "Activate Theme",
      description: "Activate an already-installed theme by its slug.",
      inputSchema: { slug: z.string().min(1) },
    },
    async ({ slug }) => {
      const data = await client.request("POST", "/theme-activate", { json: { slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
