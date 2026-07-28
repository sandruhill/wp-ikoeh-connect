import { z } from "zod";

export function registerElementorTools(server, client) {
  server.registerTool(
    "wp_list_elementor_widgets",
    {
      title: "List Elementor Widgets",
      description: "List all Elementor widget types on this site, or get the full control schema for one widget by passing its name.",
      inputSchema: { type: z.string().optional() },
    },
    async ({ type }) => {
      const data = await client.request("GET", "/elementor-widgets", { params: type ? { type } : undefined });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_list_elementor_templates",
    {
      title: "List Elementor Templates",
      description: "List saved Elementor Library templates (pages, sections, containers).",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/elementor-templates");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
