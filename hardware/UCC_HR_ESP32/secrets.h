#pragma once

// Copy this file as secrets.h in the same Arduino sketch folder.

#define WIFI_SSID "infinix"
#define WIFI_PASSWORD "20050707"

// The PHP built-in server runs from the UCC_HR_System folder on port 8080.
// Use the computer's LAN IPv4 address here; ESP32 cannot use localhost.
#define API_BASE_URL "http://10.152.156.66:8080"

// Must match the value in database/device_update.sql or settings.device_shared_secret.
#define DEVICE_ID "ucc-esp32-01"
#define DEVICE_SHARED_SECRET "cc0f57a786419ba853c3e5c7902f3ef9011104a0bcd903935ce92b0adc063111"
// Leave empty when API_BASE_URL starts with http://.
// Add your HTTPS root CA only when you deploy the PHP app with a real HTTPS certificate.
#define SERVER_ROOT_CA ""

