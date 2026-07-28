import { z } from "zod";

export function registerMediaTools(server, client) {
  server.registerTool(
    "wp_upload_media",
    {
      title: "Upload Media",
      description: "Upload an image to the WordPress media library. Provide the image as base64-encoded content; returns the new attachment's ID and public URL for use in Elementor widgets or post content.",
      inputSchema: {
        filename: z.string().min(1),
        content_base64: z.string().min(1),
      },
    },
    async ({ filename, content_base64 }) => {
      const buffer = Buffer.from(content_base64, "base64");
      const data = await client.requestRaw("POST", "/media", {
        body: buffer,
        headers: { "X-Filename": filename },
      });
      return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
    }
  );
}
