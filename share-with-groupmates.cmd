@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0share-with-groupmates.ps1"
if errorlevel 1 (
    echo.
    echo Sharing did not start. Read the error above.
    pause
    exit /b 1
)
