# Uninstall, retention, and recovery

Back up the Espo database and `data/` directory before upgrades or removal.

Normal uninstall:

- removes only the Time Management, Calendar, and busy-range configuration entries that this installation added;
- leaves every pre-existing entity, relationship, field, option, and record untouched;
- preserves extension Work Packages, Time Entries, schedules, snapshots, attachments, and historical Stream notes.

Reinstall the same or a compatible newer version, rebuild, and run lifecycle reconciliation to restore access to retained records.

No automatic purge is included. Destructive removal must be a separate, reviewed database operation after an export and backup.
