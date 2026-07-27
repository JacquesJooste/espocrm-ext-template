# Administrator guide

## Upgrade to 0.2.0

Upgrade in a maintenance window after a database backup. Stop all active timers before uploading the extension; the preflight script blocks the upgrade while an active Work Session exists. EspoCRM rebuilds the new schema before the idempotent data migration runs.

The migration:

- converts every legacy Work Block template into a Work Block containing one Work Item;
- preserves the exact legacy estimate, including non-quarter-hour values;
- creates immutable Work Block and Work Item run snapshots for existing schedules;
- connects existing Time Entries to those snapshots and restores their attending-user relationship;
- converts ordered default IDs into `isDefault` and Default Planning Order;
- leaves billing snapshot JSON and checksums unchanged.

Legacy activities and estimate fields remain hidden and read-only for rollback and historical exports. A non-quarter-hour value is visibly marked as legacy until a user explicitly chooses a quarter-hour replacement.

## Settings

Resource Management Settings is a persistent singleton, not a user-managed record collection. Open Time Management → Setup or Administration → Resource Management Settings. The backing record cannot be created a second time or deleted.

The first active administrator becomes the provisional Operations Manager and Billing Administrator. Replace those assignments as needed.

## Guided Instance setup

1. Create an Instance and choose Standard or Project.
2. Select the target entity by its translated Espo label.
3. Map its identifier, display name, status, resource, Account, and Contact fields. Each selector exposes only compatible fields and includes the internal name for troubleshooting.
4. Map the in-progress and completed statuses. Billing mirror statuses are optional; leave them blank to keep those stages inside Time Management.
5. Choose Warn or Block for capacity conflicts and work outside a user’s working calendar.
6. Save the Instance. The guided flow opens Library for Work Item and Work Block setup.
7. Build Work Blocks from ordered Work Items. Select defaults and arrange them with the ordered selector; Default Planning Order is authoritative and lower positions are attached first.
8. Finish by opening the configured target list. Eligible records now expose Log Time and Work Blocks.

Eligibility rules remain an advanced JSON setting. Changing the target entity or status field refreshes dependent choices and clears incompatible mappings. The server validates mappings again on save.

The extension does not add fields, relationships, or enum values to configured target entities.

## Work library

A Work Item has one canonical description, a default estimate, and an active state. Names are intentionally not unique. Estimates use 15-minute increments from 15 minutes through 24 hours.

A Work Block is an ordered group of Work Items. A row can override its Work Item estimate without changing the library default. The server derives and stores the Work Block total; the compatibility seconds field is read-only.

Editing a library definition never rewrites a target’s historical snapshot.

## Role matrix

- Technicians need read/edit access to their target records and access to Log Time.
- Operations Managers see Planning, Reporting, Library, Setup, and user utilization.
- Billing Administrators additionally see Billing and snapshot operations.
- Espo administrators retain emergency access.

Navigation is role-aware. Account, Contact, and User overlays are read-only and filter related targets through the viewer’s ACL.

Invoiced Time Entries remain application-locked even when a Role grants edit access.
