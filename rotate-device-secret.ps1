[CmdletBinding()]
param(
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [string]$DatabaseHost = '127.0.0.1',
    [ValidateRange(1, 65535)]
    [int]$DatabasePort = 3307,
    [string]$DatabaseName = 'ucchr_system_recovered',
    [string]$DatabaseUser = 'root',
    [string]$DatabasePassword = ''
)

$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot
$secretsPath = Join-Path $projectRoot 'hardware\UCC_HR_ESP32\secrets.h'
$pendingPath = Join-Path $projectRoot 'hardware\UCC_HR_ESP32\.secrets.h.rotation-pending'
$backupPath = Join-Path $projectRoot 'hardware\UCC_HR_ESP32\.secrets.h.rotation-backup'
$secretPattern = '(?m)^#define\s+DEVICE_SHARED_SECRET\s+"([^"\r\n]*)"\s*$'
$utf8NoBom = [System.Text.UTF8Encoding]::new($false)

if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw "PHP was not found at $PhpPath"
}
if (-not (Test-Path -LiteralPath $secretsPath -PathType Leaf)) {
    throw 'Create hardware\UCC_HR_ESP32\secrets.h before rotating the device secret.'
}
if ($DatabaseName -notmatch '^[A-Za-z0-9_]{1,64}$') {
    throw 'DatabaseName contains unsupported characters.'
}

function Get-DeviceSecretFromContent {
    param([Parameter(Mandatory)][string]$Content)

    $matches = [regex]::Matches($Content, $script:secretPattern)
    if ($matches.Count -ne 1) {
        throw 'A secrets file must contain exactly one DEVICE_SHARED_SECRET definition.'
    }
    $secret = $matches[0].Groups[1].Value
    if ($secret.Length -lt 16 -or $secret.Length -gt 128) {
        throw 'DEVICE_SHARED_SECRET must contain between 16 and 128 characters.'
    }
    return $secret
}

function Invoke-DeviceSecretDatabaseStep {
    param(
        [Parameter(Mandatory)][ValidateSet('inspect', 'update')][string]$Action,
        [Parameter(Mandatory)][string]$CurrentFileSecret,
        [Parameter(Mandatory)][string]$CandidateSecret
    )

    $environmentNames = @(
        'UCCHR_ROTATE_DB_HOST',
        'UCCHR_ROTATE_DB_PORT',
        'UCCHR_ROTATE_DB_NAME',
        'UCCHR_ROTATE_DB_USER',
        'UCCHR_ROTATE_DB_PASS',
        'UCCHR_ROTATE_ACTION',
        'UCCHR_ROTATE_CURRENT_SECRET',
        'UCCHR_ROTATE_CANDIDATE_SECRET'
    )
    $previousEnvironment = @{}
    foreach ($name in $environmentNames) {
        $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
    }

    try {
        $env:UCCHR_ROTATE_DB_HOST = $script:DatabaseHost
        $env:UCCHR_ROTATE_DB_PORT = [string]$script:DatabasePort
        $env:UCCHR_ROTATE_DB_NAME = $script:DatabaseName
        $env:UCCHR_ROTATE_DB_USER = $script:DatabaseUser
        $env:UCCHR_ROTATE_DB_PASS = $script:DatabasePassword
        $env:UCCHR_ROTATE_ACTION = $Action
        $env:UCCHR_ROTATE_CURRENT_SECRET = $CurrentFileSecret
        $env:UCCHR_ROTATE_CANDIDATE_SECRET = $CandidateSecret

        # Connect directly here. config/database.php renders a browser-friendly
        # 503 page and intentionally exits without a non-zero CLI status, which
        # is not suitable for a transactional maintenance script.
        $phpCode = @'
<?php
declare(strict_types=1);

function rotation_fail(string $message, int $code): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

try {
    $host = (string) getenv('UCCHR_ROTATE_DB_HOST');
    $port = (string) getenv('UCCHR_ROTATE_DB_PORT');
    $name = (string) getenv('UCCHR_ROTATE_DB_NAME');
    $user = (string) getenv('UCCHR_ROTATE_DB_USER');
    $pass = (string) getenv('UCCHR_ROTATE_DB_PASS');
    $action = (string) getenv('UCCHR_ROTATE_ACTION');
    $current = (string) getenv('UCCHR_ROTATE_CURRENT_SECRET');
    $candidate = (string) getenv('UCCHR_ROTATE_CANDIDATE_SECRET');

    if (!in_array($action, ['inspect', 'update'], true)
        || strlen($current) < 16 || strlen($current) > 128
        || strlen($candidate) < 16 || strlen($candidate) > 128
        || ($action === 'update' && preg_match('/^[a-f0-9]{64}$/', $candidate) !== 1)
    ) {
        rotation_fail('Invalid device-secret rotation input.', 2);
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $identifier = chr(96);
    $pdo->beginTransaction();
    $selectSql = 'SELECT ' . $identifier . 'value' . $identifier
        . ' FROM settings WHERE ' . $identifier . 'key' . $identifier . '=? FOR UPDATE';
    $select = $pdo->prepare($selectSql);
    $select->execute(['device_shared_secret']);
    $databaseSecret = $select->fetchColumn();
    if (!is_string($databaseSecret) || $databaseSecret === '') {
        throw new RuntimeException('Device secret setting is unavailable.');
    }

    if ($action === 'inspect') {
        if (hash_equals($candidate, $databaseSecret)) {
            $pdo->commit();
            echo 'DATABASE_MATCHES_PENDING';
            exit(0);
        }
        if (hash_equals($current, $databaseSecret)) {
            $pdo->commit();
            echo 'DATABASE_MATCHES_CURRENT';
            exit(0);
        }
        throw new RuntimeException('Database and firmware secrets do not match either safe state.');
    }

    if (!hash_equals($current, $databaseSecret)) {
        throw new RuntimeException('Database and firmware secrets are already out of sync.');
    }
    $updateSql = 'UPDATE settings SET ' . $identifier . 'value' . $identifier
        . '=? WHERE ' . $identifier . 'key' . $identifier . '=?';
    $update = $pdo->prepare($updateSql);
    $update->execute([$candidate, 'device_shared_secret']);
    $select->execute(['device_shared_secret']);
    $stored = $select->fetchColumn();
    if (!is_string($stored) || !hash_equals($candidate, $stored)) {
        throw new RuntimeException('Database verification failed.');
    }
    $pdo->commit();
    echo 'DATABASE_UPDATED';
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    rotation_fail('Device-secret database operation failed; no secret was printed.', 10);
}
'@

        Push-Location -LiteralPath $script:projectRoot
        try {
            $result = $phpCode | & $script:PhpPath -d display_errors=0 -d display_startup_errors=0
            $exitCode = $LASTEXITCODE
        } finally {
            Pop-Location
        }
        if ($exitCode -ne 0) {
            throw "Database $Action failed with exit code $exitCode. The active firmware file was not changed."
        }

        return (($result | Out-String).Trim())
    } finally {
        foreach ($name in $environmentNames) {
            $previousValue = $previousEnvironment[$name]
            if ($null -eq $previousValue) {
                Remove-Item -LiteralPath "Env:$name" -ErrorAction SilentlyContinue
            } else {
                [Environment]::SetEnvironmentVariable($name, [string]$previousValue, 'Process')
            }
        }
    }
}

function Install-StagedSecrets {
    if (Test-Path -LiteralPath $script:backupPath) {
        throw 'A device-secret recovery backup must be reconciled before installation.'
    }
    [System.IO.File]::Replace($script:pendingPath, $script:secretsPath, $script:backupPath)
    if (Test-Path -LiteralPath $script:backupPath) {
        Remove-Item -LiteralPath $script:backupPath
    }
}

$currentContent = [System.IO.File]::ReadAllText($secretsPath)
$currentSecret = Get-DeviceSecretFromContent -Content $currentContent

# File.Replace keeps the previous active file until cleanup. A power loss after
# replacement can leave only that backup behind. Verify which copy the database
# uses before deleting or restoring anything.
if (Test-Path -LiteralPath $backupPath -PathType Leaf) {
    $backupContent = [System.IO.File]::ReadAllText($backupPath)
    $backupSecret = Get-DeviceSecretFromContent -Content $backupContent
    $backupInspectArguments = @{
        Action = 'inspect'
        CurrentFileSecret = $currentSecret
        CandidateSecret = $backupSecret
    }
    $backupState = Invoke-DeviceSecretDatabaseStep @backupInspectArguments
    if ($backupState -eq 'DATABASE_MATCHES_CURRENT') {
        Remove-Item -LiteralPath $backupPath
    } elseif ($backupState -eq 'DATABASE_MATCHES_PENDING') {
        $discardPath = $backupPath + '.discard'
        if (Test-Path -LiteralPath $discardPath) {
            throw 'An unexpected device-secret discard file requires manual review.'
        }
        [System.IO.File]::Replace($backupPath, $secretsPath, $discardPath)
        Remove-Item -LiteralPath $discardPath
        $currentContent = [System.IO.File]::ReadAllText($secretsPath)
        $currentSecret = Get-DeviceSecretFromContent -Content $currentContent
    } else {
        throw 'Could not determine a safe recovery state for the device-secret backup.'
    }
}

# A fixed pending file makes an interrupted rotation recoverable. If a previous
# run stopped after the database commit but before the atomic file replacement,
# this invocation finishes that replacement. If it stopped before the database
# commit, the stale staged file is safely discarded.
if (Test-Path -LiteralPath $pendingPath -PathType Leaf) {
    $pendingContent = [System.IO.File]::ReadAllText($pendingPath)
    $pendingSecret = Get-DeviceSecretFromContent -Content $pendingContent
    $inspectArguments = @{
        Action = 'inspect'
        CurrentFileSecret = $currentSecret
        CandidateSecret = $pendingSecret
    }
    $recoveryState = Invoke-DeviceSecretDatabaseStep @inspectArguments

    if ($recoveryState -eq 'DATABASE_MATCHES_PENDING') {
        Install-StagedSecrets
        Write-Host 'Completed an interrupted device-secret rotation safely.' -ForegroundColor Green
        Write-Host 'Recompile and upload UCC_HR_ESP32.ino before reconnecting the terminal.' -ForegroundColor Yellow
        exit 0
    }
    if ($recoveryState -ne 'DATABASE_MATCHES_CURRENT') {
        throw 'Could not determine a safe recovery state for the pending device secret.'
    }
    Remove-Item -LiteralPath $pendingPath
}

$bytes = [byte[]]::new(32)
$random = [System.Security.Cryptography.RandomNumberGenerator]::Create()
try {
    $random.GetBytes($bytes)
} finally {
    $random.Dispose()
}
$newSecret = -join ($bytes | ForEach-Object { $_.ToString('x2') })
$updatedContent = [regex]::Replace(
    $currentContent,
    $secretPattern,
    '#define DEVICE_SHARED_SECRET "' + $newSecret + '"'
)

try {
    [System.IO.File]::WriteAllText($pendingPath, $updatedContent, $utf8NoBom)
    $updateArguments = @{
        Action = 'update'
        CurrentFileSecret = $currentSecret
        CandidateSecret = $newSecret
    }
    $databaseResult = Invoke-DeviceSecretDatabaseStep @updateArguments
    if ($databaseResult -ne 'DATABASE_UPDATED') {
        throw 'The database did not return the expected rotation confirmation.'
    }

    # Same-volume File.Replace is atomic. The staged file intentionally remains
    # on any failure so the next invocation can reconcile the committed DB value.
    Install-StagedSecrets
} catch {
    if (Test-Path -LiteralPath $pendingPath -PathType Leaf) {
        try {
            $inspectArguments = @{
                Action = 'inspect'
                CurrentFileSecret = $currentSecret
                CandidateSecret = $newSecret
            }
            $state = Invoke-DeviceSecretDatabaseStep @inspectArguments
            if ($state -eq 'DATABASE_MATCHES_CURRENT') {
                Remove-Item -LiteralPath $pendingPath
            }
        } catch {
            # Keep the pending file: it is the recovery source if the database
            # committed before becoming unavailable. No secret is printed.
        }
    }
    throw
} finally {
    $newSecret = $null
    $currentSecret = $null
    $bytes = $null
}

Write-Host 'Device secret rotated in MySQL and secrets.h.' -ForegroundColor Green
Write-Host 'Recompile and upload UCC_HR_ESP32.ino before reconnecting the terminal.' -ForegroundColor Yellow
