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

  server.registerTool(
    "wp_get_elementor_page",
    {
      title: "Get Elementor Page",
      description: "Get a post/page's Elementor element tree, already decoded from JSON.",
      inputSchema: { id: z.number().int().positive() },
    },
    async ({ id }) => {
      const data = await client.request("GET", "/elementor-content", { params: { id } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_write_elementor_page",
    {
      title: "Write Elementor Page",
      description: "Write a post/page's Elementor element tree (containers/widgets). Server-side normalization fills in required schema fields and clears Elementor's render caches automatically.",
      inputSchema: {
        id: z.number().int().positive(),
        elements: z.array(z.record(z.any())),
      },
    },
    async ({ id, elements }) => {
      const data = await client.request("PUT", "/elementor-content", { params: { id }, json: { elements } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
