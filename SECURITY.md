# Security Policy

This is a fork of the WordPress MCP Adapter. Upstream WordPress security reports should follow the [WordPress HackerOne program](https://hackerone.com/wordpress). For fork-specific security reports, use GitHub private vulnerability reporting if available; do not disclose exploit details in public issues.

## Threat boundaries

- **STDIO:** WP-CLI's `--user` selects a WordPress identity; the process launcher must already be trusted. Never give an untrusted MCP host a privileged identity.
- **HTTP:** WordPress authentication and per-ability permissions are required. An optional `Origin` header is validated against same-origin or explicitly allowed origins; it does **not** authenticate non-browser clients.
- **Tools:** MCP ability exposure is opt-in. Ability callbacks must enforce object-level authorization, including write/delete permissions. Tool output is untrusted input to the model.
- **Secrets:** Keep WordPress Application Passwords, AWS credentials and Ollama Cloud tokens out of source files, client config checked into Git, URLs, and logs.
- **Network:** Require HTTPS for remote HTTP access; do not expose local WordPress, WP-CLI or the Ollama API to the public internet.

**Known limitation:** HTTP GET/SSE streaming is unsupported. Authentication and user permissions remain necessary for the supported HTTP methods. Review deployment controls before any remote exposure.
