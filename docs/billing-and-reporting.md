# Billing and reporting

Reporting distinguishes elapsed and labour time and supports Work Item, Work Block, resource, Account, Contact, and target-oriented analysis. Custom date windows are interpreted in the CRM timezone while data remains stored in UTC.

Billing has three queues: Add Time Logs, Ready for Billing, and Invoiced. Generate Invoice Summary previews current entries. Mark Invoiced creates a versioned JSON snapshot with a SHA-256 checksum and locks its Time Entries.

Reopen Billing supersedes the current snapshot, unlocks entries, and returns the package to Ready. It never overwrites prior snapshots.

Automatic invoicing after export is disabled by default. A failed export must never advance lifecycle state.
