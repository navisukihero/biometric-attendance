# UCC HR ESP32 Biometric Integration

This folder contains the ESP32 firmware and wiring reference for the UCC HR System. The website is the controller, MySQL is the central record store, and the ESP32 polls the signed PHP API while the terminal is idle.

The browser does not connect to the ESP32 directly. The complete path is:

```text
Browser -> PHP website -> device_commands table
                            ^
                            | signed poll every 2 seconds
                            v
                       ESP32 -> AS608
                            |
                            v
                  PHP API -> attendance/MySQL
```

## Files

- `UCC_HR_ESP32/UCC_HR_ESP32.ino` - ESP32 terminal firmware
- `UCC_HR_ESP32/secrets.example.h` - configuration template
- `UCC_HR_ESP32/secrets.h` - local Wi-Fi/API configuration; keep this private
- `WIRING.md` - ESP32, AS608, OLED, buttons, RGB, and buzzer wiring

The included root `.htaccess` blocks Apache from serving secrets and development files if this project is copied under `htdocs`.

## Start the Website for ESP32 Access

Run PHP from inside the project folder and bind it to every local network interface:

```powershell
cd "C:\Users\Jules Ivan\Documents\Codex\2026-08-21\cr\outputs\UCC_HR_System"
C:\xampp\php\php.exe -S 0.0.0.0:8080 router.php
```

Use `0.0.0.0`, not `localhost`, in the server command. A server bound only to `localhost` cannot accept requests from the ESP32. The `router.php` argument blocks LAN access to `secrets.h`, database scripts, and private application source.

- Website on the same computer: `http://localhost:8080/`
- ESP32 API base URL on the current network: `http://YOUR-COMPUTER-IPV4:8080`
- LAN test from a phone on the same Wi-Fi: `http://YOUR-COMPUTER-IPV4:8080/`

If the phone test cannot open the page, allow `php.exe` through Windows Defender Firewall for private networks. If `ipconfig` later shows a different IPv4 address, update `API_BASE_URL` in `secrets.h` and upload the sketch again.

## Configure and Upload

1. Copy `secrets.example.h` to `secrets.h` if `secrets.h` does not exist.
2. Enter the Wi-Fi name and password in `secrets.h`.
3. Set `API_BASE_URL` to the computer's LAN address and port `8080`. Do not use `localhost` and do not add `/UCC_HR_System` when PHP is started inside that folder.
4. Generate a private 32-byte random secret (64 hexadecimal characters), then
   keep `DEVICE_SHARED_SECRET` exactly equal to `settings.device_shared_secret`
   in MySQL. Rotate both values together and upload the firmware immediately;
   never leave the sample/local-development secret in a deployment.
5. Install the Arduino libraries listed below.
6. Upload `UCC_HR_ESP32.ino` with the normal Arduino Upload button.
7. Open Serial Monitor at `115200` baud.

Required Arduino libraries:

- Adafruit Fingerprint Sensor Library
- ArduinoJson
- U8g2

## Startup and Connection Check

At startup, the firmware:

1. Detects the AS608 at `57600`, `9600`, or `115200` baud.
2. Starts a non-blocking Wi-Fi connection attempt.
3. Synchronizes its clock for signed API authentication.
4. Calls signed `GET /api/device/ping.php`.
5. Displays `API CONNECTED` before entering the idle state.
6. Polls `GET /api/device/commands.php` every two seconds while idle.

Every signed request identifies this build with `X-Firmware-Version: 2026.09.28-fingerprint-capture-v13`. Its HMAC canonical value is `METHOD`, request path, `DEVICE_ID`, `DEVICE_CAPABILITIES`, `FIRMWARE_VERSION`, timestamp, nonce, and exact body joined by newline characters. Device identity owns nonce replay state and command claims, while capabilities authorize the destructive enrollment workflow, so all three metadata fields are signed. There is no legacy-signature fallback, so recompile and upload this firmware when upgrading the matching PHP API. Its capabilities include BTN1-approved five-template enrollment plus session-state, stable punch-id, authenticated capture-time attendance, fail-closed HTTPS certificate verification, and idempotent command-result retry. The API resolves each scan atomically from the employee's assigned schedule and ordered raw punches.

For the normal same-LAN installation, keep `API_BASE_URL` on `http://COMPUTER_IPV4:8080` and leave `SERVER_ROOT_CA` empty. If you later change the URL to `https://`, paste the correct PEM root/server CA into `SERVER_ROOT_CA`. The firmware intentionally rejects HTTPS with an empty CA instead of sending biometric and attendance traffic through an unverified TLS connection.

Serial Monitor prints a heartbeat about every 15 seconds with sensor state, Wi-Fi state, RSSI, ESP32 IP, clock state, attendance mode, poll count, last HTTP status, free/minimum heap, largest allocatable block, and remaining loop-task stack. Uptime must continue increasing without another ROM `rst:` banner.

### Wi-Fi retry versus a real ESP32 restart

Every real boot now prints one diagnostic line like this:

```text
[BOOT] retainedBootCount=1 resetReason=POWER_ON (1)
```

The ROM also prints `rst:...` before the sketch banner. If `[WIFI] Attempt #...` repeats but neither the ROM `rst:` output nor a new `[BOOT]` line appears, the ESP32 has **not** restarted; Wi-Fi has not accepted the connection yet. Check that the SSID/password are exact, the network is 2.4 GHz, and the ESP32 is in range.

Wi-Fi retries no longer block the main loop or call `WiFi.begin()` on a fixed ten-second cycle. Each attempt has a 15-second limit, then its ESP-IDF driver attempt is explicitly cancelled before the retry delay backs off from 5 to 10, 20, 40, and at most 60 seconds. The firmware's state machine exclusively owns both initial connection and reconnection; SDK auto-reconnect is disabled so two reconnect mechanisms cannot overlap and produce `sta is connecting, cannot set config`. API polling is paused while Wi-Fi or the clock is unavailable.

After changing `WIFI_SSID`, `WIFI_PASSWORD`, or `API_BASE_URL`, save `secrets.h` and upload the sketch again; changing the file cannot update firmware that is already running on the ESP32. For an iPhone hotspot, turn on **Maximize Compatibility** so the hotspot offers 2.4 GHz Wi-Fi supported by the ESP32.

The reset reason identifies a genuine hardware/software reset:

- `BROWNOUT`: the voltage dipped when Wi-Fi, the AS608, OLED, RGB, or buzzer drew current. Use a stable USB/5V supply, avoid a charge-only or damaged USB cable, and keep all grounds common.
- `TASK_WATCHDOG`, `INTERRUPT_WATCHDOG`, `OTHER_WATCHDOG`, or `PANIC_EXCEPTION`: preserve the exception/backtrace printed immediately before the next boot line for diagnosis.
- `POWER_ON` or `EXTERNAL_PIN`: power was applied or the EN/reset button/pin was triggered.

The current firmware also fixes a former `HTTPClient` lifetime error that could print `Rebooting...` followed by `rst:0xc (SW_CPU_RESET)` immediately after a successful PHP response. API network clients now outlive `HTTPClient`, connections are explicitly closed, failed requests use short bounded timeouts, and command polling backs off until the authenticated API is reachable again.

After uploading a firmware update, verify at least 100 consecutive command polls (about four minutes). There should be one startup `[BOOT]` line, steadily increasing heartbeat uptime, and no later `Rebooting...`/ROM `rst:` banner. If a panic still occurs, copy the complete `Guru Meditation Error`, register dump, and `Backtrace:` lines printed immediately before `Rebooting...`; the boot banner alone cannot identify a second crash site.

## Website Command Flow

### Enroll or re-enroll

1. Save the employee's personal and work information.
2. Click the website's enroll or re-enroll fingerprint action.
3. The website queues a versioned `ENROLL` command using the employee's canonical CENTER slot plus five physical sensor slots recorded in `fingerprint_template_slots`. `employees.fingerprint_code` remains the canonical compatibility value.
4. The idle ESP32 receives it automatically within about two seconds.
5. The ESP32 records the exact command UUID, fingerprint slot, and mapping version, reports `AWAITING_APPROVAL` to PHP, and displays `BTN1=OK BTN2=CANCEL`. It does **not** scan yet.
6. Press Button 1 once to approve. The ESP32 first confirms that the sensor glass is clear, then asks PHP to validate the same command/slot/version and report `SCANNING` before changing anything in the AS608. If a thumb is still present, or PHP/Wi-Fi is unavailable, approval pauses safely and can be tried again.
7. Alternatively, press Button 2 while approval is waiting to reject the command before any sensor template is changed.
8. The ESP32 guides CENTER, LEFT, RIGHT, UPPER, and LOWER in that order. Each position is captured twice at the same angle because the AS608 `createModel()` command requires two matching characteristic buffers.
9. For each position the ESP32 performs image capture, feature-quality validation, a confirmed full removal, duplicate ownership checks on both impressions and the completed model, `createModel()`, `storeModel()`, and exact-page reload verification. Starting with LEFT, every position must also search back to the same employee profile established by CENTER. PHP only supplies the command and slot association.
10. Completely remove the thumb when the OLED says `SCAN CAPTURED`. Stable `NOFINGER` sampling prevents one placement from being reused accidentally, and each mismatched position receives up to three bounded retries. The OLED then names the next angle or asks for the same angle again.
11. A fingerprint assigned to another employee is rejected. A template assigned to the same employee is allowed during re-enrollment, while a database-confirmed orphan may be cleared.
12. After all five physical templates are stored, the ESP32 reports success. PHP marks the five slots and canonical employee profile Enrolled in one transaction. If a destructive attempt fails partway through, the ESP32 clears the partial five-slot sensor profile before PHP records the mapping as Failed, preventing ghost matches.

Re-enrollment reuses the same five physical sensor slots with a newer mapping version, so every replacement remains associated with the same employee. A delayed result from an older version is rejected.

The AS608 has two characteristic buffers per model. To make all five angles useful during later attendance recognition, this implementation stores five real models per employee—one for each position. Each position therefore asks for an initial placement and one confirmation placement.

### Website-controlled attendance

1. Select an enrolled employee and choose the requested `IN` or `OUT` action on the biometric terminal page. Both controls remain available.
2. Click `Scan Fingerprint`.
3. The website queues a `VERIFY_ATTENDANCE` command containing the selected employee's expected sensor slot and attendance event.
4. The ESP32 asks for two scans.
5. Each physical match is resolved through the API to its canonical employee profile. The two scans may match different angle slots, but both must resolve to the same selected employee.
6. Only then does the ESP32 submit `fingerprintSlot`, `eventType: AUTO`, the selected `requestedEventType`, `commandId`, and `verifiedTwice: true` to the attendance API.
7. PHP verifies that the requested action matches the queued command, then uses the employee's assigned schedule and locked daily attendance row to return the safe actual action: `TIME_IN`, `TIME_OUT`, `ALREADY_IN`, or `ALREADY_OUT`. The website shows that actual result immediately through status polling.

Wrong fingers, mismatched scans, sensor errors, removal timeouts, and scan timeouts are rejected and reported to the command record.

If Wi-Fi drops after either a successful or rejected website verification, the
ESP32 retains that exact command result in RAM and keeps the terminal at
`SAVING RESULT`. It retries the idempotent result endpoint after connectivity
returns instead of fetching the same command and asking the employee to scan
again. Do not reset or remove power while this message is displayed.

### Autonomous attendance

An employee may also place a finger while the terminal is idle. Before treating that placement as attendance, the ESP32 performs a final command check so a newly queued enroll/re-enroll request takes priority. Autonomous attendance still requires two scans that resolve to the same enrolled employee before anything is posted. It submits `eventType: AUTO`; under a database row lock, PHP checks today's attendance, any valid open overnight shift, and the employee's saved schedule before returning `TIME_IN`, `TIME_OUT`, `ALREADY_IN`, or `ALREADY_OUT`.

An immediate repeat scan cannot accidentally create the next punch. For a Full-Time 08:00-17:00 schedule with a 60-minute break, PHP derives Morning 08:00-12:00 and Afternoon 13:00-17:00, producing `IN, OUT, IN, OUT`. A completed morning or afternoon session can become Half-Day; all required sessions must be completed for Present. Part-Time punch count comes only from that employee's explicitly assigned periods (or one continuous legacy shift), never from the Full-Time rule. A first 14:00 arrival for the standard schedule is retained for audit but is immediately Absent because no eligible half-day remains.

PHP remains authoritative for Present/Half-Day/Absent status and the Late and Undertime minute conditions. Late and Undertime stay stored as integer minutes for accurate payroll deductions; the website and OLED format totals below 60 as minutes and totals of 60 or more as hours plus remaining minutes. Work beyond Expected Out may be retained internally only to validate a manually submitted employee request. It never creates an overtime request. Attendance and payroll expose overtime only after HR/Admin approves that Employee request.

A successful Time In is stored as `INCOMPLETE` and shown to users as `Pending Time Out`; it is never counted as a completed attendance day. While the terminal is connected, its authenticated command heartbeat checks due open rows at a throttled interval. Once the saved Expected Out passes without Time Out, PHP changes the workday to Absent. The nightly attendance CLI remains the fallback when the terminal or network is offline.

Some AS608-compatible firmware returns search status `0x17` when the finger was not fully moved between captures. This build treats that as a residual-finger condition, not as an unknown employee or corrupt database. It preserves the converted template buffer, asks for complete removal, retries the search only a bounded number of times, and never clears sensor templates solely because of `0x17`.

## Controls and Indicators

- Button 1 / GPIO 22: select Time In while idle; approve a waiting enrollment
- Button 2 / GPIO 21: select Time Out while idle; cancel a waiting enrollment before scanning
- Blue RGB: idle and ready
- Amber RGB: connecting or scanning
- Green RGB with two high beeps: success
- Red RGB with failure tones: sensor, verification, or connection failure

All capture/removal loops have timeouts; the terminal never waits forever for a finger. Enrollment removal uses a generous 30-second bound and requires a short stable `NOFINGER` period so a partial lift cannot be mistaken for permission to capture the next angle. Waiting for physical enrollment approval is intentionally not a sensor capture loop: normal firmware execution continues, signed API heartbeats keep the website status online, and no AS608 template changes until Button 1 is pressed. During time-critical fingerprint capture/removal, Wi-Fi servicing and serial heartbeats continue, but signed HTTP pings are deferred so a slow network request cannot interrupt sensor sampling. Signed enrollment progress updates still occur between guided positions. Wi-Fi reconnection uses bounded attempts with exponential backoff, while API authentication, clock synchronization, AS608 detection, and unsynchronized command results are retried automatically.

## Safe Deployment Order

1. Deploy the PHP/API update and import `database/fingerprint_five_template_update.sql`. The script avoids unsupported `CHECK` syntax; PHP and firmware enforce slots 1–127.
2. Upload this revised sketch to the ESP32 with the normal Arduino **Upload** action, not the OpenOCD Debug action.
3. Confirm Serial Monitor prints `Firmware: 2026.09.28-fingerprint-capture-v13` and advertises `attendance-session-state-v1`, `attendance-punch-id-v1`, `attendance-capture-time-v1`, `https-ca-required-v1`, and `command-result-retry-v1`.
4. Queue an enrollment from Employee Records. The OLED must wait at `BTN1=OK BTN2=CANCEL`; the scanner must not start by itself.
5. Press Button 1, complete CENTER, LEFT, RIGHT, UPPER, and LOWER, confirming each position when prompted and fully lifting between placements.

## Database Setup

For a fresh installation, import `database/ucchr.sql` and then
`database/integrated_attendance_payroll_update.sql`. For an existing
installation, follow `DEPLOYMENT.md` and import only the applicable additive
updates, including `database/fingerprint_five_template_update.sql`. The device
secret in the database must match `DEVICE_SHARED_SECRET` in `secrets.h`.

Five physical templates are stored for every employee profile. Because the
configured AS608 range is 1–127, a single terminal supports at most 25 complete
five-position employee profiles. Plan an additional terminal before that limit;
never reuse another employee's slot or guess a missing mapping.

## AS608 Diagnostic

Expected startup output includes:

```text
[AS608] Detected successfully at 57600 baud.
[WIFI] Connected. ESP32 IP: ...
[CLOCK] Synchronized. Epoch: ...
[API] AUTHENTICATED at http://192.168.1.4:8080 ...
```

If all AS608 baud rates report no reply, check 5V/VIN, shared ground, and crossed UART wiring: AS608 TX goes to GPIO 33 and AS608 RX goes to GPIO 32. The API URL does not affect sensor detection.
