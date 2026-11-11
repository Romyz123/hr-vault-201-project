# Database migrations

Schema changes belong here and should run once during deployment, using a restricted migration account. Normal web requests must not create or alter tables.

## Dashboard bulk action undo

Dashboard bulk edits and soft deletes use the batch audit tables. Apply [`schema/migrations/2026-10-01-add-bulk-update-workflow.sql`](../schema/migrations/2026-10-01-add-bulk-update-workflow.sql) once during deployment to enable bulk action history and undo.

## Employee status values

The employee `status` column must support configurable statuses such as Active, Transferred, AWOL, and blank (Unspecified). Apply [`schema/migrations/2026-11-10-expand-employee-status-values.sql`](../schema/migrations/2026-11-10-expand-employee-status-values.sql) once during deployment; the legacy ENUM silently coerces unrecognized statuses to an empty value on some MySQL configurations.

## Link groups to sections

Apply [`schema/migrations/2026-11-10-link-groups-to-sections.sql`](../schema/migrations/2026-11-10-link-groups-to-sections.sql) once during deployment. Existing groups are intentionally left unassigned; an administrator should assign each group to its correct section in Manage Organization Hierarchy rather than infer a relationship from inconsistent employee records.

## Key rotation

Vault key rotation is a controlled maintenance operation:

1. Back up the database and vault.
2. Set the current `VAULT_KEY` as `VAULT_OLD_KEY` in a protected environment.
3. Generate a new random key and set it as `VAULT_KEY`.
4. Run a one-time migration that reads each document with the old key and rewrites it with the new authenticated format, updating a version marker.
5. Verify sample documents and checksums, then remove `VAULT_OLD_KEY`.

Do not change `VAULT_KEY` without migrating existing files first; old files cannot be decrypted with the new key.
