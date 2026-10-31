# Engineering audit and decisions

Baseline inspected: `trunk@55c17cecc78fde859122367d7c198d6277aadb8e` (2026-10-31).

## Verified findings

| Area | Evidence | Disposition |
| --- | --- | --- |
| Architecture | PHP WordPress plugin, Composer dependency on `wordpress/php-mcp-schema`; existing HTTP and STDIO transport classes | Preserve existing upstream protocol layers |
| HTTP Origin | `HttpTransport::check_permission` previously invoked callbacks without an Origin gate | Add same-origin/explicit allowlist validation, fail closed for malformed cross-origin requests, test order |
| Exception disclosure | `ExecuteAbilityAbility::execute` returned raw exception text to the MCP caller | Return generic error on unhandled exceptions; retain controlled WordPress error messages |
| CLI documentation | PHPDoc referenced nonexistent `wp mcp serve`; registered command is `wp mcp-adapter` | Correct the examples |
| Permission documentation | `docs/guides/transport-permissions.md` describes `is_user_logged_in()` and exception fallback, but code requires `current_user_can('read')` and returns false on exceptions | Correct docs; preserve fail-closed code |
| Model integration | Plugin exposes tools, resources, prompts; no direct model provider integration required | Document Ollama local/Cloud and Bedrock host-side configuration rather than adding API keys to WordPress |
| HTTP feature gap | `HttpTransport.php` returns 405 for GET (no SSE) | Document limitations; don't assert universal harness compatibility |

## Security and testing gates

- PHP/WordPress matrix via GitHub Actions; PHPCS; PHPStan; PHPUnit.
- New tests: accepted/rejected/absent Origin, custom permission callback short-circuit, sanitized exceptions.
- Still required: manual E2E against an installed WordPress site and each chosen MCP host; dependency and release review; reverse proxy deployment review.
- Deployment credential posture: least-privilege WordPress account, TLS on remote connections, AWS role/credential chain, host-side provider secrets.
- No changes to default tool exposure, protocol revision negotiation, HTTP wire projections or session persistence to minimize regression risk.

## Validation constraints

Local container does not have Docker or Composer and cannot resolve github.com, so it cannot run the complete WordPress integration suite. Connector-side Git writes and GitHub source readbacks prove only repository persistence, not test success. CI results need independent confirmation. 
