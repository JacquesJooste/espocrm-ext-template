# Release and rollout

## 0.2.2 loader compatibility hotfix

Release 0.2.2 uses absolute EspoCRM module IDs for the shared pagination helper. This prevents the production loader from resolving the helper outside the extension bundle and restores the Time Management workspace after upgrading from 0.2.1. Validation now rejects relative dependencies in bundled client modules.

## 0.2.1 pagination hardening

Release 0.2.1 loads complete record collections in EspoCRM-safe batches of 200. This prevents the record-list access error in the Work Item and Work Block libraries, Instance selectors, default Work Block ordering, and attending-user selectors without raising the server's `recordListMaxSizeLimit`.

## 0.1.9 hardening boundary

Release 0.1.9 contains only production hardening:

- Scheduled Work Block audit fields and calendar-facing `assignedUsers`;
- `createdAt` backfill from `dateStart`;
- persistent singleton settings route and protected backing record;
- corrected labels, translations, Default Planning Order tooltip, and grouped Instance layout.

Deploy and verify this boundary before introducing the 0.2.0 workflow schema in environments that require separately approved releases.

## 0.2.0 workflow redesign

Release 0.2.0 adds Work Items, ordered Work Block composition, immutable runtime snapshots, timer-first target actions, partial rescheduling, role-oriented navigation, rollup overlays, and schema-version migration.

## Production checklist

1. Back up the database and Espo `data/` directory.
2. Stop all active timers.
3. Run `composer install`, `npm run validate`, `npm test`, `npm run sa`, and `npm run integration-tests` against the release candidate.
4. Upload during a maintenance window and allow EspoCRM rebuild and migration to finish.
5. Verify Meeting creation and `GET /Timeline/busyRanges` while Scheduled Work Blocks are enabled.
6. Verify migration counts, Time Entry totals/attendees, legacy exact estimates, and billing snapshot checksums.
7. Test Log Time, Stop Timer, manual entry, Work Block attachment, and partial rescheduling.
8. Test technician, Operations Manager, Billing Administrator, and denied-role navigation.
9. Test target detail/list behavior and Instance/Library setup at desktop and mobile widths.
10. Test Account, Contact, and User rollups under restricted ACLs.
11. Run `npm run dist`; publish the ZIP and matching SHA-256 together.

Do not widen the manifest to EspoCRM 11 until a full certification run passes.
