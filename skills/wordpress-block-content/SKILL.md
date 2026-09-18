---
name: wordpress-block-content
description: Create or edit WordPress posts, pages and media through the HM REST Ability MCP tools (rest-api/read, rest-api/write, rest-api/delete, media/upload). Use when writing post or page content, producing Gutenberg block markup, uploading an image to the media library, or finding the right REST route for a task.
---

# WordPress content through the REST API abilities

These tools dispatch any internal WordPress REST request. Permissions are the
site's own: a call only does what the logged-in user's capabilities already
allow.

| Tool | Methods |
|---|---|
| `rest-api/read` | `GET`, `OPTIONS` |
| `rest-api/write` | `POST`, `PUT`, `PATCH` |
| `rest-api/delete` | `DELETE` |
| `media/upload` | base64 file into the media library |

## Find the route first

Don't guess a route. Two steps, both cheap:

1. `rest-api/read` with route `/` returns every route path and the methods it
   accepts — a few kilobytes, not the full 1MB index.
2. `rest-api/read` with method `OPTIONS` and that route returns its
   parameters.

Read the `guidance` key in an `OPTIONS` result before you write. It flags
routes that change site settings or access, routes that can't be undone, and
fields that need care.

Responses are capped (50KB by default) and say when they were trimmed. Pass
`_fields` to ask for less up front.

## Content is block markup

The `content` field on a post or page holds Gutenberg block markup: HTML
wrapped in `<!-- wp:... -->` delimiters carrying JSON attributes. Hand-written
delimiters are the main source of broken pages here. The editor validates
markup against each block's registered schema, and markup that doesn't match
shows up as a broken block, even when the HTML looks right.

So don't write the delimiters by hand.

### Recommended: generate and validate it

Two Human Made packages do this without any model call. Use them when Node is
available:

- **`wesper`** — reads a live site into one JSON manifest: which block types,
  post types, bindable fields, patterns and theme.json settings that site
  actually has. Run it once per site, then work from the manifest instead of
  re-querying.

  ```bash
  # local install, via WP-CLI
  npx wesper collect --wp-path ./public --out site.context.json

  # remote site, via REST (password in WP_API_PASSWORD)
  npx wesper collect --rest --wp-url https://example.com \
    --wp-user <user> --out site.context.json

  npx wesper summarize site.context.json
  ```

  The REST collector reaches further than you might expect. An Application
  Password for any user who can edit posts — an author role is enough —
  returns theme.json settings and design tokens, every registered block type
  with its attributes, and the site's patterns. Anonymous access returns
  almost none of that: no theme, no blocks, no patterns.

  Use WP-CLI when you need what REST can't reach: Site Editor customizations
  merged over theme.json, block binding sources, registered image sizes and
  registered post meta.

  With no credentials to hand, you can issue yourself a temporary one
  through these abilities and take it back afterwards:

  1. `rest-api/write` — `POST /wp/v2/users/me/application-passwords` with
     `{"name": "wesper"}`. The response carries a one-time `password` and a
     `uuid`.
  2. Run the collect with it.
  3. `rest-api/delete` — `DELETE /wp/v2/users/me/application-passwords/<uuid>`.

  Ask the user before step 1. `/wp/v2/users` is a site-config route, so the
  abilities will flag it for confirmation anyway. Keep the password in an
  environment variable, never in a file you might commit, and revoke it as
  soon as the collect finishes, including when it fails. Application
  passwords only work over HTTPS or on localhost.

  `summarize` prints coverage per surface and says which work the evidence
  supports — read it before relying on the manifest. wesper never modifies
  the site.

- **`block-runner`** — turns HTML, or a block tree you describe, into
  validated block markup, checked with the same packages the editor uses.

  ```bash
  npx block-runner convert  # HTML in, block markup out
  npx block-runner assemble # a block tree you supply, validated
  npx block-runner validate # check markup you already have
  ```

  It ships its own skill at `skills/block-runner/` in the package — read that
  for the full command surface and authoring reference rather than guessing
  flags. Needs Node `^20.19 || ^22.13 || >=24` (21 and 23 are unsupported).

The pairing is: `wesper` tells you what this site supports, `block-runner`
produces markup that conforms to it, then `rest-api/write` sends it.

### Fallback when neither is available

If Node or the packages aren't there, either:

- send plain HTML as `content` — WordPress accepts it and stores it as one
  classic block, which is valid but not block-structured; or
- keep to core blocks with simple attributes (`core/paragraph`,
  `core/heading`, `core/list`, `core/image`).

`GET /wp/v2/block-types` through `rest-api/read` lists the blocks the site
has registered, with the attributes each accepts. It needs a logged-in user
who can edit posts.

Say which path you took, and that the result wasn't validated.

## Images

Upload first, then reference. `media/upload` takes a base64 file and returns
the attachment ID and URL. A `core/image` block needs both — the ID in the
block attributes, the URL in the `<img src>`. Uploads are capped at the
site's own limit.

## Writing

- Creating a post already defaults to `draft`. Leave it there unless the user
  asked you to publish.
- Confirm with the user before a write or a delete on anything that changes
  site settings or access, and before anything irreversible. The `guidance`
  key tells you which routes those are.
- After a write, read the item back with `_fields=content` if you need to
  check what was stored.
