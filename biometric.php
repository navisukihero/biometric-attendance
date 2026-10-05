<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';

header('Cache-Control: no-store');

$adminAuthorized = admin_session_is_valid($pdo);
$employees = [];
$commandId = '';
$pageMode = 'waiting';
$pageMessage = $adminAuthorized
    ? 'Select an employee and attendance action to activate the scanner.'
    : 'Administrator login is required to select an employee or send scanner commands.';
$schemaError = '';

try {
    if (!$adminAuthorized) {
        throw new LogicException('AUTH_REQUIRED');
    }
    $eventColumn = $pdo->query("SHOW COLUMNS FROM device_commands LIKE 'event_type'")->fetch();
    $typeColumn = $pdo->query("SHOW COLUMNS FROM device_commands LIKE 'command_type'")->fetch();
    $mappingTable = $pdo->query("SHOW TABLES LIKE 'fingerprint_registrations'")->fetch();
    if (!$eventColumn || !$typeColumn || !$mappingTable || !str_contains((string) ($typeColumn['Type'] ?? ''), 'VERIFY_ATTENDANCE')) {
        $schemaError = 'The biometric terminal database update has not been installed. Import the latest database update SQL first.';
    } else {
        $employees = $pdo->query(
            'SELECT e.id, e.employee_no, e.first_name, e.last_name,
                    fr.fingerprint_slot AS fingerprint_code
             FROM fingerprint_registrations fr
             JOIN employees e ON e.id=fr.employee_id
             WHERE e.status="Active"
               AND e.fingerprint_status="Enrolled"
               AND fr.mapping_status="Enrolled"
               AND e.fingerprint_code REGEXP "^[0-9]+$"
               AND CAST(e.fingerprint_code AS UNSIGNED)=fr.fingerprint_slot
               AND NOT EXISTS (
                   SELECT 1 FROM device_commands pending_enrollment
                   WHERE pending_enrollment.employee_id=e.id
                     AND pending_enrollment.command_type="ENROLL"
                     AND pending_enrollment.status IN ("Pending","Running")
               )
             ORDER BY e.last_name, e.first_name'
        )->fetchAll();
    }
} catch (LogicException $exception) {
    if ($exception->getMessage() !== 'AUTH_REQUIRED') {
        $schemaError = 'The biometric command tables are unavailable.';
    }
} catch (Throwable) {
    $schemaError = 'The biometric command tables are missing. Import the latest database update SQL first.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    if (!$adminAuthorized) {
        http_response_code(401);
        $payload = ['ok' => false, 'error' => 'Administrator login is required to control the biometric terminal.'];
        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        } else {
            header('Location: index.php');
        }
        exit;
    }
    verify_csrf();
    $employeeId = filter_input(INPUT_POST, 'employee_id', FILTER_VALIDATE_INT) ?: 0;
    $eventType = strtoupper(trim((string) ($_POST['event_type'] ?? '')));
    $responseStatus = 200;

    try {
        if ($schemaError !== '') {
            throw new RuntimeException($schemaError);
        }
        if ($employeeId <= 0 || !in_array($eventType, ['IN', 'OUT'], true)) {
            $responseStatus = 422;
            throw new RuntimeException('Choose an enrolled employee and select Time In or Time Out.');
        }

        $stmt = $pdo->prepare(
            'SELECT e.id, e.employee_no, e.first_name, e.last_name,
                    fr.fingerprint_slot AS fingerprint_code
             FROM fingerprint_registrations fr
             JOIN employees e ON e.id=fr.employee_id
             WHERE e.id=? AND e.status="Active"
               AND e.fingerprint_status="Enrolled"
               AND fr.mapping_status="Enrolled"
               AND e.fingerprint_code REGEXP "^[0-9]+$"
               AND CAST(e.fingerprint_code AS UNSIGNED)=fr.fingerprint_slot
               AND NOT EXISTS (
                   SELECT 1 FROM device_commands pending_enrollment
                   WHERE pending_enrollment.employee_id=e.id
                     AND pending_enrollment.command_type="ENROLL"
                     AND pending_enrollment.status IN ("Pending","Running")
               )
             LIMIT 1'
        );
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();
        if (!$employee || (int) $employee['fingerprint_code'] <= 0) {
            $responseStatus = 422;
            throw new RuntimeException('This employee does not have a usable enrolled fingerprint slot.');
        }

        // The administrator terminal controls one physical scanner. A newer request
        // intentionally supersedes any older attendance verification request.
        $pdo->prepare('UPDATE device_commands SET status = "Failed", result_message = "Superseded by a newer terminal scan.", completed_at = NOW() WHERE command_type = "VERIFY_ATTENDANCE" AND status IN ("Pending", "Running")')->execute();

        $commandId = bin2hex(random_bytes(32));
        $requestedBy = (int) $_SESSION['user_id'];
        $stmt = $pdo->prepare('INSERT INTO device_commands (command_uuid, command_type, employee_id, fingerprint_slot, event_type, requested_by, requested_at) VALUES (?, "VERIFY_ATTENDANCE", ?, ?, ?, ?, NOW())');
        $stmt->execute([$commandId, (int) $employee['id'], (int) $employee['fingerprint_code'], $eventType, $requestedBy]);

        $_SESSION['terminal_commands'] ??= [];
        $_SESSION['terminal_commands'][$commandId] = time();
        foreach ($_SESSION['terminal_commands'] as $knownId => $createdAt) {
            if ((int) $createdAt < time() - 3600) {
                unset($_SESSION['terminal_commands'][$knownId]);
            }
        }

        $pageMode = 'pending';
        $pageMessage = 'Command queued. The ESP32 will ask for two matching fingerprint scans.';
        $payload = [
            'ok' => true,
            'commandId' => $commandId,
            'status' => 'Pending',
            'employeeName' => trim((string) $employee['first_name'] . ' ' . (string) $employee['last_name']),
            'employeeNo' => (string) $employee['employee_no'],
            'eventType' => $eventType,
            'message' => $pageMessage,
        ];
    } catch (Throwable $error) {
        $pageMode = 'error';
        $knownMessages = array_filter([
            $schemaError,
            'Choose an enrolled employee and select Time In or Time Out.',
            'This employee does not have a usable enrolled fingerprint slot.',
        ]);
        if (in_array($error->getMessage(), $knownMessages, true)) {
            $pageMessage = $error->getMessage();
        } else {
            error_log('[UCCHR biometric terminal] Command failed: ' . $error->getMessage());
            $pageMessage = 'The scanner command could not be created. Check the server log and terminal connection.';
        }
        $payload = ['ok' => false, 'error' => $pageMessage];
        if ($responseStatus === 200) {
            $responseStatus = 503;
        }
    }

    if ($isJson) {
        http_response_code($responseStatus);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#a8172d">
    <?= pwa_head_tags() ?>
    <title>Biometric Terminal | UCCHR</title>
    <link rel="icon" href="assets/images/ucclogo.jpg" type="image/jpeg">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <style>
        .terminal-online-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin: 14px 0 0;
            font-size: 12px;
            font-weight: 800
        }

        .terminal-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #999;
            box-shadow: 0 0 0 4px #ffffff20
        }

        .terminal-dot.online {
            background: #38df62;
            box-shadow: 0 0 12px #38df62
        }

        .terminal-dot.offline {
            background: #ff516b
        }

        .scanner-box {
            margin-top: 34px
        }

        .scanner-box.pending {
            background: rgba(235, 174, 0, .72);
            border-color: #ffe185
        }

        .scanner-box.running {
            background: rgba(15, 91, 154, .78);
            border-color: #72c5ff
        }

        .scanner-box.pending h1,
        .scanner-box.pending p,
        .scanner-box.running h1,
        .scanner-box.running p {
            color: #fff
        }

        .terminal-fingerprint-visual {
            position: relative;
            display: grid;
            place-items: center;
            flex: 0 0 108px;
            width: 108px;
            height: 108px;
            overflow: hidden;
            border: 1px solid #e7bfc8;
            border-radius: 50%;
            background: #fff9fa;
            color: #951b35;
            box-shadow: 0 8px 24px #45112122
        }

        .terminal-fingerprint-visual svg {
            width: 72px;
            height: 72px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2.7;
            stroke-linecap: round;
            stroke-linejoin: round
        }

        .terminal-fingerprint-beam {
            display: none;
            position: absolute;
            z-index: 1;
            left: 15px;
            right: 15px;
            top: 50px;
            height: 3px;
            border-radius: 3px;
            background: #24aeec;
            box-shadow: 0 0 12px 5px #49c6ff88;
            animation: terminal-fingerprint-scan 1.8s ease-in-out infinite alternate
        }

        .terminal-fingerprint-outcome {
            display: none;
            font-size: 58px;
            font-weight: 800;
            line-height: 1
        }

        .scanner-box.pending .terminal-fingerprint-visual,
        .scanner-box.running .terminal-fingerprint-visual {
            border-color: #b8dfff;
            background: #eef8ff;
            color: #156292
        }

        .scanner-box.running .terminal-fingerprint-beam {
            display: block
        }

        .scanner-box.done .terminal-fingerprint-visual {
            border-color: #9cdbb1;
            background: #edfff2;
            color: #146b34
        }

        .scanner-box.failed .terminal-fingerprint-visual,
        .scanner-box.error .terminal-fingerprint-visual {
            border-color: #f0aab8;
            background: #fff1f4;
            color: #9a1833
        }

        .scanner-box.done .terminal-fingerprint-visual svg,
        .scanner-box.failed .terminal-fingerprint-visual svg,
        .scanner-box.error .terminal-fingerprint-visual svg {
            display: none
        }

        .scanner-box.done .terminal-fingerprint-outcome,
        .scanner-box.failed .terminal-fingerprint-outcome,
        .scanner-box.error .terminal-fingerprint-outcome {
            display: block
        }

        @keyframes terminal-fingerprint-scan {
            from { transform: translateY(-29px) }
            to { transform: translateY(29px) }
        }

        @media (prefers-reduced-motion: reduce) {
            .terminal-fingerprint-beam { animation: none }
        }

        .terminal-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px
        }

        .terminal-result {
            min-height: 18px
        }

        .scanner-controls button:disabled {
            opacity: .55;
            cursor: wait
        }

        @media(max-width:500px) {
            .terminal-actions {
                grid-template-columns: 1fr
            }

            .biometric-card {
                padding-left: 22px;
                padding-right: 22px
            }
        }
    </style>
</head>

<body class="biometric-page">
    <main class="biometric-shell">
        <section id="terminal-card" class="biometric-card <?= e($pageMode === 'error' ? 'error' : '') ?>">
            <img class="site-logo seal-small" src="assets/images/ucclogo.jpg" alt="UCC Logo">
            <div class="terminal-brand"><strong>UCCHR</strong><span>Ubay Community College HR System</span></div>
            <div class="terminal-online-row"><span id="terminal-dot" class="terminal-dot"></span><span id="terminal-online-text">Checking ESP32 connection…</span></div>

            <div id="scanner-box" class="scanner-box <?= e($pageMode) ?>">
                <div id="fingerprint-icon" class="terminal-fingerprint-visual" role="img" aria-label="<?= $pageMode === 'error' ? 'Fingerprint scan unavailable' : 'Fingerprint scanner status' ?>">
                    <svg viewBox="0 0 100 100" aria-hidden="true" focusable="false">
                        <path d="M50 17c-19 0-34 15-34 34 0 13-1 23-5 33" />
                        <path d="M50 25c-15 0-26 11-26 26 0 15-1 26-5 35" />
                        <path d="M50 33c-10 0-18 8-18 18 0 17-1 29-5 38" />
                        <path d="M50 41c-6 0-10 4-10 10 0 18-1 31-4 39" />
                        <path d="M50 17c19 0 34 15 34 34 0 13 1 23 5 33" />
                        <path d="M50 25c15 0 26 11 26 26 0 15 1 26 5 35" />
                        <path d="M50 33c10 0 18 8 18 18 0 17 1 29 5 38" />
                        <path d="M50 41c6 0 10 4 10 10 0 18 1 31 4 39" />
                        <path d="M50 51v39" />
                    </svg>
                    <span class="terminal-fingerprint-beam" aria-hidden="true"></span>
                    <span id="fingerprint-outcome" class="terminal-fingerprint-outcome" aria-hidden="true"><?= $pageMode === 'error' ? '!' : '' ?></span>
                </div>
                <em id="scan-status" aria-live="polite"><?= $pageMode === 'pending' ? 'Command pending' : 'Biometric terminal' ?></em>
                <h1 id="scan-heading"><?= $pageMode === 'error' ? 'Connection unavailable' : ($adminAuthorized ? 'Ready to scan' : 'Terminal status') ?></h1>
                <p id="scan-message" class="terminal-result"><?= e($schemaError !== '' ? $schemaError : $pageMessage) ?></p>
            </div>

            <?php if ($adminAuthorized): ?>
                <form id="scan-form" method="post" class="scanner-controls" data-command-id="<?= e($commandId) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <label>Employee
                        <select name="employee_id" required <?= $schemaError !== '' ? 'disabled' : '' ?>>
                            <option value="">Choose enrolled employee</option>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?= (int) $employee['id'] ?>"><?= e($employee['employee_no'] . ' — ' . $employee['first_name'] . ' ' . $employee['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Requested attendance action
                        <select name="event_type" required <?= $schemaError !== '' ? 'disabled' : '' ?>>
                            <option value="IN">Time In</option>
                            <option value="OUT">Time Out</option>
                        </select>
                    </label>
                    <button id="scan-button" class="btn btn-neon" type="submit" <?= $schemaError !== '' ? 'disabled' : '' ?>>Activate scanner &amp; verify twice</button>
                </form>
            <?php else: ?>
                <div class="scanner-controls">
                    <a class="btn btn-neon" href="index.php"><?= ui_icon('shield', 'button-icon') ?>Administrator Login</a>
                </div>
                <p class="terminal-help">ESP32 connection status is available here. Employee names, fingerprint mappings, and scanner controls remain private until an Administrator logs in.</p>
            <?php endif; ?>
        </section>
        <a class="terminal-admin-link" href="index.php">Admin Login</a>
    </main>
    <script>
        (() => {
            const form = document.getElementById('scan-form');
            const button = document.getElementById('scan-button');
            const card = document.getElementById('terminal-card');
            const box = document.getElementById('scanner-box');
            const icon = document.getElementById('fingerprint-icon');
            const outcome = document.getElementById('fingerprint-outcome');
            const status = document.getElementById('scan-status');
            const heading = document.getElementById('scan-heading');
            const message = document.getElementById('scan-message');
            const dot = document.getElementById('terminal-dot');
            const onlineText = document.getElementById('terminal-online-text');
            let commandId = form ? (form.dataset.commandId || '') : '';
            let pollTimer = null;

            function render(state, title, detail) {
                box.className = 'scanner-box ' + state.toLowerCase();
                card.classList.toggle('success', state === 'Done');
                card.classList.toggle('error', state === 'Failed' || state === 'Error');
                outcome.textContent = state === 'Done' ? '✓' : (state === 'Failed' || state === 'Error' ? '!' : '');
                icon.setAttribute('aria-label', state === 'Done' ? 'Fingerprint verified' : (state === 'Failed' || state === 'Error' ? 'Fingerprint scan failed' : (state === 'Running' ? 'Fingerprint scanner active' : 'Fingerprint scanner waiting')));
                status.textContent = state;
                heading.textContent = title;
                message.textContent = detail;
            }

            async function checkDevice() {
                try {
                    const response = await fetch('api/terminal/device_status.php', {
                        cache: 'no-store'
                    });
                    const data = await response.json();
                    dot.className = 'terminal-dot ' + (data.online ? 'online' : 'offline');
                    onlineText.textContent = data.online ? 'ESP32 online' : (data.message || 'ESP32 offline');
                } catch (_) {
                    dot.className = 'terminal-dot offline';
                    onlineText.textContent = 'Website API unavailable';
                }
            }

            async function pollCommand() {
                if (!commandId) return;
                try {
                    const response = await fetch('api/terminal/command_status.php?id=' + encodeURIComponent(commandId), {
                        cache: 'no-store'
                    });
                    const data = await response.json();
                    if (!response.ok || !data.ok) throw new Error(data.error || 'Could not read command status.');
                    const label = data.status === 'Pending' ? 'Waiting for ESP32' : data.status === 'Running' ? 'Scan the same finger twice' : data.employeeName;
                    render(data.status, label, data.message || 'Waiting for biometric result…');
                    if (data.status === 'Done' || data.status === 'Failed') {
                        clearInterval(pollTimer);
                        pollTimer = null;
                        button.disabled = false;
                        commandId = '';
                    }
                } catch (error) {
                    render('Error', 'Connection error', error.message);
                    button.disabled = false;
                }
            }

            if (form) form.addEventListener('submit', async (event) => {
                event.preventDefault();
                button.disabled = true;
                render('Pending', 'Sending command…', 'Contacting the website API.');
                try {
                    const response = await fetch('biometric.php', {
                        method: 'POST',
                        body: new FormData(form),
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    const data = await response.json();
                    if (!response.ok || !data.ok) throw new Error(data.error || 'Could not queue fingerprint scan.');
                    commandId = data.commandId;
                    render('Pending', 'Waiting for ESP32', data.message);
                    await pollCommand();
                    if (!pollTimer && commandId) pollTimer = setInterval(pollCommand, 1400);
                } catch (error) {
                    render('Error', 'Command failed', error.message);
                    button.disabled = false;
                }
            });

            checkDevice();
            setInterval(checkDevice, 4000);
            if (form && commandId) {
                button.disabled = true;
                pollCommand();
                pollTimer = setInterval(pollCommand, 1400);
            }
        })();
    </script>
    <script src="assets/js/pwa.js" defer></script>
</body>

</html>
