# Review

## What failure looks like here

- A REST call reaches an endpoint the current user could not reach over HTTP: the endpoint's own `permission_callback` is skipped, run on a request prepared differently from core, or overridden by the policy filter in the allow direction.
- A destructive or site-config call runs without the confirmation it should get (`inc/confirmation.php`, `inc/route-risk.php`).
- An MCP client (usually an LLM) gets a vague or misleading error and cannot correct itself. Tool errors surface only `WP_Error::get_error_message()` through the MCP adapter; error data is dropped.
- Responses blow past the size cap, or the route index stops fitting under it.
- A valid REST response comes back as an error, or in a shape that breaks the tool's declared schema (core validates ability output against `output_schema`; clients may validate `structuredContent` against `outputSchema`).
- Hooks added at the wrong time, so abilities, MCP tools or filters silently never register.

## Always check

- Any code that builds or inspects a `WP_REST_Request` matches what `WP_REST_Server::dispatch()` / `match_request_to_handler()` do, or goes through `rest_do_request()`.
- Ability-backed tools (`rest-api/read`) and direct tools (`rest-api-write`, `rest-api-delete`, built in `inc/confirmation.php`) both get the change; they are built on different paths.
- Result shapes match the MCP Adapter in `vendor/wordpress/mcp-adapter` (ToolsHandler result shaping), including PHP `[]` encoding as a JSON list.
- Hook timing matches WordPress load order (`after_setup_theme`, `init`, `rest_api_init`, `wp_abilities_api_init`).
- Permission paths return `WP_Error` with a message that tells the client what to do next.
- PHPUnit stubs in `tests/phpunit/wp-stubs.php` stay close enough to core that a new test fails without the fix.
- New behaviour visible to MCP clients has an e2e test in `tests/e2e/` (runs in CI on Playground).
- `CHANGELOG.md` gets an entry; the release workflow tags `main` as-is, so a release PR puts it under a dated version heading.

## Paths that need a person

- `inc/rest-api-abilities.php` permission and policy functions (`check_permission`, `endpoint_permission`, `apply_policy`)
- `inc/confirmation.php` (signed state, elicitation)
- `.github/workflows/`

## Skip

- `vendor/`, `composer.lock`, `package-lock.json`

## Nits

Cap of 3. Public repo: no client names, sites or domains anywhere.

## Learned

- 2026-10-29: `blueprint.json` holds test-only mu-plugins on purpose. Only the e2e suite and local `npm run playground:start` use it, and `.gitattributes` keeps it out of the release ZIP, so adding fixtures there is fine.
- 2026-10-29: Object `data` skipping the size cap in `cap_response_data()` is known and was left out of scope on purpose; don't re-raise it against unrelated changes.
