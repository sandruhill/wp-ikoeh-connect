import { z } from "zod";

export function registerSkillsTools(server, client) {
  server.registerTool(
    "wp_write_skill",
    {
      title: "Write Skill",
      description:
        "Create or update a reusable skill (a named markdown prompt/procedure stored on the WordPress site). The title is sanitized into a slug (lowercase, dash-separated) and is the only identifier. enable_prompt skills are exposed to MCP clients as selectable prompts; enable_agentic skills can be fetched on demand with wp_get_skill.",
      inputSchema: {
        title: z.string(),
        description: z.string(),
        content: z.string(),
        enable_prompt: z.boolean().optional(),
        enable_agentic: z.boolean().optional(),
        on_conflict: z.enum(["fail", "replace", "rename"]).optional(),
      },
    },
    async ({ title, description, content, enable_prompt, enable_agentic, on_conflict }) => {
      const data = await client.request("POST", "/skill", {
        json: { title, description, content, enable_prompt, enable_agentic, on_conflict },
      });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_list_skills",
    {
      title: "List Skills",
      description: "List all skills on this site (slug, description, flags -- not the full body).",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/skills");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_get_skill",
    {
      title: "Get Skill",
      description: "Fetch one skill's full content by slug. Use this to read an enable_agentic skill's instructions and follow them.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("GET", "/skill", { params: { slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );

  server.registerTool(
    "wp_delete_skill",
    {
      title: "Delete Skill",
      description: "Delete a skill by slug.",
      inputSchema: { slug: z.string() },
    },
    async ({ slug }) => {
      const data = await client.request("DELETE", "/skill", { params: { slug } });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
