# Architecture

The server module is `ElevateResourceManagement`; entity names use the `ElevateRm` prefix. Target associations are stored as `targetType` and `targetId`, so arbitrary entities can be supported without reciprocal schema changes.

The public API is under `/api/v1/ElevateResourceManagement`. Every target operation rechecks eligibility and ACL. Timer commands use client UUIDs for idempotency, server UTC timestamps, optimistic revisions, and active-session conflict checks.

Scheduled Blocks are Event entities with multiple User attendees. Reusable templates are copied into package snapshots so later template edits do not alter historical work.

The global frontend handlers are passive until the context endpoint confirms eligibility. List views make one bulk context request per rendered page.

The hourly `ElevateRmReconcileLifecycle` job repairs state after imports or integrations. All extension tables are retained on uninstall.
