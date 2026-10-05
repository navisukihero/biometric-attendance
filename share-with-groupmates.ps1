param(
    [string] $AllowedEmails = '',
    [switch] $CheckOnly
)

$ErrorActionPreference = 'Stop'
$projectDirectory = $PSScriptRoot
$workspaceDirectory = Split-Path -Parent (Split-Path -Parent $projectDirectory)
$cloudflared = Join-Path $workspaceDirectory '_tools\cloudflared-windows-amd64.exe'
$localBase = 'http://127.0.0.1:8080'

if (-not (Test-Path -LiteralPath $cloudflared -PathType Leaf)) {
    throw "Cloudflare Tunnel is missing: $cloudflared"
}

$signature = Get-AuthenticodeSignature -LiteralPath $cloudflared
if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'Cloudflare') {
    throw 'Cloudflare Tunnel executable did not pass the Windows signature check.'
}

try {
    $login = Invoke-WebRequest -UseBasicParsing -Uri "$localBase/index.php" -TimeoutSec 10
    $launch = Invoke-WebRequest -UseBasicParsing -Uri "$localBase/launch.html" -TimeoutSec 10
} catch {
    throw "UCCHR is not responding on port 8080. Run start-server.cmd first and leave that window open. $($_.Exception.Message)"
}
if ($login.StatusCode -ne 200 -or $login.Content -notmatch 'UCCHR' -or
    $launch.StatusCode -ne 200 -or $launch.Content -notmatch '<title>Open UCCHR</title>') {
    throw 'The site on port 8080 does not look like the expected UCCHR installation.'
}

if ($CheckOnly) {
    Write-Host 'UCCHR, Cloudflare Tunnel, and the local port 8080 are ready. No public tunnel was opened.' -ForegroundColor Green
    exit 0
}

if ([string]::IsNullOrWhiteSpace($AllowedEmails)) {
    $AllowedEmails = Read-Host 'Enter allowed groupmate email addresses (comma-separated)'
}
$emails = @($AllowedEmails -split '[,;\s]+' | ForEach-Object { $_.Trim().ToLowerInvariant() } |
    Where-Object { $_ -ne '' } | Select-Object -Unique)
if ($emails.Count -eq 0) {
    throw 'No email addresses entered. The HR system will not be shared without an access gate.'
}
foreach ($email in $emails) {
    if ($email -notmatch '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$' -or $email.Length -gt 254) {
        throw "Invalid email address: $email. Use individual email addresses, not wildcard domains."
    }
}

Write-Host ''
Write-Host "Starting protected Cloudflare Quick Tunnel for $($emails.Count) allowed email address(es)." -ForegroundColor Cyan
Write-Host 'Keep this window, the UCCHR server window, MariaDB, and this PC running.'
Write-Host 'Copy the https://...trycloudflare.com URL below and add /launch.html for your groupmates.'
Write-Host 'Press Ctrl+C here to stop sharing. The temporary URL changes next time.' -ForegroundColor Yellow
Write-Host ''

$tunnelArguments = @('tunnel', '--no-autoupdate', '--url', $localBase)
foreach ($email in $emails) {
    $tunnelArguments += @('--allowed-mail', $email)
}
& $cloudflared @tunnelArguments
if ($LASTEXITCODE -ne 0) {
    throw "Cloudflare Tunnel stopped with exit code $LASTEXITCODE. Review the error above."
}
