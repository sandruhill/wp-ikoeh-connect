import { z } from "zod";

export function registerContentTools(server, client) {
  server.registerTool(
    "wp_get_content",
    {
      title: "Get WordPress Content",
      description: "Get a post/page's title, content, status and meta (including Elementor data) by ID.",
      inputSchema: { id: z.number().int().positive() },
    },
    async ({ id }) => {
      const data = await client.request("GET", "/content", { params: { id } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_update_content",
    {
      title: "Update WordPress Content",
      description: "Update a post/page's title, content and/or meta by ID.",
      inputSchema: {
        id: z.number().int().positive(),
        title: z.string().optional(),
        content: z.string().optional(),
        meta: z.record(z.any()).optional(),
      },
    },
    async ({ id, title, content, meta }) => {
      const data = await client.request("PUT", "/content", {
        params: { id },
        json: { title, content, meta },
      });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
