#pragma once

// Copy this file as secrets.h in the same Arduino sketch folder.

// ESP32 uses 2.4 GHz Wi-Fi. Names/passwords are case-sensitive.
#define WIFI_SSID "YOUR_WIFI_NAME"
#define WIFI_PASSWORD "YOUR_WIFI_PASSWORD"

// Start PHP inside the UCC_HR_System folder with:
// C:\xampp\php\php.exe -S 0.0.0.0:8080 router.php
// Use the computer's LAN IPv4 address here; ESP32 cannot use localhost.
// The browser on the computer may still open http://localhost:8080/.
#define API_BASE_URL "http://YOUR_COMPUTER_IPV4:8080"

// Must match the value in database/device_update.sql or settings.device_shared_secret.
#define DEVICE_ID "ucc-esp32-01"
#define DEVICE_SHARED_SECRET "REPLACE_WITH_A_UNIQUE_64_CHARACTER_HEX_SECRET"

// Leave empty only when API_BASE_URL starts with http://.
// HTTPS is deliberately refused unless this contains the server/root CA in
// PEM format. The firmware never falls back to insecure certificate handling.
#define SERVER_ROOT_CA ""
