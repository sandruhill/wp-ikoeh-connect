import { z } from "zod";

export function registerDbTools(server, client) {
  server.registerTool(
    "wp_db_query",
    {
      title: "Run WordPress DB Query",
      description:
        "Run a SQL query against the site's database. Read queries (SELECT/SHOW/DESCRIBE/EXPLAIN) run directly. " +
        "Write queries (INSERT/UPDATE/DELETE/etc) are rejected unless confirmWrite is true.",
      inputSchema: {
        sql: z.string(),
        confirmWrite: z.boolean().default(false),
      },
    },
    async ({ sql, confirmWrite }) => {
      const data = await client.request("POST", "/dbquery", {
        json: { sql, confirm_write: confirmWrite },
      });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
