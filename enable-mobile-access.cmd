@echo off
setlocal
cd /d "%~dp0"
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "%~dp0enable-mobile-access.ps1"
if errorlevel 1 (
    echo.
    echo Mobile access was not enabled. Read the message above.
    pause
    exit /b 1
)
echo.
echo Now run start-server.cmd and open its Phone / tablet address on the same Wi-Fi.
pause
