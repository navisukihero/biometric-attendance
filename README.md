# UCCHR — Fingerprint Biometric Attendance & Payroll System

A complete PHP 8 + MySQL/MariaDB implementation of the supplied Ubay Community College HR interface. It includes:

- Admin authentication and account security
- Responsive dashboard and navigation
- Employee search, add, edit, archive, and fingerprint enrollment
- Simulated biometric time-in/time-out terminal (ready for vendor SDK integration)
- Attendance logs and weekly schedules
- Payroll calculation, pay slips, deductions, and payroll history
- Attendance/payroll CSV exports and print-ready PDF reports
- Reports dashboard, profile activity history, and system settings

## Requirements

- PHP 8.1+ with PDO MySQL
- MySQL 8+ or MariaDB 10.4+
- Apache, Nginx, or PHP's built-in development server

## Quick setup with XAMPP

1. Import `database/ucchr.sql` in phpMyAdmin, or run:

cd "C:\Users\Jules Ivan\Documents\Codex\2026-08-21\cr\outputs\UCC_HR_System"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\start-fixed-system.ps1"
.\start-server.cmd

cd "C:\Users\Jules Ivan\Documents\Codex\2026-08-21\cr\outputs\UCC_HR_System"
.\start-server.cmd

Demo credentials:

- Username: `admin`
- Password: `Zafraivan123`

## Database configuration

Defaults are in `config/database.php`. You can also set:

- `UCCHR_DB_HOST`
- `UCCHR_DB_PORT`
- `UCCHR_DB_NAME`
- `UCCHR_DB_USER`
- `UCCHR_DB_PASS`

## Development server

With MySQL running and the schema imported:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8080
```

Then open `http://127.0.0.1:8080/`.

## Fingerprint hardware integration

The included biometric terminal provides a complete simulated scan workflow. For deployment, replace the employee selector submission in `biometric.php` with the callback or identifier from your fingerprint device's vendor SDK. Keep the same attendance insert/update logic so time-in and time-out records remain compatible with the dashboard and payroll reports.

