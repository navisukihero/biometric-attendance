# Run once; Windows will ask for administrator permission when needed.
# This allows the existing PHP web server from the local subnet only.
# It does not expose MariaDB or change the network profile/firewall defaults.
[CmdletBinding(SupportsShouldProcess)]
param([switch] $Elevated)

$ErrorActionPreference = 'Stop'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]::new($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    if ($Elevated) {
        throw 'Windows did not grant administrator permission. Right-click enable-mobile-access.cmd and choose Run as administrator.'
    }
    if ($PSCmdlet.ShouldProcess('UCCHR local Wi-Fi access', 'Request Windows administrator permission')) {
        Write-Host 'Windows will ask for permission to allow the UCCHR website on the local network. Choose Yes.' -ForegroundColor Cyan
        try {
            $elevationArguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"{0}"' -f $PSCommandPath), '-Elevated')
            $setup = Start-Process -FilePath "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" `
                -ArgumentList $elevationArguments -Verb RunAs -WindowStyle Hidden -PassThru -Wait
            if ($setup.ExitCode -ne 0) {
                throw "Setup exited with code $($setup.ExitCode). Run this script in an Administrator PowerShell window to see the details."
            }
            Write-Host 'UCCHR local Wi-Fi access is enabled. Start start-server.cmd and use its Phone / tablet address.' -ForegroundColor Green
        } catch {
            throw "Mobile access was not enabled: $($_.Exception.Message)"
        }
    }
    return
}

$phpExecutable = 'C:\xampp\php\php.exe'
$ruleName = 'UCCHR-Web-LAN-8080-v1'
if (-not (Test-Path -LiteralPath $phpExecutable -PathType Leaf)) {
    throw "PHP was not found at $phpExecutable. Check the XAMPP installation first."
}

$existing = Get-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue
if ($existing) {
    $port = $existing | Get-NetFirewallPortFilter
    $address = $existing | Get-NetFirewallAddressFilter
    $application = $existing | Get-NetFirewallApplicationFilter
    if ($existing.Enabled -ne 'True' -or $existing.Action -ne 'Allow' -or
        $existing.Direction -ne 'Inbound' -or $port.Protocol -ne 'TCP' -or
        $port.LocalPort -ne '8080' -or $address.RemoteAddress -ne 'LocalSubnet' -or
        $application.Program -ne $phpExecutable) {
        throw "An existing rule named $ruleName has different settings. Review it in Windows Defender Firewall before continuing."
    }
    Write-Host 'UCCHR local Wi-Fi access is already enabled.' -ForegroundColor Green
} elseif ($PSCmdlet.ShouldProcess('PHP TCP port 8080', 'Allow inbound connections from the local subnet')) {
    New-NetFirewallRule -Name $ruleName -DisplayName 'UCCHR web app - local Wi-Fi only' `
        -Direction Inbound -Action Allow -Enabled True -Profile Any `
        -Protocol TCP -LocalPort 8080 -RemoteAddress LocalSubnet `
        -Program $phpExecutable -EdgeTraversalPolicy Block | Out-Null
    Write-Host 'UCCHR local Wi-Fi access enabled. Start start-server.cmd, then open the phone link shown there.' -ForegroundColor Green
}
Write-Host 'Keep the phone and computer on the same trusted Wi-Fi. No router port forwarding is needed.'
