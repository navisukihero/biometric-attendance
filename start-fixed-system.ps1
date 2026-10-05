param([switch] $NoBrowser)

$ErrorActionPreference = 'Stop'
$projectDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$phpExecutable = 'C:\xampp\php\php.exe'
$mysqlExecutable = 'C:\xampp\mysql\bin\mysqld.exe'
$mysqlClient = 'C:\xampp\mysql\bin\mysql.exe'
$databaseName = 'ucchr_system_recovered'

# The recovered project database has its own InnoDB system tablespace. Keep it
# isolated from XAMPP's normal MySQL instance, which commonly uses port 3306.
$databasePort = 3307
$runtimeData = Join-Path $projectDirectory 'database\runtime_mysql_data'
$runtimeTemp = Join-Path $runtimeData 'tmp'
$runtimeLog = Join-Path $runtimeData 'mysql-3307-error.log'

function Get-LocalTcpListenerAddresses([int] $Port) {
    try {
        return @(
            [System.Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners() |
                Where-Object { $_.Port -eq $Port } |
                ForEach-Object { $_.Address }
        )
    } catch {
        return @()
    }
}

function Test-LocalPort([int] $Port) {
    # Check every local bind address first. A listener bound directly to a LAN
    # address is still a conflict for the wildcard PHP listener even though a
    # connection to 127.0.0.1 would not find it.
    if (@(Get-LocalTcpListenerAddresses $Port).Count -gt 0) { return $true }

    # Retain a connection fallback for hosts where listener enumeration is not
    # available. Check both loopback families because `php -S localhost:8080`
    # commonly binds only to ::1 on Windows.
    foreach ($address in @('127.0.0.1', '::1')) {
        $client = [System.Net.Sockets.TcpClient]::new()
        try {
            $connection = $client.ConnectAsync($address, $Port)
            if ($connection.Wait(800) -and $client.Connected) { return $true }
        } catch {
            # Try the other loopback family before declaring the port offline.
        } finally {
            $client.Dispose()
        }
    }
    return $false
}

function Test-LanIPv4PortBinding([int] $Port) {
    foreach ($address in @(Get-LocalTcpListenerAddresses $Port)) {
        if ($address.AddressFamily -ne [System.Net.Sockets.AddressFamily]::InterNetwork) { continue }
        if (-not [System.Net.IPAddress]::IsLoopback($address)) { return $true }
    }
    return $false
}

function Get-LanIPv4Address {
    try {
        $configuration = Get-NetIPConfiguration -ErrorAction Stop |
            Where-Object {
                $null -ne $_.IPv4DefaultGateway -and
                $null -ne $_.IPv4Address -and
                $_.NetAdapter.Status -eq 'Up'
            } |
            Select-Object -First 1
        if ($null -ne $configuration) {
            $address = @($configuration.IPv4Address)[0].IPAddress
            if ($address -and $address -notmatch '^(127\.|169\.254\.)') {
                return [string] $address
            }
        }
    } catch {
        # Fall through to the framework-only adapter scan below.
    }

    foreach ($adapter in [System.Net.NetworkInformation.NetworkInterface]::GetAllNetworkInterfaces()) {
        if ($adapter.OperationalStatus -ne [System.Net.NetworkInformation.OperationalStatus]::Up) { continue }
        if ($adapter.NetworkInterfaceType -in @(
            [System.Net.NetworkInformation.NetworkInterfaceType]::Loopback,
            [System.Net.NetworkInformation.NetworkInterfaceType]::Tunnel
        )) { continue }
        foreach ($unicast in $adapter.GetIPProperties().UnicastAddresses) {
            if ($unicast.Address.AddressFamily -ne [System.Net.Sockets.AddressFamily]::InterNetwork) { continue }
            $address = $unicast.Address.ToString()
            if ($address -notmatch '^(127\.|169\.254\.)') { return $address }
        }
    }
    return $null
}

function Test-UccHrWebsite {
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8080/' -TimeoutSec 5
        return $response.StatusCode -eq 200 -and $response.Content -match '<title>[^<]*UCCHR[^<]*</title>'
    } catch {
        return $false
    }
}

function Get-MariaDbDataDirectory([int] $Port) {
    $result = & $mysqlClient --connect-timeout=5 --host=127.0.0.1 --port=$Port --user=root `
        --batch --skip-column-names --execute='SELECT @@datadir;' 2>$null
    if ($LASTEXITCODE -ne 0) { return $null }
    $value = (($result | Out-String).Trim())
    return $(if ($value -eq '') { $null } else { $value })
}

function Get-MariaDbBindAddress([int] $Port) {
    $result = & $mysqlClient --connect-timeout=5 --host=127.0.0.1 --port=$Port --user=root `
        --batch --skip-column-names --execute='SELECT @@bind_address;' 2>$null
    if ($LASTEXITCODE -ne 0) { return $null }
    $value = (($result | Out-String).Trim())
    return $(if ($value -eq '') { $null } else { $value })
}

foreach ($requiredPath in @(
    $phpExecutable,
    $mysqlExecutable,
    $mysqlClient,
    (Join-Path $projectDirectory 'router.php'),
    (Join-Path $runtimeData 'ibdata1'),
    (Join-Path $runtimeData 'mysql'),
    (Join-Path $runtimeData $databaseName)
)) {
    if (-not (Test-Path -LiteralPath $requiredPath)) {
        throw "Required runtime file is missing: $requiredPath"
    }
}

New-Item -ItemType Directory -Force -Path $runtimeTemp | Out-Null

if (-not (Test-LocalPort $databasePort)) {
    $runtimeForward = $runtimeData.Replace('\', '/')
    $tempForward = $runtimeTemp.Replace('\', '/')
    $mysqlArguments = @(
        '--defaults-file=C:/xampp/mysql/bin/my.ini',
        "--datadir=$runtimeForward",
        # The PHP API must listen on the LAN, but the recovered database must
        # never be exposed to other Wi-Fi clients. Only PHP on this PC needs
        # direct MariaDB access.
        '--bind-address=127.0.0.1',
        "--port=$databasePort",
        "--pid-file=$runtimeForward/mysql-3307.pid",
        "--log-error=$runtimeForward/mysql-3307-error.log",
        "--innodb-data-home-dir=$runtimeForward",
        "--innodb-log-group-home-dir=$runtimeForward",
        "--tmpdir=$tempForward",
        '--standalone',
        # MariaDB 10.4 can exit immediately after socket creation when launched
        # through a hidden ProcessStartInfo without console mode. `--console`
        # keeps the recovered instance attached to its real server process; the
        # window remains hidden because CreateNoWindow is enabled below.
        '--console'
    )

    # ArgumentList preserves each path as one argument even when the project
    # directory contains spaces. The previous Start-Process call joined the
    # arguments and caused MariaDB to silently use XAMPP's default data folder.
    $startInfo = [System.Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = $mysqlExecutable
    $startInfo.WorkingDirectory = 'C:\xampp'
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    if ($startInfo.PSObject.Properties.Name -contains 'ArgumentList') {
        foreach ($argument in $mysqlArguments) {
            [void] $startInfo.ArgumentList.Add($argument)
        }
    } else {
        $startInfo.Arguments = ($mysqlArguments | ForEach-Object {
            if ($_ -match '\s') { '"' + ($_ -replace '"', '\"') + '"' } else { $_ }
        }) -join ' '
    }
    [void] [System.Diagnostics.Process]::Start($startInfo)

    $mysqlReady = $false
    for ($attempt = 1; $attempt -le 30; $attempt++) {
        Start-Sleep -Milliseconds 500
        if (Test-LocalPort $databasePort) {
            $mysqlReady = $true
            break
        }
    }
    if (-not $mysqlReady) {
        throw "Recovered MariaDB did not start on port $databasePort. Check $runtimeLog"
    }
}

$actualDataDirectory = Get-MariaDbDataDirectory $databasePort
if ($null -eq $actualDataDirectory) {
    throw "Port $databasePort is open, but it is not accepting the recovered database connection."
}
$expectedDataDirectory = [System.IO.Path]::GetFullPath($runtimeData).TrimEnd('\')
$resolvedDataDirectory = [System.IO.Path]::GetFullPath($actualDataDirectory).TrimEnd('\')
if ($resolvedDataDirectory -ne $expectedDataDirectory) {
    throw "Port $databasePort belongs to a different MariaDB data directory: $resolvedDataDirectory"
}
$actualBindAddress = Get-MariaDbBindAddress $databasePort
if ($null -eq $actualBindAddress) {
    throw "Port $databasePort is open, but its MariaDB bind address could not be verified."
}
$unsafeBindAddresses = @(
    $actualBindAddress -split ',' |
        ForEach-Object { $_.Trim().ToLowerInvariant() } |
        Where-Object { $_ -notin @('127.0.0.1', '::1', 'localhost') }
)
if ($unsafeBindAddresses.Count -gt 0) {
    throw "Recovered MariaDB is using the project data directory but is exposed through bind address '$actualBindAddress'. Stop that instance safely, then relaunch it so it binds only to 127.0.0.1."
}

$healthQuery = @'
SELECT COUNT(*) FROM schema_migrations;
SELECT COUNT(*) FROM employees;
SELECT COUNT(*) FROM users;
SELECT COUNT(*) FROM admin_auth_throttles;
SELECT COUNT(*) FROM settings;
SELECT COUNT(*) FROM attendance;
SELECT COUNT(*) FROM fingerprint_registrations;
SELECT COUNT(*) FROM work_schedules;
SELECT COUNT(*) FROM payroll_runs;
SELECT session_index, punch_sequence, punch_request_id FROM attendance_logs LIMIT 0;
SELECT employment_type_snapshot, legacy_single_pair FROM attendance LIMIT 0;
SELECT employment_type, pay_type, basic_rate FROM employee_compensation_history LIMIT 0;
'@
$healthOutput = & $mysqlClient --connect-timeout=5 --host=127.0.0.1 --port=$databasePort `
    --user=root --database=$databaseName --batch --skip-column-names --execute=$healthQuery 2>&1
if ($LASTEXITCODE -ne 0) {
    throw "Recovered MariaDB is running from the correct directory, but a required application table or migration is unavailable. Apply the ordered migrations in DEPLOYMENT.md, including database/compensation_history_integrity_update.sql, then retry. Details: $healthOutput"
}

$compensationHealthQuery = @'
SELECT CONCAT(
    (SELECT COUNT(*) FROM schema_migrations WHERE migration_key='2026-09-19-compensation-history-integrity-v1'), '|',
    (SELECT COUNT(*) FROM employees
     WHERE employment_type NOT IN ('Full-Time','Part-Time')
        OR pay_type NOT IN ('Daily','Hourly')
        OR basic_rate<=0), '|',
    (SELECT COUNT(*) FROM employee_compensation_history
     WHERE employment_type NOT IN ('Full-Time','Part-Time')
        OR pay_type NOT IN ('Daily','Hourly')
        OR basic_rate<=0)
);
'@
$compensationHealthOutput = & $mysqlClient --connect-timeout=5 --host=127.0.0.1 --port=$databasePort `
    --user=root --database=$databaseName --batch --skip-column-names --execute=$compensationHealthQuery 2>&1
$compensationHealthValue = (($compensationHealthOutput | Out-String).Trim())
if ($LASTEXITCODE -ne 0 -or $compensationHealthValue -ne '1|0|0') {
    throw "Compensation setup is incomplete (expected integrity marker|invalid employees|invalid history = 1|0|0; received '$compensationHealthValue'). Back up the database, apply database/compensation_history_integrity_update.sql, correct any specifically reported employee setup, then retry."
}

# Ensure the PHP child uses this verified database instance even when the
# parent shell contains an old environment override.
$env:UCCHR_DB_HOST = '127.0.0.1'
$env:UCCHR_DB_PORT = [string] $databasePort
$env:UCCHR_DB_NAME = $databaseName
$env:UCCHR_DB_USER = 'root'
$env:UCCHR_DB_PASS = ''

Set-Location -LiteralPath $projectDirectory
$lanAddress = Get-LanIPv4Address
$esp32ApiAddress = if ($lanAddress) { "http://${lanAddress}:8080" } else { 'http://<THIS-PC-WIFI-IP>:8080' }
$secretsFile = Join-Path $projectDirectory 'hardware\UCC_HR_ESP32\secrets.h'
if ($lanAddress -and (Test-Path -LiteralPath $secretsFile)) {
    $secretsSource = Get-Content -LiteralPath $secretsFile -Raw
    $apiMatch = [regex]::Match($secretsSource, '(?m)^\s*#define\s+API_BASE_URL\s+"([^"]+)"')
    if ($apiMatch.Success -and $apiMatch.Groups[1].Value.TrimEnd('/') -ne $esp32ApiAddress) {
        Write-Warning "ESP32 API_BASE_URL is $($apiMatch.Groups[1].Value), but this PC is $esp32ApiAddress. Update secrets.h and upload the sketch again."
    }
}

if (Test-LocalPort 8080) {
    if (Test-UccHrWebsite) {
        if (-not (Test-LanIPv4PortBinding 8080)) {
            throw 'A loopback-only UCC HR server is already using port 8080. Close the old PHP terminal, then run this launcher again. Do not use "php.exe -S localhost:8080" or "php.exe -S 127.0.0.1:8080" because the ESP32 cannot reach either listener.'
        }
        Write-Host 'The UCC HR website is already running at http://localhost:8080/' -ForegroundColor Green
        Write-Host "ESP32 API: $esp32ApiAddress/" -ForegroundColor Cyan
        Write-Host "Phone / tablet: $esp32ApiAddress/launch.html" -ForegroundColor Cyan
        Write-Host 'Use the phone address on the same Wi-Fi. localhost works only on this computer.'
        if (-not $NoBrowser) { Start-Process 'http://localhost:8080/' }
        exit 0
    }
    try {
        $localOnlyResponse = Invoke-WebRequest -UseBasicParsing -Uri 'http://localhost:8080/' -TimeoutSec 5
        if ($localOnlyResponse.StatusCode -eq 200 -and $localOnlyResponse.Content -match '<title>[^<]*UCCHR[^<]*</title>') {
            throw 'A localhost-only UCC HR server is already using port 8080. Close the old PHP terminal, then run this launcher again. Do not use "php.exe -S localhost:8080" because the ESP32 cannot reach it.'
        }
    } catch {
        if ($_.Exception.Message -match 'localhost-only UCC HR server') { throw }
    }
    throw 'Port 8080 is occupied by another or incorrectly started server. Close that server, then run this launcher again.'
}

Write-Host "Recovered MariaDB: ONLINE (127.0.0.1:$databasePort)" -ForegroundColor Green
Write-Host 'Website:           http://localhost:8080/' -ForegroundColor Cyan
Write-Host 'Employee portal:   http://localhost:8080/employee-login.php' -ForegroundColor Cyan
Write-Host "ESP32 API:         $esp32ApiAddress/" -ForegroundColor Cyan
Write-Host "Phone / tablet:    $esp32ApiAddress/launch.html" -ForegroundColor Cyan
Write-Host 'Use the phone address on the same Wi-Fi. localhost works only on this computer.'
Write-Host 'Keep this PowerShell window open. Press Ctrl+C to stop the PHP website.' -ForegroundColor Yellow
if (-not $NoBrowser) { Start-Process 'http://localhost:8080/' }
& $phpExecutable -d display_errors=0 -d display_startup_errors=0 -d log_errors=1 -d expose_php=0 -S 0.0.0.0:8080 router.php
