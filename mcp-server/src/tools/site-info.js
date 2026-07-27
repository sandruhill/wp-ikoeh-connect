export function registerSiteInfoTools(server, client) {
  server.registerTool(
    "wp_site_info",
    {
      title: "WP Site Info",
      description: "Get WordPress/PHP version, active theme and active plugins for the connected site.",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("GET", "/site-info");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
