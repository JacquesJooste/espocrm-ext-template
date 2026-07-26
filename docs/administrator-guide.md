# Administrator guide

## Installation

Upload the release ZIP under Administration → Extensions. Rebuild and clear cache if Espo does not do so automatically. Confirm that Time Management appears in the navigation and Scheduled Work Blocks are available in Calendar.

The first active administrator becomes the provisional Operations Manager and Billing Administrator. Open Time Management → Settings and replace these assignments as needed.

## Instance setup

Create Work Block templates first, then create an instance:

1. Choose Standard or Project.
2. Enter the exact Espo entity type of the existing queue.
3. Map its identifier, name, status, assigned-user, Account, and Contact fields.
4. Select existing in-progress and completed status values.
5. Optionally select existing billing-state status values.
6. Add safe eligibility rules and default Work Block IDs.
7. Choose Warn or Block for schedule conflicts and outside-hours planning.
8. Validate with non-production records before enabling broad criteria.

The extension does not create fields or enum values on the target.

## Role matrix

Grant technicians target read/edit access plus Time Entry create/read and, where appropriate, edit/delete. Operations Managers need all extension scopes. Billing Administrators need Work Package, Time Entry, Reporting, and Billing Snapshot access. Espo administrators retain emergency access.

Invoiced entries are application-locked even when a Role grants edit access.

## Capacity defaults

Native working calendars are authoritative. The initial planning guideline reserves 20% for operational work, warns at 85% of bookable capacity, and blocks above 100% only when Block policy is selected. Configure per-user profiles where working-calendar capacity is not the appropriate baseline.
