# UCCHR — Fingerprint Biometric Attendance & Payroll System

A complete PHP 8 + MySQL/MariaDB implementation of the supplied Ubay Community College HR interface. It includes:

- Admin authentication and account security
- Separate employee login with view-only profile, biometric attendance, schedule, and approved payslip access
- Database-backed username changes and verified current-password recovery
- Responsive dashboard and navigation
- Employee search, add, edit, archive, and website-controlled ESP32 fingerprint enrollment/re-enrollment
- Signed ESP32 biometric time-in/time-out API with OLED, buttons, RGB, and buzzer support
- Flexible per-day Full-Time/Part-Time schedules with expected time, lateness, worked-time, rest-day, and overtime tracking
- Biometric attendance-based payroll with hourly Part-Time pay, schedule-based Late/Undertime deductions, approved Overtime/Holiday earnings, Cash Advance entry during payroll generation, payslips, and payroll history
- Attendance/payroll CSV exports and print-ready PDF reports
- Reports dashboard, profile activity history, and system settings

For an existing installation, follow the ordered [integrated attendance and
payroll deployment guide](DEPLOYMENT.md). It covers backup, the guarded MariaDB
error-1932 fingerprint-table repair, the idempotent migration, policy approval,
regression tests, and ESP32 LAN deployment.

## Requirements

- PHP 8.1+ with PDO MySQL
- MySQL 8+ or MariaDB 10.4+
- Apache, Nginx, or PHP's built-in development server

## Quick setup with XAMPP

1. Start MySQL in XAMPP.
2. For a fresh database, import `database/ucchr.sql` in phpMyAdmin and then
   import `database/integrated_attendance_payroll_update.sql` into that same
   database. The second file adds raw attendance, flexible part-time schedule
   periods, leave/holiday/overtime, compensation history, and the controlled
   payroll lifecycle. For an existing database, do not import the fresh schema
   over live data; follow `DEPLOYMENT.md` and apply only the ordered updates.
   Existing installations must also apply
   `database/remove_obsolete_deductions_update.sql` and
   `database/retire_admin_reset_tokens_update.sql`, followed last by
   `database/payroll_review_automation_update.sql`, then
   `database/payroll_dashboard_update.sql`, then
   `database/daily_rate_payroll_update.sql`, then
   `database/flexible_pay_type_salary_update.sql`, and finally
   `database/compensation_history_integrity_update.sql`. The final update
   repairs older dated compensation rows whose Employment Type was missing and
   enforces Daily/Hourly for current employee setup. Legacy Monthly values stay
   readable only in immutable payroll snapshots. These updates are guarded and
   safe to run again.
3. Start the recovered database and website together from PowerShell:

```powershell
cd "C:\Users\Jules Ivan\Documents\Codex\2026-08-21\cr\outputs\UCC_HR_System"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\start-fixed-system.ps1"
```

On Windows, you can instead double-click `start-server.cmd`. The launcher starts
the recovered MariaDB data on loopback-only port `3307`, verifies its InnoDB
tables and bind address, and binds the website to `0.0.0.0:8080` so the ESP32 can
reach the API.

4. Open `http://localhost:8080/` on the computer. The ESP32 must use the computer's current LAN address shown by `start-server.cmd`, for example `http://192.168.1.4:8080`. Check `ipconfig` whenever the computer joins a different router, and reserve that address in the router for a stable deployment.

Employee login is available at `http://localhost:8080/employee-login.php`.

Fresh-database demo credentials (change these after the first login):

- Username: `admin`
- Password: `admin123`

## Database configuration

Defaults are in `config/database.php`. The recovered installation uses database
port `3307` so it cannot be confused with XAMPP's normal port `3306` data. You
can also set:

- `UCCHR_DB_HOST`
- `UCCHR_DB_PORT`
- `UCCHR_DB_NAME`
- `UCCHR_DB_USER`
- `UCCHR_DB_PASS`

## Development server

For this recovered installation, use the verified launcher:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\start-fixed-system.ps1"
```

Then open `http://localhost:8080/`. Binding to `0.0.0.0` is required because a server bound to `localhost` cannot accept ESP32 connections from Wi-Fi. Keep `router.php` in the command so database, source, and ESP32 secret files cannot be downloaded from the LAN.

## Open from a phone on the same Wi-Fi

1. Start `start-server.cmd` on the computer and leave the server running.
2. Copy the **Phone / tablet** address printed by the launcher on the computer.
3. Connect the phone to the same trusted Wi-Fi and open that address in its
   browser. Do not type `localhost` on the phone: that means the phone itself.
4. If Windows Firewall blocks the connection, double-click
   `enable-mobile-access.cmd` and choose **Yes** at the Windows permission prompt.
   You can also run the following in a normal PowerShell window; the script
   requests administrator permission automatically:

   ```powershell
   powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\Users\Jules Ivan\Documents\Codex\2026-08-21\cr\outputs\UCC_HR_System\enable-mobile-access.ps1"
   ```

   This permits only `C:\xampp\php\php.exe`, TCP 8080, from the local subnet.
   It does not open MariaDB, disable the firewall, or set up router forwarding.
   Use a trusted Wi-Fi network; HTTP does not encrypt login or payroll traffic.

The phone/tablet layout adapts automatically: larger controls, collapsible
navigation, single-column forms, and labelled record cards on small phones.
Complex schedules and printable documents keep their original table layout
with horizontal scrolling inside the document, not across the entire page.
There is no separate database or mobile login account.

Refresh the phone-address page whenever Wi-Fi/IP changes. Guest Wi-Fi may block
device-to-device connections. Access from mobile data or a different network
requires HTTPS hosting; a home-screen shortcut does not host PHP or the database.

## Install the UCCHR app (PWA)

For a test on the server computer, run `start-server.cmd` or
`start-fixed-system.ps1` as above, then open
`http://localhost:8080/launch.html` in Chrome or Edge. Choose **Install UCCHR
App** when offered, or the browser's install-app option. The installed launcher
opens Administrator Login, Employee Login, or the Biometric Terminal.

For installation on phones and other computers over the internet, first host
the PHP application and its MySQL/MariaDB database on an online server using
Apache, Nginx/PHP-FPM, or IIS/FastCGI. Use the final stable HTTPS domain with a
certificate trusted by each device, then open its `launch.html`. Do not expose
PHP's built-in development server to the internet. The local address above
works only on the server computer; plain HTTP at a LAN IP does not provide the
secure context required for the full PWA features.

- **Chrome / Edge on desktop or Android:** choose **Install UCCHR App** when
  offered, or use the browser's **Install app** option. Android Chrome may show
  **Add to Home screen → Install**.
- **iPhone / iPad:** open the site in Safari, select **Share → Add to Home
  Screen**, and confirm **Add**. Keep **Open as Web App** enabled if shown.
- **Safari on Mac:** choose **File → Add to Dock** where supported.

Installation adds the web client to the device; it does not install PHP or
MySQL there. Attendance, payroll, login, private pages, and APIs still require a
live connection to the same backend. Only public static assets and a generic
offline screen are cached; private records and biometric commands are not
available or queued offline. The ESP32 keeps using that backend: set its
`API_BASE_URL` to the reachable server address, supply the correct PEM CA in
`SERVER_ROOT_CA` for HTTPS, and recompile/upload after changes. Never set the
ESP32 address to `localhost`.

See [PWA hosting and installation checks](DEPLOYMENT.md#91-pwa-hosting-and-installation)
for deployment verification and browser-specific references.

## Account changes and password recovery

- Open `My Profile` to change the full name, username, or email. The current password is required, and the new values are written directly to the `users` table.
- Use the Security form on `My Profile` to change the password. Only a secure password hash is stored in `users.password_hash`.
- Use `Forgot password?` on the login screen to verify the registered username or email, verify the current password, and then choose and confirm a different password. The staged verification expires after 10 minutes, allows at most five old-password attempts, and updates `users.password_hash` atomically before returning to Login.
- Passwords are never stored or retrieved as readable text. PHP compares the entered password to the one-way hash with `password_verify()`. Import `database/password_hash_integrity_update.sql` once on an existing installation to prevent a blank or plain-text value from replacing the hash.
- Existing installations must also import `database/admin_auth_security_update.sql` once. It adds privacy-preserving, persistent five-attempt throttling for administrator login and current-password recovery; the table stores only hashed client/account scopes, never passwords, usernames, or readable IP addresses.

## Employee portal

Employee credentials are stored in `employee_accounts`, not the administrator `users` table. The employee session, routes, navigation, and actions are isolated from the admin system. An employee can view only records linked to their authenticated database employee ID and cannot open employee-management, attendance-editing, payroll-management, settings, biometric-control, or delete functions.

To activate a new or existing employee:

1. Open **Employee Records → Edit** for that employee.
2. Complete fingerprint enrollment and assign a work schedule as needed.
3. In **Employee Portal Access**, either create a private one-time setup link or assign an 8–72 character temporary password.
4. The username is the employee's unique Employee ID, such as `EMP-0010`.
5. A temporary password must be changed at first login. A setup link lets the employee create their own password immediately.

Employees use **Forgot password?** to submit their Employee ID and registered email. HR sees the request on the employee record and can issue a one-time reset link or temporary password. One-time links expire after 24 hours, old links are revoked, and only SHA-256 token digests are stored. Passwords are always stored with PHP's secure one-way password hashing.

The portal reads the existing synchronized records directly:

- AS608/ESP32 biometric scans → `attendance.employee_id`
- Daily Time Record preview/print → the same processed `attendance.employee_id` rows for the employee-selected date range
- Assigned schedule → `work_schedules.employee_id`
- Approved and historical finalized/paid/released payslips → `payroll_items.employee_id`

No ESP32 firmware, device API, or duplicate DTR table is required for the portal. Employees can generate a view-only **Daily Time Record** for up to 366 calendar days; its preview and print route are ownership-checked and support the browser's **Print / Save as PDF** action. Printable payslips remain ownership-checked as well.

## Fingerprint hardware integration

The complete ESP32 sketch, secrets template, wiring map, and setup guide are in `hardware/`. Save the employee first, then click `Sync / Enroll Fingerprint` on the employee record. The command waits safely at the terminal until an operator presses BTN1; the ESP32 then stores five physical AS608 templates in Center, Left, Right, Upper, and Lower order. Each position is confirmed twice so the AS608 can create a valid model. BTN2 cancels before scanning. Attendance still uses two scans, and either scan may match any of the employee's five templates. Firmware must advertise both `enroll-btn1-v1` and `enroll-five-template-v1`.

`Open Biometric Terminal` is the live controller for attendance. Selecting an employee and `IN` or `OUT` queues a signed device command; the ESP32 requires two matching scans for that employee, saves the result through the PHP API, and the page updates automatically. Every HMAC binds the method, path, device ID, device capabilities, firmware version, timestamp, nonce, and exact body in that order. Firmware built against an older signing contract must be recompiled and uploaded; the API deliberately has no unsigned compatibility fallback. Payroll counts verified biometric attendance days and schedule-based overtime in the selected period.

Idle-terminal attendance is schedule-driven. BTN1 and BTN2 retain the familiar IN/OUT mode display, while the ESP32 sends `AUTO` so the API's locked session state prevents a wrong or duplicate punch. A Full-Time 08:00-17:00 schedule with a 60-minute break requires Morning IN/OUT and Afternoon IN/OUT; all four complete Present, while one eligible completed session becomes Half-Day after the day closes. Part-Time employees use only their individually assigned periods. A lone/open punch is never Present, and an ineligible first arrival such as 14:00 for the standard schedule is Absent. Extra time never creates an overtime request: only an employee-submitted request approved by HR/Admin appears as Approved OT or becomes payroll-eligible.

The AS608 stores five real templates per complete employee profile. With the
configured slot range of 1–127, one terminal can therefore hold at most 25
complete five-position profiles. Use another terminal/sensor or a different
biometric architecture before enrolling a 26th employee; do not silently fall
back to single-position enrollment.

For an existing installation, import `database/device_update.sql`, `database/iot_schedule_update.sql`, `database/fingerprint_mapping_update.sql`, and `database/fingerprint_five_template_update.sql` once before using the hardware workflow. The five-template update intentionally avoids `CHECK` syntax for compatibility with older MySQL/MariaDB/phpMyAdmin builds; PHP and the ESP32 enforce the AS608 slot range.

Administrator recovery uses the staged username/email, current-password, and
new-password forms; reset links and email delivery are not part of this flow.
Existing installations that previously used administrator reset tokens can
import `database/retire_admin_reset_tokens_update.sql` once. This does not affect
the separate employee setup/reset links in `employee_account_tokens`.

Current employee compensation supports Daily and Hourly Approved Rates.
Expected Monthly Salary is `Daily Rate × scheduled workdays` or `Hourly Rate ×
scheduled calendar-month hours`. Generated Hourly basic pay uses the hours
assigned in the selected payroll period; Daily basic pay uses its scheduled
workdays. Verified attendance remains separate: late, undertime, half-day,
and absent/unpaid time reduce Net Pay once below the scheduled Gross Basic.
Monthly compensation is prorated to the selected calendar period, with its
assigned schedule determining unpaid attendance deductions. Approved Overtime
is added only after approval. An optional Cash Advance is entered
directly in **Run Payroll → Generate Payroll** and saved atomically with the
Draft. Retained historical Other entries remain readable in old payroll
snapshots but cannot be created from the active interface.
Every new payroll reconciles the frozen equation `Gross Pay − Late − Undertime
− Absence / Unpaid Time − Cash Advance = Net Pay`. The unpaid half of a validated
Half-Day is included in Absence / Unpaid Time, not deducted twice. Active legacy
Other deduction entries must be reviewed before a new Draft can be created; old
approved slips retain their original frozen values. Generation
stops with a clear validation error if deductions exceed Gross Pay instead of
silently storing a non-reconciling zero or negative amount.
Legacy Monthly payroll snapshots remain readable but Monthly cannot be selected
for a new or updated employee. SSS, PhilHealth, Pag-IBIG, tax,
and broad policy-deduction fields are not part of the active model. On an upgraded installation,
`database/remove_obsolete_deductions_update.sql` first retires the former broad
model, then `database/payroll_review_automation_update.sql` adds only the current
transparent fields and monthly schedule support. Keep the pre-upgrade backup if
former released values must be audited later.

## Employee work schedules

Every newly registered employee is saved as Active and appears immediately in
**Work Schedule**. Select the employee, choose Full-Time or Part-Time, check the
working days, and save one complete seven-day assignment. Full-Time days use one
Expected In/Out shift and an optional unpaid break. Part-Time days can contain
one to six non-overlapping periods; for example, `9:00 AM–12:00 PM` plus
`1:00 PM–2:00 PM`. The page automatically calculates each day's required hours,
the weekly total, and the scheduled total for a selected pay-period preview.

Full-Time and Part-Time employees may use a Daily or Hourly Approved Rate.
Attendance
freezes the periods that applied on the work date and counts only actual time
overlapping those periods for late, undertime, and approved-overtime review. A
gap between periods is not worked time and is not late time. The existing
biometric flow still stores one daily Time In and
one daily Time Out, so an employee scans **IN before the first period** and
**OUT after the final period**; the server excludes configured gaps automatically.
Approved overtime remains separate. One schedule save also synchronizes current
unfinished attendance rows without rewriting finalized history.

Existing installations that already completed the integrated migration must
import `database/part_time_schedule_periods_update.sql` once before using split
periods. The migration is additive: it keeps existing schedule rows and converts
an old Part-Time break into a centered gap with the same net required hours so
HR can review and replace it with the employee's exact periods.

The Add/Edit Employee pages show **Expected Monthly Salary** and update it from
the selected Pay Type: Daily is `Rate × scheduled workdays for the calendar
month`, while Hourly is `Rate × scheduled hours for the calendar month`. PHP independently
enforces the actual payroll formula. Each payroll item stores its Pay Type,
Approved Rate, calculated basic salary, approved overtime, deductions, and a
complete calculation snapshot. Later employee-rate changes cannot rewrite an
existing payslip.

The normal payroll release flow is **Draft → For Review → Approved**. HR prints
the detailed review sheet before approval. An Approved run immediately becomes
available as an ownership-checked payslip; older Finalized, Paid, and Released
runs remain readable for historical compatibility.

The database still keeps an organization fallback so attendance remains usable before HR completes an employee assignment. The former `Source: Default` filter was removed from the interface: it was only a technical label meaning “no employee assignment was saved for this day,” not a separate schedule HR needed to choose. New attendance snapshots use the employee assignment first and the fallback only when an assignment is missing.
