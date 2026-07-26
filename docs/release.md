# Build and release checklist

1. Install exact dependencies and commit `package-lock.json`.
2. Run `composer install`, `npm run validate`, `npm test`, and `npm run sa`.
3. Build clean EspoCRM 10 test sites for MySQL and PostgreSQL.
4. Exercise plan, Report In, milestone, finish, manual entry, lifecycle, billing snapshot, and reopen flows.
5. Test internal Role combinations and denied operations.
6. Test desktop, quick-detail, and mobile widths.
7. Confirm Calendar and free/busy integration.
8. Uninstall and verify pre-install target schema/data checksums are unchanged.
9. Reinstall and verify retained extension history.
10. Run `npm run dist`; publish the ZIP and matching SHA-256 file together.

Do not widen the manifest to EspoCRM 11 until a full certification run passes.
