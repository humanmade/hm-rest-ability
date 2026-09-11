# HM REST Ability

OAuth2 discovery endpoints and a REST API ability, for exposing WordPress to
MCP clients (like Claude) via the official [MCP Adapter](https://github.com/WordPress/mcp-adapter)
plugin and the WordPress Abilities API.

## What it does

**OAuth2 discovery** (`inc/oauth2-discovery.php`)

- Serves `/.well-known/oauth-authorization-server` — RFC 8414 Authorization
  Server Metadata — so MCP clients can auto-discover the OAuth2 endpoints
  provided by the [WP-API/OAuth2](https://github.com/WP-API/OAuth2) plugin.
- Serves `/.well-known/oauth-protected-resource` — RFC 9728 Protected
  Resource Metadata — so clients can discover the authorization server from
  a `401` on the MCP endpoint.
- Adds a `WWW-Authenticate` header to `401` responses on MCP REST routes,
  pointing clients at the protected resource metadata.

**REST API ability** (`inc/rest-api-abilities.php`)

- Registers a single `rest-api/call` ability that lets an MCP client dispatch
  any internal WordPress REST API request (`GET`, `POST`, `PUT`, `PATCH`,
  `DELETE`, `OPTIONS`), instead of needing a bespoke ability per endpoint.
  Permissions are enforced by running the matched route's own
  `permission_callback`.
- Caps the response data at 50KB by default, so a large payload can't fill a
  client's context window. Oversized lists keep their leading items, oversized
  objects keep their smallest fields, and the result says what was left out.
  `_fields` is passed through to the request, so clients can ask for less up
  front.
- Gives clients a two-step way to find routes. `GET /` returns every route
  path and the methods it accepts, a few kilobytes instead of the ~1MB full
  index. `OPTIONS /wp/v2/posts` then returns that one route's parameters.
  Core only answers `OPTIONS` when serving a real HTTP request, so the ability
  builds the same description from the route table itself.

**Media upload ability** (`inc/media-abilities.php`)

- Registers a `media/upload` ability that takes a base64-encoded file and puts
  it in the media library, returning the attachment ID and URL. The REST API
  ability can't do this: it sends JSON params, and `POST /wp/v2/media` needs a
  request body plus `Content-Type` and `Content-Disposition` headers.
- Requires the `upload_files` capability, and `edit_post` when a parent post is
  given. Uploads are capped at the site's own limit, `wp_max_upload_size()`.

## Requirements

- WordPress 6.9+ (for the built-in [Abilities API](https://make.wordpress.org/core/))
- PHP 7.4+
- The [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin
  (`wordpress/mcp-adapter` on Packagist), declared as a dependency via the
  `Requires Plugins` header.

## Installation

Composer (recommended):

```bash
composer require humanmade/hm-rest-ability
```

Or download a [release ZIP](https://github.com/humanmade/hm-rest-ability/releases)
and upload it to `/wp-content/plugins/`.

Then activate both **MCP Adapter** and **HM REST Ability**.

## Filters

- `hm_oauth2_discovery_metadata` — filter the RFC 8414 authorization server
  metadata document.
- `hm_oauth2_protected_resource_metadata` — filter the RFC 9728 protected
  resource metadata document.
- `hm_rest_ability_max_response_bytes` — filter the maximum size, in bytes, of
  the response data returned for one `rest-api/call`. Defaults to `50000`; set
  it to `0` or less to disable trimming.
- `hm_rest_ability_max_upload_bytes` — filter the maximum size, in bytes, of a
  decoded `media/upload` file. Defaults to `wp_max_upload_size()`, the site's
  own limit; set it to `0` or less to remove the limit.
- `hm_rest_ability_login_wall_exemptions` — filter the login-wall callbacks
  removed from `.well-known/` requests (defaults to Human Made's Require
  Login plugin; no-ops elsewhere).

## Development

```bash
composer install
npm install
```

- `composer lint` / `composer format` — PHPCS / PHPCBF against the HM
  coding standard.
- `composer test` — PHPUnit unit tests (Brain Monkey, no WordPress load).
- `npm run test:e2e` — Playwright end-to-end tests against WordPress
  Playground.
- `npm run test:evals:scripted` — runs the eval harness with a fixed script
  instead of a model. Free, deterministic, and proves the harness itself works.
- `npm run test:evals` — runs the same scenarios with a small model, to check
  the tools can be used from their descriptions alone. Needs
  `ANTHROPIC_API_KEY`. Defaults to `claude-haiku-4-5`; override with
  `EVAL_MODEL`. Add `--repeat=3` to average over several runs.

## Release process

Releases are cut from the Actions tab: **Release** workflow → run with the
version to release (e.g. `0.2.0`). It stamps the version into the plugin
header, tags the commit, and publishes a GitHub release with a distributable
ZIP.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
