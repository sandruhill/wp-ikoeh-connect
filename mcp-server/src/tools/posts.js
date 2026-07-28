import { z } from "zod";

export function registerPostsTools(server, client) {
  server.registerTool(
    "wp_list_posts",
    {
      title: "List Posts/Pages",
      description: "List existing posts/pages by type and status (defaults to any type, any status). Returns id, title, type, status for up to 100 results.",
      inputSchema: {
        type: z.string().optional(),
        status: z.string().optional(),
      },
    },
    async ({ type, status }) => {
      const data = await client.request("GET", "/posts", { params: { type, status } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_create_post",
    {
      title: "Create Post/Page",
      description: "Create a new post or page from scratch. status defaults to draft.",
      inputSchema: {
        title: z.string().min(1),
        type: z.string().min(1),
        status: z.string().optional(),
        content: z.string().optional(),
      },
    },
    async ({ title, type, status, content }) => {
      const data = await client.request("POST", "/posts", { json: { title, type, status, content } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
