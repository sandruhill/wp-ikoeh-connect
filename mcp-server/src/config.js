import { readFileSync, existsSync } from "node:fs";
import { homedir } from "node:os";
import { join } from "node:path";

export function loadSiteConfig(siteName) {
  const path = join(homedir(), ".config", "wp-ikoeh-connect", `${siteName}.conf`);

  if (!existsSync(path)) {
    throw new Error(`Config file not found: ${path}`);
  }

  const raw = readFileSync(path, "utf8");
  const config = {};

  for (const line of raw.split("\n")) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith("#")) {
      continue;
    }
    const [key, ...rest] = trimmed.split("=");
    config[key.trim()] = rest.join("=").trim();
  }

  if (!config.url || !config.token) {
    throw new Error(`Config file ${path} must define "url" and "token".`);
  }

  return { url: config.url.replace(/\/$/, ""), token: config.token };
}
