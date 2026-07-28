export class IkoehClient {
  constructor({ url, token }) {
    this.baseUrl = `${url}/wp-json/ikoeh-connect/v1`;
    this.token = token;
  }

  async request(method, path, { params, json, rawBody } = {}) {
    let fullUrl = `${this.baseUrl}${path}`;

    if (params) {
      const query = new URLSearchParams(params).toString();
      if (query) {
        fullUrl += `?${query}`;
      }
    }

    const headers = { Authorization: `Bearer ${this.token}` };
    let body;

    if (json !== undefined) {
      headers["Content-Type"] = "application/json";
      body = JSON.stringify(json);
    } else if (rawBody !== undefined) {
      headers["Content-Type"] = "application/zip";
      body = rawBody;
    }

    const response = await fetch(fullUrl, { method, headers, body });
    const text = await response.text();
    const data = text ? JSON.parse(text) : null;

    if (!response.ok) {
      const message = data && data.message ? data.message : response.statusText;
      throw new Error(`WP iKOEH Connect API error (${response.status}): ${message}`);
    }

    return data;
  }

  async requestRaw(method, path, { body, headers = {} } = {}) {
    const response = await fetch(`${this.baseUrl}${path}`, {
      method,
      headers: { Authorization: `Bearer ${this.token}`, ...headers },
      body,
    });
    const text = await response.text();
    const data = text ? JSON.parse(text) : null;

    if (!response.ok) {
      const message = data && data.message ? data.message : response.statusText;
      throw new Error(`WP iKOEH Connect API error (${response.status}): ${message}`);
    }

    return data;
  }
}
