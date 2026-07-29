import { z } from "zod";

const blockSpecSchema = z.object({
  name: z.string(),
  attributes: z.record(z.any()).optional(),
  innerBlocks: z.array(z.any()).optional(),
});

export function registerGutenbergTools(server, client) {
  server.registerTool(
    "wp_create_gutenberg_batch",
    {
      title: "Create Gutenberg Pending Batch",
      description:
        "Create a new pending-change batch for Gutenberg (block editor) content. Nothing goes live until wp_enable_gutenberg_finalization is called AND a human keeps the 'Fila de Blocos' wp-admin page open to validate and apply it.",
      inputSchema: { label: z.string().optional(), agent_note: z.string().optional() },
    },
    async ({ label, agent_note }) => {
      const data = await client.request("POST", "/gutenberg-batch", { json: { label, agent_note } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_add_gutenberg_change",
    {
      title: "Add Gutenberg Pending Change",
      description: "Queue a block-content change for one target post/page inside an existing pending batch.",
      inputSchema: {
        batch_id: z.number().int().positive(),
        target_id: z.number().int().positive(),
        target_type: z.string().optional(),
        operation: z.string().optional(),
        block_spec: z.array(blockSpecSchema),
      },
    },
    async ({ batch_id, target_id, target_type, operation, block_spec }) => {
      const data = await client.request("POST", "/gutenberg-item", {
        json: { batch_id, target_id, target_type, operation, block_spec },
      });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_list_gutenberg_batches",
    {
      title: "List Gutenberg Pending Batches",
      description: "List Gutenberg pending-change batches, optionally filtered by status.",
      inputSchema: { status: z.string().optional() },
    },
    async ({ status }) => {
      const data = await client.request("GET", "/gutenberg-batches", { params: status ? { status } : undefined });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_get_gutenberg_batch",
    {
      title: "Get Gutenberg Pending Batch",
      description: "Get one Gutenberg pending-change batch and its items.",
      inputSchema: { id: z.number().int().positive() },
    },
    async ({ id }) => {
      const data = await client.request("GET", "/gutenberg-batch", { params: { id } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_delete_gutenberg_batch",
    {
      title: "Cancel Gutenberg Pending Batch",
      description: "Cancel a Gutenberg pending-change batch and every non-terminal item in it.",
      inputSchema: { id: z.number().int().positive() },
    },
    async ({ id }) => {
      const data = await client.request("DELETE", "/gutenberg-batch", { params: { id } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_delete_gutenberg_change",
    {
      title: "Cancel Gutenberg Pending Change",
      description: "Cancel a single pending Gutenberg item.",
      inputSchema: { id: z.number().int().positive() },
    },
    async ({ id }) => {
      const data = await client.request("DELETE", "/gutenberg-item", { params: { id } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_enable_gutenberg_finalization",
    {
      title: "Enable Gutenberg Batch Finalization",
      description:
        "Mark a Gutenberg pending batch ready for finalization. A human must keep the 'Fila de Blocos' wp-admin page open for it to actually be validated and applied.",
      inputSchema: { batch_id: z.number().int().positive() },
    },
    async ({ batch_id }) => {
      const data = await client.request("POST", "/gutenberg-enable-finalization", { json: { batch_id } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_get_gutenberg_content",
    {
      title: "Get Gutenberg Content",
      description: "Read a post/page's current Gutenberg blocks (parsed, not the pending-change queue).",
      inputSchema: { id: z.number().int().positive() },
    },
    async ({ id }) => {
      const data = await client.request("GET", "/gutenberg-content", { params: { id } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
