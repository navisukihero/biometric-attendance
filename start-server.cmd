@echo off
setlocal
cd /d "%~dp0"

where pwsh.exe >nul 2>&1
if not errorlevel 1 (
    pwsh.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-fixed-system.ps1"
) else (
    powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0start-fixed-system.ps1"
)

if errorlevel 1 (
    echo.
    echo The UCC HR System did not start. Read the error shown above.
    pause
    exit /b 1
)
