export function registerCacheTools(server, client) {
  server.registerTool(
    "wp_flush_cache",
    {
      title: "Flush WordPress Cache",
      description: "Flush the WordPress object cache on the connected site.",
      inputSchema: {},
    },
    async () => {
      const data = await client.request("POST", "/cache/flush");
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
