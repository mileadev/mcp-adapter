# Local deployment and MCP host integration

MCP Adapter is the WordPress **server**. A separate MCP **host** drives Ollama, Bedrock or another model and connects over STDIO or HTTP. Do not install AWS/Ollama credentials into this plugin.

## Deployment

1. Install Docker, Composer and the Node version declared in `.nvmrc`.
2. `npm ci && composer install && npm run wp-env start`
3. `npm run wp-env:cli -- plugin activate mcp-adapter`
4. `npm run wp-env:cli -- mcp-adapter list`
5. Expose only the abilities needed by the agent. Confirm their WordPress `permission_callback` enforces the specific object-level authorization.

To connect a host running **on the same machine** to an installed WordPress site, use an absolute WordPress path and a dedicated WordPress account:

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "wp",
      "args": [
        "--path=/absolute/path/to/wordpress",
        "mcp-adapter",
        "serve",
        "--server=mcp-adapter-default-server",
        "--user=mcp-agent"
      ]
    }
  }
}
```

The JSON shape above is supported by clients using `mcpServers` for local subprocess definitions; other clients have different configuration schemas. Use your client's current documentation. Verify the `wp` binary, site path and user account before running.

**Security:** The host can launch the process as the WordPress identity specified by `--user`; use this only with trusted local clients and a dedicated least-privilege account. It is not a password-protected protocol boundary. Never run WordPress WP-CLI as root.

## HTTP integration

Default URL: `https://your-wordpress.example/wp-json/mcp/mcp-adapter-default-server`.

For clients requiring STDIO, use the upstream WordPress HTTP proxy:

```json
{
  "mcpServers": {
    "wordpress-remote": {
      "command": "npx",
      "args": ["--yes", "@automattic/mcp-wordpress-remote"],
      "env": {
        "WP_API_URL": "https://your-wordpress.example/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "mcp-agent",
        "WP_API_PASSWORD": "REPLACE_AT_RUNTIME"
      }
    }
  }
}
```

The placeholder **must** be injected by your local secret manager, never committed or shared. Pin and review a proxy version before deployment; the snippet deliberately avoids silently downloading `@latest`. For production, prefer the proxy's supported environment injection or managed credential store. Application Passwords must be scoped to a dedicated WordPress user and used over HTTPS.

### Origin validation

When an HTTP request includes `Origin`, it must match the site's REST origin by default. Reverse proxies with a different *public* hostname may register exact trusted origins:

```php
add_filter(
    'mcp_adapter_allowed_http_origins',
    static function ( array $origins ): array {
        $origins[] = 'https://trusted-agent.example';
        return $origins;
    }
);
```

Set this in an operational site plugin, not in the MCP Adapter itself. Do not use wildcards. Origin validation supplements WordPress authentication; it does not replace authentication, CSRF protections, or per-ability checks. A non-browser client can omit or forge `Origin`, so it is **not** an identity mechanism. A reverse proxy must preserve the public hostname/TLS and should restrict ingress, request sizes and rate limits.

## Harness compatibility matrix

| Host family | Connection strategy | Status |
| --- | --- | --- |
| Claude Desktop | STDIO configuration via `mcpServers` | Configuration pattern; end-to-end unverified |
| Claude Code | Register STDIO server through client's MCP command/configuration | Configuration pattern; end-to-end unverified |
| Cursor | STDIO MCP server or remote connection supported by client | Configuration pattern; end-to-end unverified |
| VS Code / GitHub Copilot | MCP server definition using its supported local/remote transport | Configuration pattern; end-to-end unverified |
| Continue / Cline / Roo Code | STDIO definition where client supports MCP | Configuration pattern; end-to-end unverified |
| Codex CLI | Add the executable MCP server in Codex's MCP configuration | Configuration pattern; end-to-end unverified |
| Other MCP-compliant hosts | STDIO, or authenticated HTTP POST when supported | Version/feature negotiation required |

**Not universal:** Each host may use a different configuration file, namespace, authorization mechanism, and MCP revision. HTTP GET/SSE is currently unsupported, and modern/legacy protocol projection is determined by the adapter's negotiated revisions. Host compatibility must be tested per version. Do not infer model-provider availability from MCP server connectivity.

## Smoke checks and operational acceptance

- `wp mcp-adapter list` returns the expected named server.
- STDIO: initialize → tools/list → a permitted, read-only tools/call → error on prohibited tool.
- Confirm STDOUT contains only protocol JSON and diagnostics go to STDERR.
- HTTP: unauthenticated POST must be rejected; authenticated POST with valid origin must work; cross-origin POST must return 403.
- Verify no unauthorized ability can be discovered or executed by a lower-privileged user.
- Stop the site: `npm run wp-env stop`. Back up database/content before testing write operations.

See [testing](testing.md) for PHPUnit and static-analysis commands.
