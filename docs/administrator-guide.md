# Administrator guide

## Installation

Upload the release ZIP under Administration → Extensions. Rebuild and clear cache if Espo does not do so automatically. Confirm that Time Management appears in the navigation and Scheduled Work Blocks are available in Calendar.

The first active administrator becomes the provisional Operations Manager and Billing Administrator. Open Time Management → Settings and replace these assignments as needed.

## Instance setup

Create Work Block templates first, then create an instance:

1. Choose Standard or Project.
2. Select the existing queue or task entity by its translated Espo label. The instance name defaults to that label and can be changed.
3. Select its identifier, display name, status, resource, Account, and Contact mappings. Each selector shows only compatible fields and includes the internal field name in parentheses for troubleshooting.
4. Select existing in-progress and completed values populated from the chosen status field.
5. Optionally select existing billing-state status values.
6. Add safe eligibility rules and default Work Block IDs.
7. Choose Warn or Block for schedule conflicts and outside-hours planning.
8. Validate with non-production records before enabling broad criteria.

Changing the target entity or status field refreshes the dependent choices and clears mappings that are no longer valid. Eligibility rules and default Work Block IDs remain advanced JSON settings because they represent structured rules and ordered record references rather than target metadata.

The extension does not create fields or enum values on the target. The server validates every selected mapping again when the instance is saved.

## Role matrix

Grant technicians target read/edit access plus Time Entry create/read and, where appropriate, edit/delete. Operations Managers need all extension scopes. Billing Administrators need Work Package, Time Entry, Reporting, and Billing Snapshot access. Espo administrators retain emergency access.

Invoiced entries are application-locked even when a Role grants edit access.

## Capacity defaults

Native working calendars are authoritative. The initial planning guideline reserves 20% for operational work, warns at 85% of bookable capacity, and blocks above 100% only when Block policy is selected. Configure per-user profiles where working-calendar capacity is not the appropriate baseline.
