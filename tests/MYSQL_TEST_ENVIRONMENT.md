# Isolated database tests

Run from the repository root:

```powershell
& C:/xampp/php/php.exe tests/run_isolated.php
```

The CLI runner uses the existing account from `backend/config/database.php`.
It creates a random `leo_defense_test_<12 hex digits>` schema, copies schema
definitions and booking-reference objects without application records, and
executes copies of the existing tests. It redirects every test connection,
omits provider credential files, disables outbound payment/email transports,
and removes the disposable schema and temporary files afterward. It never
creates accounts, grants privileges, repairs tables, or edits app configuration.
The existing account needs schema/table/routine/trigger creation permissions;
the configured local account already has these. A setup failure stops testing.

## Step 13 diagnosis (2026-10-20)

- Server: MariaDB 10.4.32, port 3306; configured account authenticates as
  `root@localhost` and already has the required privileges.
- Step 12 failed at the database-specific `GRANT`, after test-user creation:
  SQLSTATE `HY000`, driver error `1114`, `ER_RECORD_FILE_FULL`, table `db`.
  This targets the persistent Aria privilege table `mysql.db`, not salon data.
- Read-only inspection of copied `db.MAI`/`db.MAD` files reported page 0
  checksum error `176` and data length 10,969 bytes versus expected 8,192.
  SHA-256 comparison confirmed the copies matched the live original files.
  This establishes Aria table corruption; its historical cause is unknown.
- C: had about 139 GB free. Table status reported only 8 KB used against a
  roughly 17 TB data-file limit. There was no evidence of a full physical disk.
- MySQL `tmpdir` is `C:/xampp/tmp`; a read-only metadata query successfully
  created a disk temporary table. Read-only mode is off. No storage or memory
  configuration change was necessary.
- All 11 PHP suites and the admin pagination endpoint passed with this runner.
  Restricted-account setup was not retried because it writes system privileges.

## Remaining maintenance (approval required)

The runner bypasses the damaged privilege-table write; it does not repair it.
Before any live repair, obtain approval for a maintenance window: stop MySQL in
XAMPP, back up the complete `C:/xampp/mysql/data` directory and `my.ini` to a
separate location, then perform a read-only offline check of `mysql/db.MAI`.
Review its output and a restore/repair plan before approving any modifying
command. Do not replace privilege files from another installation, reset
accounts, delete Aria logs, or run automatic recovery against live files.
No shutdown, live repair, privilege modification, or production-data write was
performed in Step 13.

References: [MariaDB error 1114](https://mariadb.com/docs/server/reference/error-codes/mariadb-error-codes-1100-to-1199/e1114),
[aria_chk safety and read-only options](https://mariadb.com/docs/server/clients-and-utilities/aria-clients-and-utilities/aria_chk).
