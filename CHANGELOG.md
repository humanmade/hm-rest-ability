# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Name close matches for an unknown route. Asking for `/wp/v2/patterns` or a bare `/wp/v2/global-styles` now returns a `rest_no_route` error listing the nearest registered routes, such as `/wp/v2/block-patterns/patterns` and `/wp/v2/global-styles/{id}`, with URL parameters shown as `{name}` placeholders, plus a pointer to `GET /` and `GET /<namespace>`. The `OPTIONS` result for an unknown route carries the same list under `suggestions`. In `inc/route-suggestions.php`.
- Return a `rest_no_method` error, naming the methods a route does accept, when the route exists but doesn't take the one requested. Before, that case was reported as no route at all.
- Add guidance to `OPTIONS` output for core's design routes: where the global styles `{id}` and theme `{stylesheet}` come from, and which of `/wp/v2/block-patterns/patterns` and `/wp/v2/blocks` holds registered versus synced patterns. In `inc/design-route-guidance.php`.

### Changed

- Keep route discovery working on a site whose condensed route list exceeds the response cap. The index now swaps the list for a count of routes per namespace and says to call `GET /<namespace>` for one namespace at a time, instead of dropping the whole list as the largest field.
- Mention `GET /wp/v2` and the unknown-route suggestions in the `rest-api/read` tool description.

## [0.5.0] - 2026-09-22

### Added

- Add a guidance note to `OPTIONS` output for a route whose `content` field
  holds block markup, e.g. `/wp/v2/posts` — it says the field holds WordPress
  block markup and points at `GET /wp/v2/block-types` for the blocks that
  site has registered. Detected from the route's schema, in
  `inc/content-field-guidance.php`.
- Ship an opt-in skill file, `skills/wordpress-block-content/SKILL.md`, for
  agent harnesses that read them. It covers route lookup, block markup and
  media upload through these abilities, and points at the `wesper` and
  `block-runner` npm packages, with a fallback for when they aren't
  installed. Nothing in the plugin's own tool output names them. The skill
  is `export-ignore`d, so it stays out of the release ZIP.

### Removed

- OAuth2 discovery endpoints (`/.well-known/oauth-authorization-server`,
  `/.well-known/oauth-protected-resource`). This is being upstreamed to
  [WP-API/OAuth2](https://github.com/WP-API/OAuth2) instead, where it fits
  better than as a standalone feature of this plugin.

## [0.4.0] - 2026-09-15

### Added

- Make the `guidance` text an `OPTIONS` response carries filterable with
  `hm_rest_ability_route_guidance`, so a site can add its own advice for a
  route beyond the built-in risk-tier guidance.
- Add a guidance note to `OPTIONS` output for a route with a publishable
  `status` field, e.g. `/wp/v2/posts` — creating an item there already
  defaults to `draft` when `status` is omitted, so a client is told not to
  set it to `publish` unless the user asked for that. This is a built-in
  example of using the new `hm_rest_ability_route_guidance` filter, in
  `inc/status-field-guidance.php`.

### Changed

- **Breaking:** split the `rest-api/call` ability into `rest-api/read` (`GET`,
  `OPTIONS`), `rest-api/write` (`POST`, `PUT`, `PATCH`), and `rest-api/delete`
  (`DELETE`). `rest-api/call` no longer exists — there is no deprecated alias.
  This lets an MCP client gate each kind of request separately, and gives each
  tool an honest `readOnlyHint` / `destructiveHint` / `idempotentHint`, instead
  of the previous single tool's static worst-case annotations.

## [0.3.0] - 2026-09-15

### Added

- Expose the abilities as first-class MCP tools through the MCP Adapter, so
  an MCP client sees them as named tools rather than one generic call.
- Add a `media/upload` ability for file uploads, limited by the site's own
  upload limit rather than a fixed size.
- Give clients a usable way to discover routes: `GET /` lists every route
  and the methods it accepts, and `OPTIONS` on a route describes its
  parameters.
- Cap `rest-api/call` responses at 50,000 bytes, filterable with
  `hm_rest_ability_max_response_bytes`, and pass `_fields` through so a
  client can narrow a response instead of hitting the cap.

## [0.2.0] - 2026-09-04

### Fixed

- Serve the `.well-known` discovery documents when the request path has a
  trailing slash, so hosts that redirect extensionless paths no longer turn
  discovery into a 404. (Superseded in 0.5.0, which removes these endpoints.)

## [0.1.0] - 2026-08-27

### Added

- Initial release, extracted from `humanmade/hmn.md`.
- OAuth2 discovery endpoints (RFC 8414, RFC 9728) for MCP client
  auto-discovery.
- `rest-api/call` ability for dispatching internal WordPress REST API
  requests from MCP clients.

[Unreleased]: https://github.com/humanmade/hm-rest-ability/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/humanmade/hm-rest-ability/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/humanmade/hm-rest-ability/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/humanmade/hm-rest-ability/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/humanmade/hm-rest-ability/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/humanmade/hm-rest-ability/releases/tag/v0.1.0
