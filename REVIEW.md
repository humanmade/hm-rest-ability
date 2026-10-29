# Review

## What failure looks like here

- A REST call reaches an endpoint the current user could not reach over HTTP: the endpoint's own `permission_callback` is skipped, run on a request prepared differently from core, or overridden by the policy filter in the allow direction.
- A destructive or site-config call runs without the confirmation it should get (`inc/confirmation.php`, `inc/route-risk.php`).
- An MCP client (usually an LLM) gets a vague or misleading error and cannot correct itself. Tool errors surface only `WP_Error::get_error_message()` through the MCP adapter; error data is dropped.
- Responses blow past the size cap, or the route index stops fitting under it.

## Always check

- Any code that builds or inspects a `WP_REST_Request` matches what `WP_REST_Server::dispatch()` / `match_request_to_handler()` do, or goes through `rest_do_request()`.
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

