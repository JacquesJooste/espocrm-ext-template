# Elevate Resource Management

Elevate Resource Management 0.2.0 is an EspoCRM 10.x extension built around reusable Work Items, composed Work Blocks, timer-first Time Entries, scheduled resource planning, capacity visibility, reporting, and billing readiness.

It is deliberately data-agnostic. Administrators map an instance to an existing target entity and existing fields/status values. The extension never adds fields, relationships, or enum options to that target.

## Compatibility

- EspoCRM 10.x
- PHP 8.3–8.5
- MySQL or PostgreSQL
- Internal EspoCRM users
- No Advanced Pack or Sales Pack dependency

## Development

Prerequisites are Node.js 18+, npm 8+, PHP 8.3+, Composer, and an Espo-supported database.

```sh
npm install
composer install
npm run validate
npm test
npm run dist
```

The installable ZIP and SHA-256 file are written to `build/`.

Read [the administrator guide](docs/administrator-guide.md), [architecture](docs/architecture.md), [API compatibility](docs/api.md), and [release checklist](docs/release.md) before installing in production.

## Data retention

Uninstall removes only shared configuration-list values that this extension previously added. It does not remove target data or extension history. Reinstalling a compatible release reconnects preserved extension records. See [uninstall and recovery](docs/uninstall-and-recovery.md).

## License

Copyright © 2026 Elevate.

Licensed under the GNU Affero General Public License v3.0 only. See `LICENSE`.
