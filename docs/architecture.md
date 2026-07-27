# Architecture

The public operating model exposes Work Items, Work Blocks, and Time Entries. Technical records remain internal:

- Work Package is the target-level aggregate.
- Work Block Run and Work Item Run are immutable target snapshots.
- Scheduled Work Block is a calendar allocation slot; one logical run may use multiple slots.
- Work Session is active timer state.
- Billing Snapshot is immutable invoice evidence.

Targets are referenced by `targetType` and `targetId`, so the extension adds no reciprocal fields to configured entities.

## Scheduling and history

Work Block membership stores sequence and an optional estimate override. The server derives the effective total. Attaching a definition snapshots its names, descriptions, estimates, sequence, and milestone type. Later library edits cannot alter history.

Partial rescheduling creates a future Scheduled Work Block and moves unfinished Work Item runs to it. Time Entries and completed items retain their original schedule link.

Scheduled Work Blocks implement EspoCRM’s event contract with audit timestamps and `assignedUsers`. The relation reuses `elevateRmScheduledBlockUser`, preserving assignments created by earlier versions.

## Timing and reporting

Timer APIs use server UTC timestamps, client UUID idempotency, active-attendee conflict checks, and target ACL checks. A Time Entry links its Work Item run, logical Work Block run, schedule slot, Work Package, and relational attending users while retaining attendee-name snapshots.

Elapsed seconds are recorded once. Labour seconds equal elapsed seconds multiplied by attendee count. Work Block progress is estimate-weighted completion, independent of actual elapsed and labour totals.

Account and Contact reporting uses snapshots captured when the package was created. User utilization uses native EspoCRM working calendars.

## Migration

Schema version 2 migration runs after EspoCRM rebuild and uses stable legacy marker fields to make retries safe. It never rewrites legacy IDs, Time Entry totals, or billing snapshot payloads/checksums. Installation is blocked while an active timer exists.

The public API is under `/api/v1/ElevateResourceManagement`; see [API compatibility](api.md).
