# Operations guide

The Capacity tab shows scheduled blocks across the selected instance. Use it to check upcoming allocation and advice before reassigning work.

Advice is informational unless the instance uses Block policy. It never moves work automatically. Scheduled Work Blocks appear in native Calendar and free/busy ranges for their assigned users.

Lifecycle reconciliation runs hourly and after relevant saves:

- `ClosedAddTimeLogs`: completed target with missing time.
- `ClosedReadyForBilling`: every required Work Block has time.
- `ClosedInvoiced`: immutable billing snapshot created.

Archived instances remain reportable. Only instances with no schedules, entries, packages, or snapshots may be deleted.
