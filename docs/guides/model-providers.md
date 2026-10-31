# Using Ollama and Amazon Bedrock with MCP Adapter

This WordPress plugin exposes MCP tools. It does **not** call model endpoints. An MCP **host** must orchestrate model inference and MCP tool execution. This design keeps model credentials and token spend out of WordPress and allows multiple providers without modifying the plugin.

## Local Ollama (OSS, offline-capable)

Run Ollama on the workstation or a protected LAN server. A separate host must support both Ollama inference and MCP tool calling. Configure the host's Ollama base URL (typically `http://127.0.0.1:11434`) and a model that supports tool calls. Install the WordPress STDIO MCP definition from [local-harnesses.md](local-harnesses.md).

Test model inference and MCP connectivity independently. A successful `ollama list` does not mean your host successfully invoked an MCP tool. Do not bind Ollama to public interfaces without access controls.

## Ollama Cloud (host-dependent)

Configure the chosen host with an Ollama account/API credential and a cloud-compatible model following **current official Ollama documentation**. Hosted-model routing and support differ among clients and model versions; avoid hardcoding an undocumented URL or assuming local Ollama model names are usable in the cloud.

Keep API tokens in the host's secret manager. WordPress requires **no** Ollama Cloud token. Validate that the host can call `tools/list` and issue an actual tool call before claiming support.

## Amazon Bedrock (managed, usage-based)

Run an MCP-capable host using the AWS SDK/Bedrock Converse or another documented Bedrock tool-calling interface. The host translates MCP tool schemas into the provider's tool declarations and executes tool calls through its connected MCP client. Bedrock itself is **not** an MCP server and this plugin is not a Bedrock gateway.

- Configure region, model ID and explicit least-privilege IAM credentials in the host.
- Limit IAM to the specific model invocation actions/resources the host needs.
- Use SDK default credential chains or roles rather than embedded AWS access keys.
- Set budgets, quotas and timeouts; Bedrock usage can incur charges.
- Confirm the selected model supports tool use and the schema supported by your host.

## Shared tool-use checklist

1. Connect the host to WordPress via MCP STDIO or authenticated HTTP.
2. Confirm the tool list matches explicitly exposed WordPress abilities.
3. Run an authorized read-only call and verify its response.
4. Run an unauthorized call and verify denial.
5. Require human approval for consequential writes/deletes at the host layer.
6. Log correlation IDs, latency, outcomes and authorization decisions without credentials, request bodies or personal data.
7. Test loss of the WordPress endpoint, invalid credentials, model timeouts and schema mismatches.

**Data boundary:** MCP tool output sent to any cloud model can leave the local environment. Use local inference for sensitive content where required, and verify the selected provider/host's actual data-handling commitments before enabling cloud model access.

References: [Ollama docs](https://docs.ollama.com/) · [AWS Bedrock Converse](https://docs.aws.amazon.com/bedrock/latest/userguide/conversation-inference-call.html) · [MCP specification](https://modelcontextprotocol.io/specification/).
