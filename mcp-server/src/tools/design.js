import { z } from "zod";

export function registerDesignTools(server, client) {
  server.registerTool(
    "wp_save_design",
    {
      title: "Save Design",
      description:
        "Save a design system document (brand colors, typography, spacing, and guidance as structured markdown) to this site. Provide the full markdown content; the slug is derived from the front matter's name: field unless explicitly given.",
      inputSchema: { content: z.string(), slug: z.string().optional() },
    },
    async ({ content, slug }) => {
      const data = await client.request("POST", "/design", { json: { content, slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_get_design",
    {
      title: "Get Design",
      description: "Fetch one design document by slug, including its raw markdown and already-extracted tokens.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("GET", "/design", { params: { slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_list_design_library",
    {
      title: "List Design Library",
      description: "List all saved design documents on this site (slug, name, description -- not the full markdown).",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/design-library");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_delete_design",
    {
      title: "Delete Design",
      description: "Delete a design document by slug. If it was the active design, no design is left active.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("DELETE", "/design", { params: { slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_activate_design",
    {
      title: "Activate Design",
      description: "Mark a saved design as the active one for this site.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("POST", "/design-activate", { json: { slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_get_active_design",
    {
      title: "Get Active Design",
      description: "Fetch the currently-active design document (tokens + guidance), or null if none is active. Check this before building new pages so new content matches the brand.",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/design-active");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_check_design",
    {
      title: "Check Design Readiness",
      description: "Check whether a saved design has the minimum tokens (at least one color and one typography entry) needed before activating it.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("GET", "/design-check", { params: { slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
