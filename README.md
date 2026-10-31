# MCP Adapter for WordPress

[![Upstream](https://img.shields.io/badge/upstream-WordPress%2Fmcp--adapter-3858e9)](https://github.com/WordPress/mcp-adapter) · [MCP specification](https://modelcontextprotocol.io/specification/) · GPL-2.0-or-later

Expose opted-in WordPress Abilities API operations as MCP tools, resources and prompts. This fork preserves the upstream PHP plugin and adds hardened local deployment guidance for model-powered MCP clients.

**Scope:** This repository is an **MCP server**, not a model inference gateway. Ollama (local or Cloud) and Amazon Bedrock are configured in the **host/agent** that consumes its tools. No model provider API keys belong in WordPress.

## Requirements

- WordPress 6.9+, PHP 7.4+ (see CI for tested combinations)
- Composer dependencies installed (the source checkout does not contain `vendor/`)
- For development: Docker and the Node.js version in `.nvmrc`

## Local quick start

```bash
git clone https://github.com/mileadev/mcp-adapter.git
cd mcp-adapter
nvm use
npm ci
composer install
npm run wp-env start
npm run wp-env:cli -- plugin activate mcp-adapter
npm run wp-env:cli -- mcp-adapter list
```

Local WordPress: `http://localhost:8888`. Development credentials are provided by `wp-env`; do **not** reuse them outside development. If the server does not appear, see [installation](docs/getting-started/installation.md) and [troubleshooting](docs/troubleshooting/common-issues.md).

**STDIO:** run `wp --path=/absolute/path/to/wordpress mcp-adapter serve --server=mcp-adapter-default-server --user=<least-privileged-user>`. Configure this command in an MCP host. The client running the process must be trusted: `--user` selects the WordPress execution identity, it does not authenticate the subprocess.

**HTTP:** `/wp-json/mcp/mcp-adapter-default-server`. Use an authenticated WordPress REST client or a supported local proxy. HTTP GET/SSE streaming is **not** implemented; compatibility is limited to supported request/response flows.

## Integration

- [Local deployment, security and host configurations](docs/guides/local-harnesses.md)
- [Ollama local / Ollama Cloud / AWS Bedrock architecture](docs/guides/model-providers.md)
- [Abilities, exposure opt-in and permission model](docs/guides/creating-abilities.md)
- [CLI and HTTP proxy](docs/guides/cli-usage.md)
- [Architecture](docs/architecture/overview.md)

MCP tools are exposed only when explicitly marked `meta.mcp.public` (or `meta.public`). **Public** here means discoverable through MCP by an authorized caller; it does not remove WordPress authorization. Tools execute with the caller's WordPress capabilities, including any write capabilities granted to that user.

## Validation

```bash
npm run wp-env:test start
npm run test:php
npm run lint:php
npm run lint:php:stan
```

CI checks PHP versions, WordPress compatibility, code style and static analysis; see [testing](docs/guides/testing.md). Use a nonproduction WordPress instance to test write-capable tools.

## Security / limitations

- Prefer STDIO for single-machine clients and **dedicated least-privilege WordPress users**.
- HTTP clients need WordPress authentication (for example, Application Passwords over TLS). Incoming `Origin` headers are checked against the WordPress REST origin or an explicit allowlist; requests without `Origin` remain supported for nonbrowser clients.
- Do not publish the development site or embed credentials in tracked client configuration.
- HTTP SSE/GET streaming, OAuth discovery and blanket compatibility with all MCP harnesses are **not claimed**.
- Report security issues per [SECURITY.md](SECURITY.md). Upstream project: [WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter).
