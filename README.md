# HM REST Ability

A REST API ability, for exposing WordPress to MCP clients (like Claude) via
the official [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin
and the WordPress Abilities API.

## What it does

**REST API abilities** (`inc/rest-api-abilities.php`)

- Registers three abilities that let an MCP client dispatch any internal
  WordPress REST API request, instead of needing a bespoke ability per
  endpoint. Permissions are enforced by running the matched route's own
  `permission_callback`, so a user can only do through these abilities what
  their WordPress capabilities already allow.

  | Ability | Methods | `readOnlyHint` | `destructiveHint` | `idempotentHint` |
  |---|---|---|---|---|
  | `rest-api/read` | `GET`, `OPTIONS` | `true` | `false` | `true` |
  | `rest-api/write` | `POST`, `PUT`, `PATCH` | `false` | `false` | `false` |
  | `rest-api/delete` | `DELETE` | `false` | `true` | `false` |

  Splitting by method means an MCP client can gate each kind of request
  separately, for example auto-approving reads while asking for confirmation
  before a write or a delete. All three carry `openWorldHint: true`, since a
  route can be anything registered with WordPress, not a fixed set of
  operations.
- Caps the response data at 50KB by default, so a large payload can't fill a
  client's context window. Oversized lists keep their leading items, oversized
  objects keep their smallest fields, and the result says what was left out.
  `_fields` is passed through to the request, so clients can ask for less up
  front.
- Gives clients a two-step way to find routes. `GET /` (via `rest-api/read`)
  returns every route path and the methods it accepts, a few kilobytes instead
  of the ~1MB full index. `OPTIONS /wp/v2/posts` then returns that one route's
  parameters. Core only answers `OPTIONS` when serving a real HTTP request, so
  the ability builds the same description from the route table itself.
- Adds confirmation guidance where it matters. `rest-api/write` and
  `rest-api/delete` ask a client to confirm with the user before calling. An
  `OPTIONS` response also carries a `guidance` key for a route that changes
  site settings or access (`/wp/v2/settings`, `/wp/v2/users`, `/wp/v2/plugins`,
  and similar), or that deletes one — routine routes get nothing extra.
  This is advice, not enforcement: WordPress capabilities still decide what a
  user may do. Reclassify a route with the `hm_rest_ability_route_risk`
  filter, or add to the guidance text itself with `hm_rest_ability_route_guidance`.
  The plugin uses that second filter itself, as a working example: a route
  with a WordPress-style `status` field, such as `/wp/v2/posts`, gets a note
  that creating an item there already defaults to `draft` when `status` is
  omitted, so a client shouldn't set it to `publish` unless the user asked
  for that. Detected from the route's own schema (a `status` argument whose
  `enum` includes `publish`), not a hardcoded list of routes, so it covers
  custom post types too — see `inc/status-field-guidance.php`.
  A second module does the same for the `content` field: a route whose
  content is stored as block markup gets a note saying so, and pointing at
  `GET /wp/v2/block-types` for the blocks that site has registered — a route
  the same abilities can already call. Detected from the schema again (a
  `content` object with a `block_version` property, which core adds only for
  post types that support the editor) — see `inc/content-field-guidance.php`.

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

- `hm_rest_ability_max_response_bytes` — filter the maximum size, in bytes, of
  the response data returned for one `rest-api/read`, `rest-api/write`, or
  `rest-api/delete` call. Defaults to `50000`; set it to `0` or less to
  disable trimming.
- `hm_rest_ability_max_upload_bytes` — filter the maximum size, in bytes, of a
  decoded `media/upload` file. Defaults to `wp_max_upload_size()`, the site's
  own limit; set it to `0` or less to remove the limit.
- `hm_rest_ability_route_guidance` — filter the `guidance` text an `OPTIONS`
  response carries for a route, after the built-in risk-tier guidance is
  assembled (`$guidance, $route, $handlers`). Add to it, replace it, or
  return `''` to suppress it. Two modules hook it themselves:
  `inc/status-field-guidance.php` flags a publishable `status` field, and
  `inc/content-field-guidance.php` flags a block-markup `content` field.
  Remove either on its own, for example
  `remove_filter( 'hm_rest_ability_route_guidance', 'HM\StatusFieldGuidance\add_guidance' )`,
  or add your own hooked callback alongside them for anything else worth
  flagging.
- `hm_rest_ability_policy` — filter to `deny` a `rest-api/read`,
  `rest-api/write`, or `rest-api/delete` call after the matched route's own
  `permission_callback` has already allowed it. Runs
  after capabilities, so it can only narrow access, never grant access a
  user's capabilities would not otherwise allow. Allows everything by
  default. Example, blocking writes to settings, plugins and themes:

  ```php
  add_filter( 'hm_rest_ability_policy', function ( $decision, $method, $route, $params ) {
      if ( 'GET' === $method ) {
          return $decision;
      }

      $locked_down = [ '/wp/v2/settings', '/wp/v2/plugins', '/wp/v2/themes' ];

      foreach ( $locked_down as $prefix ) {
          if ( str_starts_with( $route, $prefix ) ) {
              return 'deny';
          }
      }

      return $decision;
  }, 10, 4 );
  ```

## Using this with an agent

The plugin tells an agent what a route is for and where to be careful. It
doesn't tell it how to write block markup, because that isn't the plugin's
job — and the tools that do it well live outside WordPress.

`skills/wordpress-block-content/SKILL.md` covers that gap. It's a skill file
for agent harnesses that read them, such as Claude Code. It explains the
two-step route lookup, that a post's `content` is block markup, how to upload
an image before referencing it, and it points at two Human Made npm packages:

- [`wesper`](https://github.com/humanmade/wesper) — collects one JSON manifest
  of what a site actually registers: block types, post types, bindable fields,
  patterns, theme.json.
- [`block-runner`](https://github.com/humanmade/block-runner) — turns HTML or
  a block tree into block markup, validated with Gutenberg's own packages.
  It ships its own skill, which the one here defers to.

Neither is required. The skill says what to do when they aren't installed.

The skill belongs in your project, not on the server, so it isn't in the
release ZIP or the Composer package. Copy it from a checkout of this repo
into your project's skills directory:

```bash
cp -r skills/wordpress-block-content /path/to/your-project/.claude/skills/
```

Then a prompt like this has what it needs:

> Add a case study page to the site for the Acme rebrand, with a heading, two
> paragraphs and the hero image from ./hero.jpg. Leave it as a draft.

### Putting it in the tool output instead

The skill is the default because tool descriptions are sent on every request,
and a site's agents may not have Node at all. If you'd rather the advice
travel with the tools, add it to the route guidance yourself:

```php
add_filter( 'hm_rest_ability_route_guidance', function ( $guidance, $route, $handlers ) {
    if ( ! \HM\ContentFieldGuidance\has_block_content_field( $handlers ) ) {
        return $guidance;
    }

    $note = 'Generate block markup with `npx block-runner convert`, not by hand.';

    return '' === $guidance ? $note : $guidance . ' ' . $note;
}, 10, 3 );
```

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

## Release process

Releases are cut from the Actions tab: **Release** workflow → run with the
version to release (e.g. `0.2.0`). It stamps the version into the plugin
header, tags the commit, and publishes a GitHub release with a distributable
ZIP.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
