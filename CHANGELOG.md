# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Serve the `.well-known` discovery documents when the request path has a
  trailing slash, so hosts that redirect extensionless paths no longer turn
  discovery into a 404.

## [0.1.0] - 2026-08-27

### Added

- Initial release, extracted from `humanmade/hmn.md`.
- OAuth2 discovery endpoints (RFC 8414, RFC 9728) for MCP client
  auto-discovery.
- `rest-api/call` ability for dispatching internal WordPress REST API
  requests from MCP clients.

[Unreleased]: https://github.com/humanmade/hm-rest-ability/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/humanmade/hm-rest-ability/releases/tag/v0.1.0
