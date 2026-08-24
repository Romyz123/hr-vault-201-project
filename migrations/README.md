# Database migrations

Schema changes belong here and should run once during deployment, using a restricted migration account. Normal web requests must not create or alter tables.

## Key rotation

Vault key rotation is a controlled maintenance operation:

1. Back up the database and vault.
2. Set the current `VAULT_KEY` as `VAULT_OLD_KEY` in a protected environment.
3. Generate a new random key and set it as `VAULT_KEY`.
4. Run a one-time migration that reads each document with the old key and rewrites it with the new authenticated format, updating a version marker.
5. Verify sample documents and checksums, then remove `VAULT_OLD_KEY`.

Do not change `VAULT_KEY` without migrating existing files first; old files cannot be decrypted with the new key.
