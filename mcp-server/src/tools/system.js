import { z } from "zod";

export function registerSystemTools(server, client) {
  server.registerTool(
    "wp_read_system_file",
    {
      title: "Read System File",
      description: "Read a file from the WordPress server filesystem (confined to the WordPress root). Binary/non-UTF-8 content is returned base64-encoded.",
      inputSchema: {
        path: z.string(),
        offset: z.number().int().min(0).optional(),
        limit: z.number().int().optional(),
      },
    },
    async ({ path, offset, limit }) => {
      const data = await client.request("GET", "/system-file", { params: { path, offset, limit } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_write_system_file",
    {
      title: "Write System File",
      description:
        "Write UTF-8 text content to a file on the WordPress server filesystem (confined to the WordPress root). Writing a .php file is only allowed inside the sandbox directory (wp-content/ikoeh-sandbox/), which is auto-loaded on every request with basic crash detection -- other files can go anywhere under the WordPress root.",
      inputSchema: {
        path: z.string(),
        content: z.string(),
        mode: z.enum(["overwrite", "append"]).optional(),
      },
    },
    async ({ path, content, mode }) => {
      const data = await client.request("POST", "/system-file", { json: { path, content, mode } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_delete_system_file",
    {
      title: "Delete System File",
      description: "Delete a file from the WordPress server filesystem (confined to the WordPress root).",
      inputSchema: { path: z.string() },
    },
    async ({ path }) => {
      const data = await client.request("DELETE", "/system-file", { params: { path } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_list_system_directory",
    {
      title: "List System Directory",
      description: "List the files and subdirectories of a directory on the WordPress server filesystem (confined to the WordPress root).",
      inputSchema: { path: z.string() },
    },
    async ({ path }) => {
      const data = await client.request("GET", "/system-directory", { params: { path } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_disable_system_file",
    {
      title: "Disable Sandbox PHP File",
      description: "Rename a sandboxed PHP file (wp-content/ikoeh-sandbox/) to add a .disabled suffix, so it stops being loaded on each request without deleting it.",
      inputSchema: { path: z.string() },
    },
    async ({ path }) => {
      const data = await client.request("POST", "/system-disable-file", { json: { path } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_enable_system_file",
    {
      title: "Enable Sandbox PHP File",
      description: "Reverse wp_disable_system_file: rename a previously-disabled sandboxed PHP file back to its original name so it resumes being loaded on each request.",
      inputSchema: { path: z.string() },
    },
    async ({ path }) => {
      const data = await client.request("POST", "/system-enable-file", { json: { path } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
