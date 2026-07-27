import { z } from "zod";

export function registerLogTools(server, client) {
  server.registerTool(
    "wp_read_debug_log",
    {
      title: "Read WordPress Debug Log",
      description: "Tail the last N lines of wp-content/debug.log.",
      inputSchema: { lines: z.number().int().positive().max(1000).default(100) },
    },
    async ({ lines }) => {
      const data = await client.request("GET", "/logs/debug", { params: { lines } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
