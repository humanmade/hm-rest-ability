# HM REST Ability

Give MCP clients such as Claude the whole WordPress REST API through three tools. Any route a plugin registers works straight away, even if the plugin defines no abilities. Built on the [Abilities API](https://make.wordpress.org/core/) and the official [MCP Adapter](https://github.com/WordPress/mcp-adapter).

## Features

### Works with every REST route

Most plugins have REST endpoints but no abilities. This plugin dispatches any internal REST request, so an agent can use core, WooCommerce, or your own custom routes without writing an ability for each one. Each call runs the route's own `permission_callback`, so a user can only do what their WordPress capabilities already allow.

| MCP tool | Ability | Methods | Annotations |
|---|---|---|---|
| `rest-api-read` | `rest-api/read` | `GET`, `OPTIONS` | read-only, idempotent |
| `rest-api-write` | `rest-api/write` | `POST`, `PUT`, `PATCH` | — |
| `rest-api-delete` | `rest-api/delete` | `DELETE` | destructive |

The split lets a client auto-approve reads and still ask before a write or a delete.

### Low context

- Three tools, not one per endpoint, so the tool list stays small.
- `GET /` returns a condensed route index of a few kilobytes, not the ~1MB core index. On a large site it falls back to a count per namespace and points at `GET /<namespace>`.
- `OPTIONS /wp/v2/posts` returns one route's parameters, only when the agent needs them.
- Responses are capped at 50KB. A trimmed response says what was left out and how to narrow it with `_fields`. Oversized GET responses also supply `truncated.response_cursor`. Call the read tool with that cursor and the same route and params, concatenate the decoded `base64-json` chunks in byte-offset order, verify `total_bytes` and `sha256`, then parse the JSON. Continue until `response.next_cursor` is null. A 409 means the response changed and the read must restart. Cursors never bypass current permissions.
- Truncated responses include `truncated.sha256` for the complete, JSON-encoded native data. A client can verify an oversized write response by reading its saved resource and matching those bytes. This never repeats the write; a different representation or changed resource cannot establish the original write result.
- An oversized core REST batch can return `truncated.batch_receipt: true`. Its `data.responses` retains one status per request in the original order and omits item bodies and headers. A validation failure keeps `data.failed: "validation"` and a null entry for each skipped request. Inspect every item status before reporting success or considering a retry.
- `_embed` follows WordPress’s normal behaviour, including named relations such as `_embed=author`.
- An unknown route returns the nearest real routes, so the agent doesn't have to search again.

### Built-in guardrails

- Delete is always marked destructive, and the write and delete tools ask the client to confirm with the user.
- Routes are rated `routine`, `site-config` (settings, users, plugins, themes) or `irreversible`, and `OPTIONS` adds guidance to the risky ones.
- Route guidance steers the agent away from common mistakes: new posts stay drafts unless the user asks to publish, `content` is block markup, and core's design routes explain where their IDs come from.
- The plugin switches itself off when the site disables AI through `WP_AI_SUPPORT` or the `wp_supports_ai` filter (WordPress 7.0+).

### Confirmation through elicitation

On clients that support MCP elicitation (2026-07-28), a delete or a write to a risky route first shows the user a confirmation form. Nothing changes until they accept. The confirmation only works for the same user, route and params, and expires after ten minutes. Routine writes, such as editing a post, run straight away. Clients without elicitation run every call straight away.

### Your own guardrails

Filters let you tighten access or change the advice, without forking the plugin. See [Filters](#filters).

### Extras

- `media/upload` takes a base64 file and adds it to the media library, which the REST tools can't do with JSON params.
- `GET /hm-rest-ability/v1/url-lookup?url=…` finds the post or file at a URL, including a production URL on a staging site.

## Requirements

- WordPress 6.9+
- PHP 8.2+
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) 0.7.0+. WordPress can't check the version of a required plugin, so check it yourself.

## Installation

```bash
composer require humanmade/hm-rest-ability
```

Or upload a [release ZIP](https://github.com/humanmade/hm-rest-ability/releases) to `/wp-content/plugins/`. Then activate **MCP Adapter** and **HM REST Ability**.

## Filters

| Filter | Use it to |
|---|---|
| `hm_rest_ability_policy` | Deny a call that capabilities would allow. It can only narrow access. |
| `hm_rest_ability_route_risk` | Change a route's risk rating. |
| `hm_rest_ability_needs_confirmation` | Change which calls ask the user to confirm. |
| `hm_rest_ability_route_guidance` | Add to, replace or remove the guidance on a route. |
| `hm_rest_ability_max_response_bytes` | Change the 50KB response cap. `0` turns it off. |
| `hm_rest_ability_max_upload_bytes` | Change the upload limit. Defaults to `wp_max_upload_size()`. |

For example, to block writes to settings, plugins and themes:

```php
add_filter( 'hm_rest_ability_policy', function ( $decision, $method, $route, $params ) {
	if ( 'GET' === $method ) {
		return $decision;
	}

	foreach ( [ '/wp/v2/settings', '/wp/v2/plugins', '/wp/v2/themes' ] as $prefix ) {
		if ( str_starts_with( $route, $prefix ) ) {
			return 'deny';
		}
	}

	return $decision;
}, 10, 4 );
```

## Agent skill

`skills/wordpress-block-content` is a skill for agent harnesses such as Claude Code. It explains route discovery, block markup and image uploads, and points at [`wesper`](https://github.com/humanmade/wesper) and [`block-runner`](https://github.com/humanmade/block-runner) when they are installed. It isn't in the release ZIP or the Composer package, so copy it into your project:

```bash
cp -r skills/wordpress-block-content /path/to/your-project/.claude/skills/
```

## Development

```bash
composer install
npm install
```

- `composer lint` / `composer format` run PHPCS and PHPCBF.
- `composer test` runs the PHPUnit unit tests.
- `npm run test:e2e` runs the Playwright tests against WordPress Playground.

Releases are cut from the **Release** workflow in the Actions tab.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
