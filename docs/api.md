# API compatibility

New 0.2.0 APIs:

- `GET|PUT /ElevateResourceManagement/settings`
- `GET /ElevateResourceManagement/permissions`
- `GET /ElevateResourceManagement/work-blocks/{id}/composition`
- `POST /ElevateResourceManagement/work-blocks`
- `PUT /ElevateResourceManagement/work-blocks/{id}`
- `POST /ElevateResourceManagement/packages/{id}/work-blocks`
- `POST /ElevateResourceManagement/timers/start`
- `POST /ElevateResourceManagement/timers/{id}/stop`
- `POST /ElevateResourceManagement/scheduled-blocks/{id}/reschedule-remaining`
- `GET /ElevateResourceManagement/my-work`
- `GET /ElevateResourceManagement/rollups/{entityType}/{id}`

Composite Work Block rows accept either `workItemId` or an inline `create` object, plus optional `estimateOverrideSeconds` and sequence by array order.

Time Entry responses retain scheduled-block and attendee snapshot fields and add `workBlockRunId`, `workItemRunId`, and relational `usersIds`.

The report-in, milestone, finish, and manual-entry endpoints remain as compatibility adapters for the 0.2.x release. Their old UI buttons are removed. Integrations should move to timer start/stop and the secondary manual entry path before the next breaking release.
