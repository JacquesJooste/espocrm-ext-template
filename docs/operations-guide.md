# Operations guide

Planning shows scheduled Work Blocks across the selected Instance. Use it to check upcoming allocation and advice before assigning or rescheduling work.

Advice is informational unless the Instance uses Block policy. It never moves work automatically. Scheduled Work Blocks appear in native Calendar and free/busy ranges for their assigned users.

Use Reschedule Remaining Work to split unfinished Work Items into a future slot. Existing Time Entries and completed items are historical and are never moved.

Lifecycle reconciliation runs hourly and after relevant saves:

- `ClosedAddTimeLogs`: completed target with missing time.
- `ClosedReadyForBilling`: every required Work Item has time.
- `ClosedInvoiced`: immutable billing snapshot created.

Archived instances remain reportable. Only instances with no schedules, entries, packages, or snapshots may be deleted.
