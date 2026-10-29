# Review

## What failure looks like here
- An MCP tool call that the REST API would allow gets refused, or one it would refuse gets through (permission, policy and confirmation checks in `inc/rest-api-abilities.php` and `inc/confirmation.php`).
- A risky write or delete runs without the user's confirmation on a client that supports elicitation.
- A valid REST response comes back to the client as an error, or in a shape that breaks the tool's declared schema (core validates ability output against `output_schema`; clients may validate `structuredContent` against `outputSchema`).
- Hooks added at the wrong time, so abilities, MCP tools or filters silently never register.

## Always check
- Ability-backed tools (`rest-api/read`) and direct tools (`rest-api-write`, `rest-api-delete` built in `inc/confirmation.php`) both get the change; they are built on different paths.
- Result shapes against the MCP Adapter in `vendor/wordpress/mcp-adapter` (ToolsHandler result shaping, 2025-11-25 and 2026-07-28 projections), including PHP `[]` encoding as a JSON list.
- Hook timing against WordPress load order (`plugins_loaded`, `after_setup_theme`, `init`, `rest_api_init`, `wp_abilities_api_init`).
- Unit tests stub WordPress with Brain Monkey; the Playwright suite runs on WP 6.9 in Playground (`blueprint.json`). Say which layer would catch a reported bug.
- CHANGELOG `[Unreleased]` and README are updated for user-visible changes.
- CI runs PHPCS (`composer lint`), PHPUnit across a PHP matrix (`composer test`) and Playwright e2e.

## Paths that need a person
- `inc/confirmation.php` (HMAC-signed confirmation state, elicitation)
- `check_permission()` and the `hm_rest_ability_policy` filter in `inc/rest-api-abilities.php`
- `.github/workflows/`, especially `release.yml`

## Skip
- `vendor/`, `node_modules/`, `composer.lock`, `package-lock.json`, `artifacts/`, `test-results/`

## Nits
- Agent-facing text (tool descriptions, hints, guidance) should be accurate and actionable; wrong advice to an agent costs a round trip.
- Cap: 3.

## Learned
- 2026-10-29: `blueprint.json` holds test-only mu-plugins on purpose. Only the e2e suite and local `npm run playground:start` use it, and `.gitattributes` keeps it out of the release ZIP, so adding fixtures there is fine.
- 2026-10-29: Object `data` skipping the size cap in `cap_response_data()` is known and was left out of scope on purpose; don't re-raise it against unrelated changes.
