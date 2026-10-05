# Integrated Attendance and Payroll Deployment Guide

This guide upgrades an existing UCC HR installation without replacing employee,
fingerprint, attendance, payroll, or employee-account data. Complete the steps in
order and use the exact database name for the installation being upgraded. The
examples below use `ucchr_system_recovered`.

> Do not import `database/ucchr.sql` over an existing installation. That fresh
> schema file creates and selects `ucchr_system`; after importing it, apply the
> integrated, flexible-period, cleanup, token-retirement, payroll-review,
> payroll-dashboard, fixed-daily-rate, and flexible-pay-type
> migrations in the order shown in section 4. Point `config/database.php` or
> `UCCHR_DB_NAME` to `ucchr_system`. Existing installations use only the ordered
> migration process below and keep their current database name.

## 1. Prepare a maintenance window

1. Start the intended MariaDB instance. This recovered installation uses the
   project data directory on port `3307`; XAMPP's normal port `3306` instance is
   a different database and must not be used for these commands.
2. Stop the PHP development server and disconnect or turn off the ESP32 so that
   no fingerprint or attendance request is written during the upgrade.
3. Confirm `config/database.php` points to the intended database. Its current
   default is `ucchr_system_recovered`; the `UCCHR_DB_*` environment variables
   override it.
4. Open PowerShell and set the project/database values once. If your database is
   named differently or uses another port, change `$dbName` or `$dbPort`:

```powershell
$projectDirectory = "C:\Users\Jules Ivan\Documents\Codex\2026-08-21\cr\outputs\UCC_HR_System"
$dbName = "ucchr_system_recovered"
$dbPort = 3307
$mysql = "C:\xampp\mysql\bin\mysql.exe"
$mysqlDump = "C:\xampp\mysql\bin\mysqldump.exe"
$php = "C:\xampp\php\php.exe"
Set-Location -LiteralPath $projectDirectory
$env:UCCHR_DB_NAME = $dbName
$env:UCCHR_DB_PORT = [string] $dbPort

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SELECT DATABASE() AS selected_database; SELECT COUNT(*) AS employees FROM employees; SELECT COUNT(*) AS attendance_rows FROM attendance; SELECT COUNT(*) AS payroll_runs FROM payroll_runs; SELECT COUNT(*) AS fingerprint_registrations FROM fingerprint_registrations;"
if ($LASTEXITCODE -ne 0) { throw "Database preflight failed for $dbName." }
```

Keep this PowerShell window open for the remaining steps. If you resume in a new
window, run the variable block above again before using `$mysql`, `$mysqlDump`,
`$php`, `$dbName`, `$dbPort`, or `$projectDirectory`.

If the MySQL account has a password, add `--password` without putting the actual
password in the command; the client will prompt for it.

## 2. Create and verify a backup

MariaDB error 1932 can make a normal full dump stop at
`fingerprint_template_slots`. Back up every healthy table first while excluding
only that known unreadable derived-mapping table:

```powershell
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backupDirectory = Join-Path (Get-Location) "database\backups"
New-Item -ItemType Directory -Force -Path $backupDirectory | Out-Null
$backupFile = Join-Path $backupDirectory "$dbName-before-integrated-$stamp.sql"

& $mysqlDump --host=127.0.0.1 --port=$dbPort --user=root --single-transaction --routines --events --triggers --ignore-table="${dbName}.fingerprint_template_slots" --result-file="$backupFile" $dbName
if ($LASTEXITCODE -ne 0) { throw "Database backup failed; stop the deployment." }
Get-Item -LiteralPath $backupFile | Select-Object FullName, Length, LastWriteTime
```

The dump must exit successfully and have a non-zero length. Keep a copy outside
the project folder. For the strongest verification, restore it into a separate,
disposable database on a test MySQL instance and compare the baseline counts.
Do not delete or overwrite the live database to test a backup.

After the error-1932 repair in the next section, create a second normal full
backup without `--ignore-table`. That second backup should include the repaired
`fingerprint_template_slots` table:

```powershell
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backupDirectory = Join-Path (Get-Location) "database\backups"
New-Item -ItemType Directory -Force -Path $backupDirectory | Out-Null
$postRepairBackup = Join-Path $backupDirectory "$dbName-after-repair-$stamp.sql"
& $mysqlDump --host=127.0.0.1 --port=$dbPort --user=root --single-transaction --routines --events --triggers --result-file="$postRepairBackup" $dbName
if ($LASTEXITCODE -ne 0) { throw "Post-repair database backup failed; stop the deployment." }
Get-Item -LiteralPath $postRepairBackup | Select-Object FullName, Length, LastWriteTime
```

## 3. Repair `fingerprint_template_slots` only when error 1932 is confirmed

Do not run `DISCARD TABLESPACE`, manually copy `.ibd` files, or drop unrelated
tables. The included repair utility performs a read-only preflight and is
deliberately restricted to the exact MariaDB error-1932 metadata/engine mismatch.

Run the preflight first:

```powershell
& $php database\repair_fingerprint_template_slots.php --database=$dbName
```

- `Eligible for repair` means native MariaDB error 1932 was confirmed and no
  change has yet been made.
- A healthy table needs no repair. The tool refuses `--execute` against it.
- Any missing-table, permission, schema, or unexpected error is a stop condition;
  investigate it instead of forcing this repair.

Only after the backup succeeds and the preflight confirms eligibility, execute:

```powershell
& $php database\repair_fingerprint_template_slots.php --database=$dbName --execute
if ($LASTEXITCODE -ne 0) { throw "Fingerprint mapping repair did not complete." }
```

The repair acquires a named database lock, repeats the probe, rebuilds only the
unreadable table, and restores authoritative `CENTER` mappings from canonical
fingerprint registrations. It never guesses `LEFT`, `RIGHT`, `UPPER`, or `LOWER`
slots. Re-enroll an employee later if those auxiliary five-angle templates need
to be restored.

Verify the repaired table before proceeding:

```powershell
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="CHECK TABLE fingerprint_template_slots; SELECT position, mapping_status, COUNT(*) AS rows_found FROM fingerprint_template_slots GROUP BY position, mapping_status;"
if ($LASTEXITCODE -ne 0) { throw "Fingerprint table verification failed." }
```

## 4. Apply the integrated migration twice

The migration is additive and procedure-free. It preserves primary keys and
legacy payroll status `Released`, and uses `IF NOT EXISTS`/upserts so it can be
run again safely. Run it once to upgrade, then immediately run the same command
a second time to prove idempotency:

```powershell
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/integrated_attendance_payroll_update.sql"
if ($LASTEXITCODE -ne 0) { throw "First migration import failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/integrated_attendance_payroll_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Second idempotency import failed." }
```

Both imports must finish without an SQL error. Compare the migration's
preservation summary with the baseline counts, then verify its marker and core
tables:

```powershell
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SELECT migration_key, applied_at FROM schema_migrations WHERE migration_key='2026-09-05-integrated-attendance-payroll-v1'; CHECK TABLE attendance_logs, attendance, overtime_requests, leave_requests, payroll_runs, payroll_items, payroll_item_attendance;"
if ($LASTEXITCODE -ne 0) { throw "Integrated migration verification failed." }
```

If this installation completed the integrated migration before flexible
Part-Time periods were introduced, also apply the dedicated additive update:

```powershell
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/part_time_schedule_periods_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Flexible schedule migration failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SELECT migration_key, applied_at FROM schema_migrations WHERE migration_key='2026-09-06-flexible-part-time-periods-v1'; CHECK TABLE work_schedule_periods, attendance;"
if ($LASTEXITCODE -ne 0) { throw "Flexible schedule verification failed." }
```

The update preserves every existing schedule and attendance row. For a legacy
Part-Time day, it converts the old unpaid break into a centered gap while keeping
the same net scheduled minutes. HR must review those converted periods and set
the employee's real daily periods in **Work Schedule**.

Finish the database cleanup by applying both guarded retirement migrations.
Run each one twice to verify that a repeated deployment is harmless:

```powershell
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/remove_obsolete_deductions_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Obsolete deduction cleanup failed." }
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/remove_obsolete_deductions_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Obsolete deduction idempotency check failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/retire_admin_reset_tokens_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Administrator reset-token retirement failed." }
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/retire_admin_reset_tokens_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Token-retirement idempotency check failed." }
```

The deduction cleanup is a historical prerequisite: it reconciles stored values
and removes the old contribution, tax, absence, undertime, and broad policy
fields. Overtime and Holiday remain earnings. The token cleanup affects only the
retired administrator token table; employee setup and reset links remain in
`employee_account_tokens`.

Apply the payroll review automation update **last**, after the cleanup above.
It restores only the current, transparent Undertime field, adds the source table
used by Cash Advance and historical Other deductions, and enables the new
attendance day classification and review sheet. Cash Advance is entered only in
**Run Payroll → Generate Payroll**; new Other entries are not exposed by the
active application. Its historical `employee_monthly_schedules` table is retained only
for migration compatibility; the current application uses the recurring employee
Work Schedule as its single schedule source. It does not restore SSS,
PhilHealth, Pag-IBIG, tax, absence, or broad policy deductions. Run it twice to
verify idempotency:

```powershell
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/payroll_review_automation_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Payroll review automation migration failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/payroll_review_automation_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Payroll review automation idempotency check failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SELECT migration_key, applied_at FROM schema_migrations WHERE migration_key='2026-09-09-payroll-review-automation-v1'; CHECK TABLE employee_monthly_schedules, employee_deduction_entries, attendance, payroll_items;"
if ($LASTEXITCODE -ne 0) { throw "Payroll review schema verification failed." }
```

Apply the Payroll Dashboard update after the review migration. It keeps the
existing calculation and approval engine, permits separate employee payrolls
for the same period, and adds Cash/Bank Transfer plus Pending/Paid tracking.
Run it twice to verify idempotency:

```powershell
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/payroll_dashboard_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Payroll Dashboard migration failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/payroll_dashboard_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Payroll Dashboard idempotency check failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SELECT migration_key, applied_at FROM schema_migrations WHERE migration_key='2026-09-10-payroll-dashboard-v1'; CHECK TABLE payroll_runs, payroll_items;"
if ($LASTEXITCODE -ne 0) { throw "Payroll Dashboard schema verification failed." }
```

Apply the fixed Daily Rate migration after the Payroll Dashboard update. It converts active Monthly values to
their daily equivalent using `/ 30`, converts active Hourly values using the
configured regular hours per day, removes those choices from active employee
compensation, and adds `payroll_items.monthly_basic_salary`. Existing payroll
money values are not recalculated; their stored `regular_pay` is copied into the
new historical monthly-basic snapshot. Run it twice to prove idempotency:

```powershell
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/daily_rate_payroll_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Daily Rate payroll migration failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/daily_rate_payroll_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Daily Rate payroll idempotency check failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SELECT migration_key, applied_at FROM schema_migrations WHERE migration_key='2026-09-10-fixed-daily-rate-payroll-v1'; CHECK TABLE employees, employee_compensation_history, payroll_items;"
if ($LASTEXITCODE -ne 0) { throw "Daily Rate payroll verification failed." }
```

Keep the verified pre-upgrade backup for any historical audit of former values.

Then apply the flexible Pay Type migration. It provides the existing schema
needed for Daily and Hourly rates without recalculating historical payroll
items. The final Monthly expansion below must also be applied before using
the current Employee, Work Schedule, and Run Payroll forms. Run it twice to
prove idempotency:

```powershell
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/flexible_pay_type_salary_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Flexible Pay Type migration failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/flexible_pay_type_salary_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Flexible Pay Type idempotency check failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SELECT migration_key, applied_at FROM schema_migrations WHERE migration_key='2026-09-11-flexible-pay-types-v1'; CHECK TABLE employees, employee_compensation_history, payroll_items;"
if ($LASTEXITCODE -ne 0) { throw "Flexible Pay Type verification failed." }
```

Immediately afterward, apply the compensation-history integrity update. Older
versions could save a dated compensation row before Employment Type was
required. Such a row correctly takes precedence for its effective date but
cannot be used by payroll, producing the message “no valid effective
compensation or complete HR-approved employment type.” This repair copies only
the missing Employment Type from its owning employee, keeps every rate and
effective date unchanged, and enforces Daily/Hourly on active employee and
history records. Frozen payroll item snapshots are not modified. The repair
now preserves all three supported pay types, so rerunning it cannot narrow a
Monthly column. Run it twice:

```powershell
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/compensation_history_integrity_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Compensation-history integrity migration failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/compensation_history_integrity_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Compensation-history integrity idempotency check failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SELECT migration_key, applied_at FROM schema_migrations WHERE migration_key='2026-09-19-compensation-history-integrity-v1'; SELECT COUNT(*) AS invalid_employee_compensation FROM employees WHERE employment_type NOT IN ('Full-Time','Part-Time') OR pay_type NOT IN ('Daily','Hourly','Monthly') OR basic_rate<=0; SELECT COUNT(*) AS invalid_history_compensation FROM employee_compensation_history WHERE employment_type NOT IN ('Full-Time','Part-Time') OR pay_type NOT IN ('Daily','Hourly','Monthly') OR basic_rate<=0; CHECK TABLE employees, employee_compensation_history, payroll_items;"
if ($LASTEXITCODE -ne 0) { throw "Compensation-history integrity verification failed." }
```

Apply the schedule-based attendance status and absence-payroll migration after
the flexible Pay Type migration. It adds immutable Half-Day and absence payslip snapshot columns
without recalculating released payroll. Run it twice to prove idempotency:

```powershell
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/attendance_status_payroll_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Attendance status payroll migration failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/attendance_status_payroll_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Attendance status payroll idempotency check failed." }
```

Finally, apply the schedule-session attendance migration. It replaces the old
one-IN/one-OUT database constraint with independent Full-Time morning and
afternoon pairs, uses each Part-Time period as its own pair, and changes
overtime to employee-requested/HR-approved only. Run it twice to verify that it
is safe to repeat:

```powershell
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/multi_session_attendance_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Multi-session attendance migration failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/multi_session_attendance_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Multi-session attendance idempotency check failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SELECT migration_key, applied_at FROM schema_migrations WHERE migration_key='2026-09-13-multi-session-attendance-v2'; SELECT employment_type_snapshot, legacy_single_pair FROM attendance LIMIT 0; CHECK TABLE attendance_logs, attendance, overtime_requests;"
if ($LASTEXITCODE -ne 0) { throw "Multi-session attendance verification failed." }
```

Apply the administrator-authentication security migration last. It adds only a
small throttle table containing hashed client/account scopes; it never stores
submitted passwords, usernames, or readable client addresses. Running it twice
is safe and does not alter existing account credentials:

```powershell
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/admin_auth_security_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Administrator authentication security migration failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/admin_auth_security_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Administrator authentication security migration idempotency check failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SELECT migration_key, applied_at FROM schema_migrations WHERE migration_key='2026-09-14-admin-auth-security-v1'; CHECK TABLE admin_auth_throttles;"
if ($LASTEXITCODE -ne 0) { throw "Administrator authentication security verification failed." }
```

Apply the final payroll Pay Type expansion after the older migrations. It adds
Monthly alongside Daily and Hourly to the employee and dated-compensation
enums; existing employee rows and frozen payroll items are preserved. The
Run Payroll engine now requires a valid dated compensation row for every
payroll workday. Review Employee Records and Work Schedule for any employee
whose preview reports missing compensation or schedule before creating a Draft.
This migration is safe to repeat:

```powershell
& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SOURCE database/monthly_pay_type_payroll_update.sql"
if ($LASTEXITCODE -ne 0) { throw "Monthly Pay Type migration failed." }

& $mysql --connect-timeout=5 --host=127.0.0.1 --port=$dbPort --user=root --database=$dbName --execute="SELECT migration_key, applied_at FROM schema_migrations WHERE migration_key='2026-09-28-monthly-pay-type-v1'; SHOW COLUMNS FROM employees LIKE 'pay_type'; SHOW COLUMNS FROM employee_compensation_history LIKE 'pay_type'; CHECK TABLE employees, employee_compensation_history, payroll_items;"
if ($LASTEXITCODE -ne 0) { throw "Monthly Pay Type verification failed." }
```

If an import fails, do not repeatedly retry an unknown error. Preserve the
backup and error output, diagnose the failed statement, and restore the backup
to a new database name if rollback is required. Point `UCCHR_DB_NAME` to that
verified restored database instead of dropping the original.

## 5. Confirm payroll policies before generating payroll

Migration defaults are safe configuration placeholders, not a statement of
labor or company policy. An authorized HR/payroll officer must
review **Payroll → Payroll Settings** before the first production run. Confirm:

- timezone, pay frequency, currency, and rounding rule;
- the 15-minute late grace period and whether Late and Undertime deductions are
  enabled;
- the configurable minimum scheduled coverage for Half-Day and Present
  classification;
- default and employee-specific break minutes;
- each employee's assigned workdays, daily periods, and expected hours used for
  salary, per-minute Late/Undertime, and Overtime calculations;
- whether overtime is enabled and requires approval;
- regular overtime, rest-day, regular-holiday, special-day, and combined-day
  multipliers;
- each employee's employment type, Pay Type, Approved Rate, and compensation
  effective date.

The employee's position is descriptive and is not used as a pay rate. Record a
reason and a new effective date when compensation changes so historical payroll
keeps the correct rate.

## 6. Understand raw scans and processed attendance

`attendance_logs` is the append-only raw event trail received from the biometric
terminal. It records accepted `IN`/`OUT` events and their device/source details.
The existing `attendance` table is the processed one-row-per-employee-per-day
result used by reports and payroll.

The PHP server—not the ESP32—combines raw scans with the assigned work schedule,
break, grace period, leave, holiday/rest-day rules, and approved overtime to
calculate expected times, worked minutes, late minutes, undertime, potential
overtime, approved overtime, absence, and final classification. Do not manually
rewrite raw biometric events to change payroll. Correct the authorized schedule,
leave, holiday, or overtime decision and reprocess the affected day while it is
still editable.

The biometric page keeps a public ESP32 online/offline status display, while
employee names and the requested IN/OUT controls require an authenticated
Administrator session. Neither control can force an invalid punch. After two
matching scans, the ESP32 sends
`eventType: AUTO` plus the selected request. The API validates that request
against the queued command, locks the employee's daily row, then resolves the
actual Time In, Time Out, Already Timed In, or Already Timed Out result from the
saved schedule and current server time. Late and Undertime remain integer
minutes in the database/payroll snapshot; only their UI labels change to
minutes below one hour or hours plus remaining minutes at one hour or more.

For Part-Time employees, each working day can contain one to six separate,
non-overlapping periods. Attendance counts actual overlap with those periods as
eligible regular minutes; time in a configured gap is excluded and is not marked
late. The terminal still records one daily IN/OUT pair, so the employee scans IN
before the first period and OUT after the last one. Payroll multiplies eligible
regular minutes by the employee's effective Hourly rate. Overtime begins after
the final period and is paid only under the configured approval rules.

Migrated legacy attendance may have a clearly labeled synthetic raw event only
to preserve traceability. It must not be presented as a newly captured AS608
scan.

## 7. Use the payroll lifecycle

Payroll advances in one direction:

`Draft` → `For Review` → `Approved` → `Finalized` → `Paid`

- Generate and, if needed, recalculate only a `Draft`. An unreviewed unpaid
  Draft can also be deleted with a required reason, releasing its attendance
  ownership for a replacement run. The attendance itself is retained. A Cash
  Advance created with that Draft is removed only if its source row still
  matches the Draft snapshot, preventing a duplicate deduction on rerun.
- A Draft that has already entered review cannot be deleted, even if returned
  to Draft. Recalculate it in place instead. Paid and Released payroll cannot
  be deleted.
- Review processed attendance, compensation, leave, holiday, approved overtime,
  Late, Undertime, Absence / Unpaid Time (including unpaid half-days), Cash Advance,
  and any retained historical Other deductions on the print-ready payroll
  review sheet before advancing it.
- Approval is the release gate: employee and administrator payslips become
  available as soon as the run is `Approved`.
- Finalized, Paid, and historical Released runs remain visible and immutable;
  they are never silently rewritten or deleted.

Never approve a test payroll in the production database. Use a disposable test
database for workflow practice.

## 8. Run regression tests

PHP syntax check:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object {
    & "C:\xampp\php\php.exe" -l $_.FullName
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax check failed: $($_.FullName)" }
}
```

Run the project tests from the project root:

```powershell
$testFiles = Get-ChildItem -LiteralPath "tests" -Filter "*_test.php" -File |
    Sort-Object Name
foreach ($testFile in $testFiles) {
    & $php $testFile.FullName
    if ($LASTEXITCODE -ne 0) {
        throw "Test failed: $($testFile.Name)"
    }
}
```

The database-backed tests use the `UCCHR_DB_*` settings. Attendance and payroll
integration tests create uniquely named disposable schemas and remove them on
success, so the MySQL test account needs `CREATE DATABASE`/`DROP DATABASE`
permission. The attendance test builds its isolated fixture from the repository
bootstrap and never clones, drops, or writes live application tables. Do not
proceed to production while any test reports a failure.

## 9. Start the website for LAN/ESP32 access

For local testing, a classroom demonstration, or a single-terminal LAN pilot,
start the recovered database and LAN-safe website with the verified launcher:

```powershell
Set-Location -LiteralPath $projectDirectory
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\start-fixed-system.ps1"
```

The launcher prints the computer's current ESP32 API address, checks whether
`hardware/UCC_HR_ESP32/secrets.h` still uses that address, verifies that the
recovered database on port `3307` uses the project data directory and a
loopback-only bind, and binds PHP to `0.0.0.0:8080`. It also rejects an existing
UCC HR listener that is loopback-only. Do not start this recovered installation
with `php.exe -S localhost:8080` or `php.exe -S 127.0.0.1:8080`; neither listener
is reachable from the ESP32.

The browser on the host computer uses `http://localhost:8080/`. The ESP32 must
not use `localhost`; set this in
`hardware/UCC_HR_ESP32/secrets.h` using the computer's current Wi-Fi IPv4:

```cpp
#define API_BASE_URL "http://YOUR-COMPUTER-WIFI-IPV4:8080"
```

Also confirm the Wi-Fi SSID/password, device ID, and device shared secret. The
device secret must match `settings.device_shared_secret`; do not expose it in
screenshots, source control, or deployment notes. Recompile and upload the sketch
after changing `secrets.h`. Device API signatures bind the method, path, device
ID, device capabilities, firmware version, timestamp, nonce, and exact request
body in that order. Recompile and upload the current firmware whenever this
signing contract changes; the server rejects older signatures rather than
accepting an insecure compatibility fallback.

For a new deployment or suspected secret exposure, rotate both copies together:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\rotate-device-secret.ps1"
```

The script generates a cryptographically random 64-character hexadecimal value
without printing it. It stages the firmware file, verifies and commits the
database value, then atomically replaces `secrets.h`. A failed database
connection leaves the active firmware file unchanged. If the process is stopped
between the database commit and file replacement, rerun the same command; its
private pending file safely completes or discards the interrupted rotation.
Upload the firmware immediately afterward.

For reliable connectivity:

1. Connect the computer and ESP32 to the same router/network.
2. Use `ipconfig` to obtain the computer's active Wi-Fi IPv4 address.
3. On a trusted LAN, double-click `enable-mobile-access.cmd` once and choose
   **Yes** when Windows asks for administrator permission. Its firewall rule permits only the XAMPP PHP executable on
   TCP 8080 from `LocalSubnet`, including when Windows labels that Wi-Fi Public.
   It does not open MariaDB or change the network profile. Do not use an
   untrusted/shared public Wi-Fi for sensitive HR data over HTTP.
4. Reserve the computer's IPv4 address in the router, or update
   `API_BASE_URL` and re-upload whenever DHCP changes it.
5. Keep the server window open. The ESP32 heartbeat should show Wi-Fi online and
   a successful API response rather than HTTP `-1`/connection refused.

`start-server.cmd` runs the same verified launcher and warns when port 8080 is
already occupied by a localhost-only or different process. The launcher keeps
`router.php` in the PHP command so database, source, and secret files are not
served from the LAN.

For phone/tablet browser access, copy the **Phone / tablet** address printed
by the launcher on the computer. Open that address on a phone connected to the
same trusted Wi-Fi. `localhost` on a phone refers to the phone, not the
computer.
Keep the server computer awake and the server window open. If the address
changes after switching Wi-Fi, restart the launcher and use its new phone address. The mobile
layout adapts automatically without separate accounts, APIs, or database data.

The PHP command-line development server is intentionally a pilot/development
server, not a production multi-user web server. For a real organization-wide
launch, serve this project with Apache, Nginx/PHP-FPM, or IIS/FastCGI using PHP
8.2+, enable HTTPS, and reproduce every deny rule and security header in the
root `.htaccess` if the chosen server does not read Apache configuration. Keep
MariaDB bound to loopback, expose only the web listener to the private LAN, and
change `API_BASE_URL` to the final HTTPS/LAN address before uploading firmware.
Do not declare a multi-user production launch complete while using `php.exe -S`.

### 9.1 PWA hosting and installation

The app can be installed through its browser-based launcher at `launch.html`.
For testing on the server computer, run `start-server.cmd` or the
`start-fixed-system.ps1` command above and open
`http://localhost:8080/launch.html` in Chrome or Edge. Browsers treat localhost
as a secure development context; this exception does not extend to a plain
HTTP LAN IP address. See the [secure-context guidance](https://web.dev/articles/when-to-use-local-https).

For phones and other computers to install and use the app over the internet:

1. Provision online PHP/MySQL or PHP/MariaDB hosting using Apache,
   Nginx/PHP-FPM, or IIS/FastCGI and apply this guide's database migrations and
   production access rules. Keep the database private; internet clients connect
   to the web server. Do not expose `php.exe -S` or port `3307` to the internet.
2. Configure the final stable HTTPS domain and a valid certificate trusted by
   every client. Serve the whole app over HTTPS, including
   `manifest.webmanifest`, `service-worker.js`, and its static assets. If the
   app is under a subdirectory, keep those files together at that app root.
   Share the final `https://YOUR-DOMAIN/launch.html` address, including any app
   subdirectory, only after it works from another network. A localhost install
   does not automatically move to a production domain; install from the final
   URL on each device.
3. Open the final launcher URL and install using the device's browser:
   **Chrome / Edge** offer **Install UCCHR App** or a browser install option;
   **Android Chrome** may label it **Add to Home screen → Install**.
   **iPhone / iPad Safari** uses **Share → Add to Home Screen → Add** (leave
   **Open as Web App** enabled if shown). **Safari on Mac** uses **File → Add
   to Dock** where supported. Browser prompts vary and may be absent after
   installation; use the browser menu when necessary. See the official
   [Chrome instructions](https://support.google.com/chrome/answer/9658361),
   [Edge instructions](https://support.microsoft.com/en-us/edge/install-manage-or-uninstall-apps-in-microsoft-edge),
   [iPhone instructions](https://support.apple.com/guide/iphone/iphea86e5236/ios),
   and [Mac instructions](https://support.apple.com/en-us/104996).
4. Point the ESP32's `API_BASE_URL` at the same reachable backend base URL,
   including its app subdirectory if applicable, but without `launch.html`.
   For HTTPS, put the correct PEM root/server CA into `SERVER_ROOT_CA`; the
   firmware refuses HTTPS without it. Keep the device ID/shared secret matched
   to that backend, then recompile and upload. Never use `localhost` on the
   ESP32: it would refer to the device itself.

Installation adds a browser-managed client, not PHP or MySQL/MariaDB on each
device. The server must remain online for authentication, attendance, payroll,
employee records, and biometric operations. The service worker caches only
allowlisted public static assets and a generic offline screen. Private PHP
pages and API data remain network-only; offline writes and biometric commands
are not queued.

Public assets are revalidated online so installed clients receive current
CSS and JavaScript. When releasing a changed offline page or changing the
precache list, increment the `CACHE_NAME` version in `service-worker.js` in the
same release to replace the installed offline resources. Keep the
service-worker response set to `no-cache, no-store,
must-revalidate`; the included Apache rules and development router provide it.
For regression checks, run `node tests/pwa_cache_test.cjs` from the app folder.

Before sharing the app, install it from the final URL on a desktop and a phone,
close and reopen it, and confirm the launcher and each intended login route
reach the correct server. In browser developer tools, verify that the manifest,
icons, and service worker load successfully with no certificate errors.
Temporarily disconnect a test device and open a new page: it should show the
generic offline screen, not cached employee/payroll records. Reconnect and
confirm normal login and the existing ESP32 heartbeat/attendance workflow.

## 10. Production smoke test

Use a non-payroll test employee before normal operation:

1. Log in as Administrator and confirm the dashboard and audit log load.
2. Confirm the employee's employment type and pay rate. Assign all work/rest
   days and expected times; for Part-Time staff, add each daily period separately
   and confirm the automatic daily, weekly, and pay-period totals.
3. Enroll or re-enroll the fingerprint, approving enrollment with BTN1, and
   confirm the employee-to-slot mapping on the website.
4. Record one two-scan `IN` and one two-scan `OUT`; verify raw events appear once
   and the processed day shows schedule-derived expected times and calculations.
5. Create/approve a small overtime request only when potential overtime exists,
   then reprocess the day and confirm approved minutes never exceed potential.
6. Generate a short `Draft` payroll, print its detailed review sheet, and verify
   Gross Pay, Late, Undertime, Cash Advance, any retained historical Other value,
   and Net Pay. Do not approve a
   smoke-test run in production.
7. Log in as that employee and confirm only their own view-only attendance,
   schedule, holidays, requests, and eligible payslip data are visible.

Keep the pre-upgrade backup, the post-repair backup, migration output, test
output, and policy approval record with the deployment record.

Each employee consumes five AS608 template slots (Center, Left, Right, Upper,
and Lower). Slots 1–127 support at most 25 complete employee profiles on one
terminal. The Employee Records page must show `5/5` before attendance readiness;
an incomplete profile requires controlled re-enrollment with the employee
present, never a guessed database mapping.

## 11. Schedule daily attendance finalization on Windows

The CLI-only `database/process_daily_attendance.php` entrypoint processes
yesterday by default in `UCCHR_TIMEZONE` (default `Asia/Manila`). It converts the
append-only raw events into processed daily attendance and records a NULL-user
audit event. It does not expose an HTTP route.

Test the command first against a disposable database by setting
`UCCHR_DB_NAME`; do not use a first-time test run against production. Once the
deployment tests and smoke test pass, create a Windows Task Scheduler task with:

- **Trigger:** Daily at 12:10 AM, after the previous calendar day has ended.
- **Program/script:** `C:\xampp\php\php.exe`
- **Add arguments:**
  `"C:\Users\Jules Ivan\Documents\Codex\2026-08-21\cr\outputs\UCC_HR_System\database\process_daily_attendance.php"`
- **Start in:**
  `C:\Users\Jules Ivan\Documents\Codex\2026-08-21\cr\outputs\UCC_HR_System`

The equivalent manual command is:

```powershell
& $php (Join-Path $projectDirectory "database\process_daily_attendance.php")
```

Normal scheduling should keep the default `--force=0`. For an authorized manual
recovery, an exact date and force flag can be supplied explicitly:

```powershell
& $php database\process_daily_attendance.php --date=2026-09-05 --force=0
```

Use `--force=1` only when HR intentionally needs final classification despite
normal deferral rules. The command exits `0` after successful processing/audit,
`1` for a database, processing, or audit failure, and `2` for invalid arguments
or timezone. Configure Task Scheduler to retain task history and investigate any
non-zero result; do not silently retry a failing forced run.
