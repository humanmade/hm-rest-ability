# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

### Removed

- OAuth2 discovery endpoints (`/.well-known/oauth-authorization-server`,
  `/.well-known/oauth-protected-resource`). This is being upstreamed to
  [WP-API/OAuth2](https://github.com/WP-API/OAuth2) instead, where it fits
  better than as a standalone feature of this plugin.

## [0.1.0] - 2026-08-27

### Added

- Initial release, extracted from `humanmade/hmn.md`.
- OAuth2 discovery endpoints (RFC 8414, RFC 9728) for MCP client
  auto-discovery.
- `rest-api/call` ability for dispatching internal WordPress REST API
  requests from MCP clients.

[Unreleased]: https://github.com/humanmade/hm-rest-ability/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/humanmade/hm-rest-ability/releases/tag/v0.1.0
