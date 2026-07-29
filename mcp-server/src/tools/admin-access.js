import { z } from "zod";

export function registerAdminAccessTools(server, client) {
  server.registerTool(
    "wp_create_admin_access_link",
    {
      title: "Create Admin Access Link",
      description:
        "Create a temporary, one-time WordPress admin login for browser automation, logging in as the site's primary Administrator. Returns exchange_url/access_token/access_nonce and a curl_example: POST those headers to exchange_url to get a login_url, then open login_url in a browser tool immediately -- its nonce expires within 60 seconds and can only be used once.",
      inputSchema: {
        expires_in: z.number().int().min(30).max(600).optional(),
        session_expires_in: z.number().int().min(60).max(3600).optional(),
        admin_path: z.string().optional(),
      },
    },
    async ({ expires_in, session_expires_in, admin_path }) => {
      const data = await client.request("POST", "/admin-access", {
        json: { expires_in, session_expires_in, admin_path },
      });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
