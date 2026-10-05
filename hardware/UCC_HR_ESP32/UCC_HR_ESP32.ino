#include <WiFi.h>
#include <WiFiClient.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <Adafruit_Fingerprint.h>
#include <U8g2lib.h>
#include <Wire.h>
#include <time.h>
#include <mbedtls/md.h>
#include <esp_system.h>
#include "secrets.h"

constexpr char FIRMWARE_VERSION[] = "2026.09.28-fingerprint-capture-v13";
constexpr char DEVICE_CAPABILITIES[] =
  "enroll-btn1-v1,enroll-five-template-v1,as608-quality-v1,"
  "attendance-auto-state-v1,attendance-duplicate-safe-v1,"
  "attendance-session-state-v1,attendance-punch-id-v1,attendance-capture-time-v1,"
  "https-ca-required-v1,command-result-retry-v1";

// ESP32 DevKit V1 pin map. These values match hardware/WIRING.md.
constexpr uint8_t FP_RX = 33;       // ESP32 RX2 <- AS608 TX
constexpr uint8_t FP_TX = 32;       // ESP32 TX2 -> AS608 RX
constexpr uint8_t OLED_SDA = 16;
constexpr uint8_t OLED_SCL = 17;
constexpr uint8_t BUTTON_1 = 22;    // Idle: IN mode; enrollment waiting: approve
constexpr uint8_t BUTTON_2 = 21;    // Idle: OUT mode; enrollment waiting: cancel
constexpr uint8_t RGB_RED = 27;
constexpr uint8_t RGB_GREEN = 14;
constexpr uint8_t RGB_BLUE = 26;
constexpr uint8_t BUZZER = 23;

constexpr unsigned long COMMAND_POLL_INTERVAL_MS = 2000;
constexpr unsigned long HEARTBEAT_INTERVAL_MS = 15000;
constexpr unsigned long WIFI_CONNECT_TIMEOUT_MS = 15000;
constexpr unsigned long WIFI_RETRY_INITIAL_MS = 5000;
constexpr unsigned long WIFI_RETRY_MAX_MS = 60000;
constexpr unsigned long API_RETRY_INTERVAL_MS = 10000;
constexpr unsigned long CLOCK_RETRY_INTERVAL_MS = 30000;
constexpr unsigned long STANDBY_CLOCK_REFRESH_MS = 250;
constexpr unsigned long SENSOR_RETRY_INTERVAL_MS = 15000;
constexpr unsigned long FINGER_IDLE_POLL_INTERVAL_MS = 90;
constexpr unsigned long FINGER_SCAN_TIMEOUT_MS = 20000;
constexpr unsigned long FINGER_REMOVE_TIMEOUT_MS = 10000;
constexpr unsigned long FINGER_SENSOR_POLL_INTERVAL_MS = 50;
constexpr unsigned long PROMPT_TRANSITION_MS = 350;
constexpr unsigned long RESULT_DISPLAY_MS = 1000;
// Keep a failed LAN request below the ESP32 task-watchdog window. The terminal
// will retry later instead of sitting inside a long blocking socket call.
constexpr unsigned long HTTP_CONNECT_TIMEOUT_MS = 1500;
constexpr unsigned long HTTP_RESPONSE_TIMEOUT_MS = 2500;
constexpr int HTTP_MAX_RESPONSE_BYTES = 4096;
constexpr unsigned long COMMAND_RESULT_RETRY_INTERVAL_MS = 10000;
constexpr uint8_t ATTENDANCE_POST_ATTEMPTS = 3;
// Internal/preflight results deliberately stay outside HTTPClient's -1..-11
// error range so a socket timeout can never be mislabeled as a clock error.
constexpr int REQUEST_STATUS_WIFI_OFFLINE = -100;
constexpr int REQUEST_STATUS_CLOCK_NOT_READY = -101;
constexpr int REQUEST_STATUS_BEGIN_FAILED = -102;
constexpr int REQUEST_STATUS_RESPONSE_TOO_LARGE = -103;
constexpr int REQUEST_STATUS_SIGNING_FAILED = -104;
constexpr int REQUEST_STATUS_INVALID_API_URL = -105;
constexpr uint16_t AS608_MAX_SLOT = 127;
// The AS608 security level makes the biometric decision. A second fixed score
// floor rejected valid angled matches on some modules; keep this configurable
// but disabled by default. Attendance still requires two scans of one profile.
constexpr uint16_t MIN_MATCH_CONFIDENCE = 0;
// Some AS608-compatible firmware returns 0x17 when a search is attempted
// before it has observed a complete lift since the preceding capture/search.
// Adafruit_Fingerprint 2.1.4 does not name this module-specific response.
constexpr uint8_t AS608_RESIDUAL_FINGER = 0x17;
constexpr uint8_t MAX_RESIDUAL_FINGER_RETRIES = 3;
constexpr uint8_t STORED_MODEL_LOAD_RETRIES = 3;
constexpr uint8_t ENROLLMENT_POSITION_COUNT = 5;
constexpr uint8_t ENROLLMENT_POSITION_ATTEMPTS = 3;
constexpr uint8_t ENROLLMENT_DUPLICATE_SEARCH_ATTEMPTS = 3;
constexpr unsigned long ENROLLMENT_RELEASE_TIMEOUT_MS = 30000;
constexpr unsigned long ENROLLMENT_RELEASE_STABLE_MS = 300;
constexpr unsigned long ENROLLMENT_RELEASE_REMINDER_MS = 2500;
constexpr uint8_t ENROLLMENT_RELEASE_STABLE_SAMPLES = 4;

const char* const ENROLLMENT_POSITION_NAMES[ENROLLMENT_POSITION_COUNT] = {
  "CENTER", "LEFT", "RIGHT", "UPPER", "LOWER"
};
const char* const ENROLLMENT_POSITION_DETAILS[ENROLLMENT_POSITION_COUNT] = {
  "Place thumb flat",
  "Tilt thumb slightly left",
  "Tilt thumb slightly right",
  "Tilt thumb slightly up",
  "Tilt thumb slightly down"
};

HardwareSerial fingerprintSerial(2);
Adafruit_Fingerprint finger(&fingerprintSerial);
U8G2_SH1106_128X64_NONAME_F_HW_I2C oled(
  U8G2_R0,
  U8X8_PIN_NONE,
  OLED_SCL,
  OLED_SDA
);

// HTTPClient stores a pointer to the network client passed to begin(). These
// clients must therefore outlive every HTTPClient instance. Keeping them at
// program scope also avoids repeatedly constructing an unused TLS client on
// every two-second command poll.
WiFiClient apiPlainClient;
WiFiClientSecure apiSecureClient;
String configuredApiBaseUrl;
bool apiConfigurationValid = false;

// BTN1/BTN2 keep the familiar IN/OUT mode display, but every autonomous scan
// is submitted as AUTO. The locked API session state and assigned schedule are
// authoritative, so selecting the wrong button cannot create a wrong punch.
String eventType = "IN";
bool fingerprintReady = false;
bool apiAuthenticated = false;
bool lastButton1Pressed = false;
bool lastButton2Pressed = false;
bool biometricOperationActive = false;
// A successful/failed recognition attempt cannot start again until several
// consecutive NOFINGER readings prove that the glass is clear.
bool recognitionFingerReleaseRequired = false;
uint8_t recognitionReleaseSamples = 0;
uint32_t fingerprintBaud = 0;
uint32_t pollCount = 0;
int lastPollStatus = 0;

enum class EnrollmentWorkflowState : uint8_t {
  IDLE,
  AWAITING_APPROVAL,
  ENROLLING,
  AWAITING_RESULT_SYNC
};

struct EnrollmentWorkflow {
  EnrollmentWorkflowState state = EnrollmentWorkflowState::IDLE;
  String commandId;
  String employeeName;
  uint16_t slot = 0;
  uint16_t templateSlots[ENROLLMENT_POSITION_COUNT] = {};
  uint32_t mappingVersion = 0;
  bool resultSuccess = false;
  String resultMessage;
  unsigned long nextResultRetryAt = 0;
};

EnrollmentWorkflow enrollmentWorkflow;

// If PHP commits or rejects a website command but its acknowledgement is lost
// on Wi-Fi, do not make the employee repeat the physical two-scan workflow.
// The result endpoint is idempotent, so the exact command result stays queued
// in RAM and the terminal remains unavailable for a new scan until it is
// acknowledged. Enrollment has its own stronger sensor/database sync state.
struct DeferredCommandResult {
  bool active = false;
  String commandId;
  bool success = false;
  String message;
  unsigned long nextRetryAt = 0;
};

DeferredCommandResult deferredCommandResult;

enum class WifiConnectionState : uint8_t {
  IDLE,
  CONNECTING,
  ONLINE
};

WifiConnectionState wifiConnectionState = WifiConnectionState::IDLE;
unsigned long wifiAttemptStarted = 0;
unsigned long wifiNextAttemptAt = 0;
unsigned long wifiRetryDelayMs = WIFI_RETRY_INITIAL_MS;
unsigned long wifiConnectedAt = 0;
uint32_t wifiAttemptCount = 0;

// Retained through most resets. Together with esp_reset_reason(), this makes a
// real reboot distinguishable from an ordinary Wi-Fi reconnect attempt.
RTC_DATA_ATTR uint32_t retainedBootCount = 0;

unsigned long lastCommandPoll = 0;
unsigned long lastHeartbeat = 0;
unsigned long lastClockRetry = 0;
unsigned long lastSensorRetry = 0;
unsigned long lastFingerprintPoll = 0;
unsigned long lastButtonRead = 0;
unsigned long lastApiTest = 0;
uint8_t consecutiveSensorErrors = 0;
uint16_t fingerprintPhysicalCapacity = 0;

// Avoid redrawing an unchanged full-frame OLED buffer. This removes the
// visible flicker caused by repeated network/status refreshes.
String lastOledTitle;
String lastOledDetail;
bool standbyClockVisible = false;
unsigned long lastStandbyClockRefresh = 0;

void beginClockSync();
void serviceWifi();
void printHeartbeat();
bool testApiConnection(bool showDisplay);
void serviceOperationDelay(unsigned long durationMs);
void serviceSensorDelay(unsigned long durationMs);
void retryDeferredCommandResult();
bool reportEnrollmentProgress(
  const String& state,
  const String& message,
  uint8_t maxAttempts = 2
);

bool intervalElapsed(unsigned long startedAt, unsigned long intervalMs) {
  return (unsigned long) (millis() - startedAt) >= intervalMs;
}

bool deadlineReached(unsigned long now, unsigned long deadline) {
  return (int32_t) (now - deadline) >= 0;
}

void setRgb(uint8_t red, uint8_t green, uint8_t blue) {
  // Values are for a common-cathode RGB module.
  analogWrite(RGB_RED, red);
  analogWrite(RGB_GREEN, green);
  analogWrite(RGB_BLUE, blue);
}

void buzzerOn(uint16_t frequency = 2200) {
  tone(BUZZER, frequency);
}

void buzzerOff() {
  noTone(BUZZER);
  digitalWrite(BUZZER, LOW);
}

void beep(uint16_t durationMs, uint8_t repetitions = 1, uint16_t pauseMs = 90, uint16_t frequency = 2200) {
  for (uint8_t index = 0; index < repetitions; index++) {
    buzzerOn(frequency);
    delay(durationMs);
    buzzerOff();
    if (index + 1 < repetitions) delay(pauseMs);
  }
}

void beepSuccess() {
  beep(90, 2, 90, 2500);
}

void beepFailure() {
  beep(300, 1, 90, 900);
  delay(100);
  beep(90, 2, 90, 900);
}

void beepModeChanged() {
  beep(45, 1, 90, 1800);
}

void showMessage(const String& title, const String& detail = "") {
  // Every transaction/status message owns the OLED until showReady() returns
  // it to standby. The clock must never overwrite a scan or result prompt.
  standbyClockVisible = false;
  const String visibleTitle = title.substring(0, 17);
  const String visibleDetail = detail.substring(0, 25);
  if (visibleTitle == lastOledTitle && visibleDetail == lastOledDetail) return;

  lastOledTitle = visibleTitle;
  lastOledDetail = visibleDetail;
  oled.clearBuffer();
  oled.setFont(u8g2_font_6x12_tf);
  oled.drawStr(2, 14, "UCC HR TERMINAL");
  oled.drawHLine(2, 19, 124);
  oled.setFont(u8g2_font_7x14B_tf);
  oled.drawUTF8(2, 39, visibleTitle.c_str());
  oled.setFont(u8g2_font_5x8_tf);
  oled.drawUTF8(2, 56, visibleDetail.c_str());
  oled.sendBuffer();
}

bool clockIsReady() {
  time_t now;
  time(&now);
  return now > 1700000000;
}

void showStandbyClock() {
  const time_t now = time(nullptr);
  struct tm manilaTime = {};
  if (!clockIsReady() || localtime_r(&now, &manilaTime) == nullptr) {
    showMessage("SYNCING CLOCK", "Waiting for time sync");
    return;
  }

  // beginClockSync() already sets Asia/Manila's UTC+8 offset with no DST.
  // Format that existing clock only; do not offset the signed API timestamps.
  char timeText[12];
  char dateText[26];
  strftime(timeText, sizeof(timeText), "%I:%M:%S %p", &manilaTime);
  strftime(dateText, sizeof(dateText), "%a %d %b %Y PHT", &manilaTime);
  showMessage(timeText, dateText);
  standbyClockVisible = true;
  lastStandbyClockRefresh = millis();
}

void showReady() {
  buzzerOff();

  if (deferredCommandResult.active) {
    showMessage("SAVING RESULT", "Keep terminal powered");
    setRgb(70, 45, 0);
    return;
  }

  if (enrollmentWorkflow.state == EnrollmentWorkflowState::AWAITING_APPROVAL) {
    String detail;
    if (!fingerprintReady) {
      detail = "Sensor offline - wait";
      setRgb(90, 0, 0);
    } else if (WiFi.status() != WL_CONNECTED || !clockIsReady() || !apiAuthenticated) {
      detail = "Network offline - wait";
      setRgb(90, 35, 0);
    } else if (recognitionFingerReleaseRequired) {
      detail = "Remove thumb, then BTN1";
      setRgb(70, 45, 0);
    } else {
      detail = "BTN1=START BTN2=CANCEL";
      setRgb(70, 45, 0);
    }
    showMessage("ENROLL REQUEST", detail);
    return;
  }

  if (enrollmentWorkflow.state == EnrollmentWorkflowState::AWAITING_RESULT_SYNC) {
    showMessage("SAVING ENROLLMENT", "Keep terminal powered");
    setRgb(70, 45, 0);
    return;
  }

  if (!fingerprintReady) {
    showMessage("SENSOR OFFLINE", "Retrying AS608...");
    setRgb(90, 0, 0);
    return;
  }

  if (!apiConfigurationValid) {
    showMessage("API CONFIG ERROR", "Check API_BASE_URL");
    setRgb(90, 0, 0);
    return;
  }

  if (WiFi.status() != WL_CONNECTED) {
    showMessage("NETWORK OFFLINE", "Retrying WiFi...");
    setRgb(90, 35, 0);
    return;
  }

  if (!clockIsReady()) {
    showMessage("SYNCING CLOCK", "API waits for time");
    setRgb(60, 45, 0);
    return;
  }

  if (!apiAuthenticated) {
    showMessage("API CONNECTING", "Checking website");
    setRgb(60, 45, 0);
    return;
  }

  if (recognitionFingerReleaseRequired) {
    showMessage("REMOVE FINGER", "Ready after sensor is clear");
    setRgb(70, 45, 0);
    return;
  }

  showStandbyClock();
  setRgb(0, 0, 32);
}

void serviceStandbyClock() {
  if (!standbyClockVisible
      || biometricOperationActive
      || enrollmentWorkflow.state != EnrollmentWorkflowState::IDLE
      || deferredCommandResult.active
      || recognitionFingerReleaseRequired
      || !intervalElapsed(lastStandbyClockRefresh, STANDBY_CLOCK_REFRESH_MS)) {
    return;
  }

  // No waiting/network calls. showMessage() redraws only when the displayed
  // second/date changes; showReady() keeps existing fault screens in priority.
  showReady();
}

bool tryFingerprintBaud(uint32_t baud) {
  Serial.printf("[AS608] Trying %lu baud...\n", (unsigned long) baud);

  fingerprintSerial.end();
  delay(100);
  fingerprintSerial.begin(baud, SERIAL_8N1, FP_RX, FP_TX);
  delay(700);

  for (uint8_t attempt = 1; attempt <= 3; attempt++) {
    if (finger.verifyPassword()) {
      fingerprintBaud = baud;
      return true;
    }
    Serial.printf("[AS608] No reply (attempt %u/3).\n", attempt);
    delay(250);
  }

  return false;
}

bool startFingerprintSensor() {
  // AS608 normally uses 57600. Fallbacks support a sensor changed by another project.
  const uint32_t baudRates[] = {57600, 9600, 115200};
  for (uint8_t index = 0; index < sizeof(baudRates) / sizeof(baudRates[0]); index++) {
    if (!tryFingerprintBaud(baudRates[index])) continue;

    Serial.printf("[AS608] Detected successfully at %lu baud.\n", (unsigned long) fingerprintBaud);
    uint8_t parameterCode = FINGERPRINT_PACKETRECIEVEERR;
    for (uint8_t attempt = 1; attempt <= 3; attempt++) {
      parameterCode = finger.getParameters();
      if (parameterCode == FINGERPRINT_OK) break;
      Serial.printf(
        "[AS608] Parameter read failed (0x%02X), attempt %u/3.\n",
        parameterCode,
        attempt
      );
      delay(180);
    }
    if (parameterCode != FINGERPRINT_OK || finger.capacity == 0) {
      Serial.println("[AS608] Sensor parameters unavailable; refusing unsafe slot searches.");
      fingerprintSerial.end();
      continue;
    }

    fingerprintPhysicalCapacity = finger.capacity;
    // Keep the sensor-reported capacity intact. Searches use an explicit
    // website-managed range (pages 1..127) instead of changing this library
    // field or using fingerFastSearch(), whose range is hard-coded to 0..162.
    Serial.printf(
      "[AS608] Physical capacity: %u; website-managed highest slot: %u.\n",
      fingerprintPhysicalCapacity,
      min(fingerprintPhysicalCapacity, AS608_MAX_SLOT)
    );
    if (finger.getTemplateCount() == FINGERPRINT_OK) {
      Serial.printf("[AS608] Stored templates: %u.\n", finger.templateCount);
    }
    consecutiveSensorErrors = 0;
    return true;
  }

  Serial.println("[AS608] ERROR: no response at 57600, 9600, or 115200 baud.");
  Serial.println("[AS608] Check 5V/VIN, common GND, TX->GPIO33, and RX->GPIO32.");
  return false;
}

void markFingerprintOffline(const char* context) {
  fingerprintReady = false;
  consecutiveSensorErrors = 0;
  lastSensorRetry = millis();
  fingerprintSerial.end();
  Serial.printf("[AS608] Marked offline after repeated UART errors (%s); retrying later.\n", context);
}

String randomNonce() {
  char output[33];
  for (int index = 0; index < 16; index++) {
    sprintf(output + (index * 2), "%02x", (uint8_t) esp_random());
  }
  output[32] = '\0';
  return String(output);
}

String hmacSha256(const String& message) {
  byte digest[32];
  mbedtls_md_context_t context;
  mbedtls_md_init(&context);
  const mbedtls_md_info_t* info = mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);
  int result = info == nullptr ? -1 : mbedtls_md_setup(&context, info, 1);
  if (result == 0) {
    result = mbedtls_md_hmac_starts(
      &context,
      (const unsigned char*) DEVICE_SHARED_SECRET,
      strlen(DEVICE_SHARED_SECRET)
    );
  }
  if (result == 0) {
    result = mbedtls_md_hmac_update(
      &context,
      (const unsigned char*) message.c_str(),
      message.length()
    );
  }
  if (result == 0) result = mbedtls_md_hmac_finish(&context, digest);
  mbedtls_md_free(&context);

  if (result != 0) {
    Serial.printf("[AUTH] HMAC-SHA256 failed (%d); request cancelled.\n", result);
    return "";
  }

  char hex[65];
  for (int index = 0; index < 32; index++) {
    sprintf(hex + index * 2, "%02x", digest[index]);
  }
  hex[64] = '\0';
  return String(hex);
}

void initializeApiConfiguration() {
  configuredApiBaseUrl = String(API_BASE_URL);
  configuredApiBaseUrl.trim();
  while (configuredApiBaseUrl.endsWith("/")) {
    configuredApiBaseUrl.remove(configuredApiBaseUrl.length() - 1);
  }

  const bool validScheme = configuredApiBaseUrl.startsWith("http://")
    || configuredApiBaseUrl.startsWith("https://");
  const int schemeLength = configuredApiBaseUrl.startsWith("https://") ? 8 : 7;
  const bool secureUrlHasCa = !configuredApiBaseUrl.startsWith("https://")
    || strlen(SERVER_ROOT_CA) > 0;
  apiConfigurationValid = validScheme
    && configuredApiBaseUrl.length() > schemeLength
    && configuredApiBaseUrl.indexOf(' ') < 0
    && configuredApiBaseUrl.indexOf('[') != 0
    && secureUrlHasCa;

  if (apiConfigurationValid) {
    Serial.println("[CONFIG] API base URL: " + configuredApiBaseUrl);
  } else if (validScheme
      && configuredApiBaseUrl.startsWith("https://")
      && !secureUrlHasCa) {
    Serial.println(
      "[CONFIG] INVALID HTTPS CONFIGURATION. Add the server root CA to "
      "SERVER_ROOT_CA; insecure TLS is refused."
    );
  } else {
    Serial.println("[CONFIG] INVALID API_BASE_URL. Use http://COMPUTER_IPV4:8080 without Markdown or quotes.");
  }
}

bool apiUsesHttps() {
  return configuredApiBaseUrl.startsWith("https://");
}

String apiUrl(const String& path) {
  return configuredApiBaseUrl + path;
}

String requestStatusMessage(int statusCode) {
  switch (statusCode) {
    case REQUEST_STATUS_WIFI_OFFLINE:
      return "WiFi is not connected";
    case REQUEST_STATUS_CLOCK_NOT_READY:
      return "Clock is not synchronized";
    case REQUEST_STATUS_BEGIN_FAILED:
      return "Could not start HTTP request";
    case REQUEST_STATUS_RESPONSE_TOO_LARGE:
      return "Website response is too large";
    case REQUEST_STATUS_SIGNING_FAILED:
      return "Could not sign API request";
    case REQUEST_STATUS_INVALID_API_URL:
      return "Invalid API_BASE_URL";
    case HTTPC_ERROR_CONNECTION_REFUSED:
      return "Website refused connection";
    case HTTPC_ERROR_CONNECTION_LOST:
      return "Website connection was lost";
    case HTTPC_ERROR_READ_TIMEOUT:
      return "Website response timed out";
    default:
      if (statusCode <= 0) {
        return HTTPClient::errorToString(statusCode);
      }
      return "HTTP " + String(statusCode);
  }
}

String signedRequest(const String& method, const String& path, const String& body, int& statusCode) {
  if (!apiConfigurationValid) {
    statusCode = REQUEST_STATUS_INVALID_API_URL;
    return "";
  }

  if (WiFi.status() != WL_CONNECTED) {
    statusCode = REQUEST_STATUS_WIFI_OFFLINE;
    return "";
  }

  if (!clockIsReady()) {
    statusCode = REQUEST_STATUS_CLOCK_NOT_READY;
    return "";
  }

  const uint64_t epochMs = (uint64_t) time(nullptr) * 1000ULL;
  char timestampBuffer[24];
  snprintf(timestampBuffer, sizeof(timestampBuffer), "%llu", epochMs);
  const String timestamp(timestampBuffer);
  const String nonce = randomNonce();
  String canonical;
  canonical.reserve(method.length() + path.length() + strlen(DEVICE_ID)
    + strlen(DEVICE_CAPABILITIES) + strlen(FIRMWARE_VERSION)
    + timestamp.length() + nonce.length() + body.length() + 8);
  canonical = method;
  canonical += '\n';
  canonical += path;
  canonical += '\n';
  // Bind the authenticated identity as well as the request payload. Without
  // this field, a captured request could be replayed with another X-Device-ID.
  canonical += DEVICE_ID;
  canonical += '\n';
  // Enrollment safety depends on these advertised properties. Signing them
  // prevents a network intermediary from upgrading an old terminal's claims.
  canonical += DEVICE_CAPABILITIES;
  canonical += '\n';
  canonical += FIRMWARE_VERSION;
  canonical += '\n';
  canonical += timestamp;
  canonical += '\n';
  canonical += nonce;
  canonical += '\n';
  canonical += body;
  const String url = apiUrl(path);
  const String signature = hmacSha256(canonical);
  if (signature.length() != 64) {
    statusCode = REQUEST_STATUS_SIGNING_FAILED;
    return "";
  }

  const bool useHttps = apiUsesHttps();

  // IMPORTANT: apiPlainClient/apiSecureClient have program lifetime and thus
  // outlive this HTTPClient. Declaring HTTPClient before local client objects
  // caused the local clients to be destroyed first; HTTPClient's destructor
  // then dereferenced a dangling _client pointer and panicked the ESP32 after
  // an otherwise successful request.
  HTTPClient http;
  http.setConnectTimeout(HTTP_CONNECT_TIMEOUT_MS);
  http.setTimeout(HTTP_RESPONSE_TIMEOUT_MS);
  http.setReuse(false);

  bool began = false;
  if (useHttps) {
    // initializeApiConfiguration() rejects HTTPS without a CA. Never silently
    // disable certificate verification for biometric or attendance traffic.
    apiSecureClient.setCACert(SERVER_ROOT_CA);
    began = http.begin(apiSecureClient, url);
  } else {
    began = http.begin(apiPlainClient, url);
  }

  if (!began) {
    statusCode = REQUEST_STATUS_BEGIN_FAILED;
    Serial.println("[HTTP] Could not initialize request: " + url);
    http.end();
    if (useHttps) {
      apiSecureClient.stop();
    } else {
      apiPlainClient.stop();
    }
    return "";
  }

  http.addHeader("Content-Type", "application/json");
  http.addHeader("Connection", "close");
  http.addHeader("X-Device-ID", DEVICE_ID);
  http.addHeader("X-Request-Path", path);
  http.addHeader("X-Timestamp", timestamp);
  http.addHeader("X-Nonce", nonce);
  http.addHeader("X-Signature", signature);
  // The website uses this capability gate to ensure an older firmware cannot
  // receive an ENROLL command and start scanning without physical approval.
  http.addHeader("X-Firmware-Version", FIRMWARE_VERSION);
  http.addHeader("X-Device-Capabilities", DEVICE_CAPABILITIES);

  statusCode = method == "GET" ? http.GET() : http.POST(body);
  String response;
  if (statusCode > 0) {
    const int responseSize = http.getSize();
    if (responseSize > HTTP_MAX_RESPONSE_BYTES) {
      Serial.printf("[HTTP] Rejected oversized response from %s (%d bytes).\n",
        path.c_str(), responseSize);
      statusCode = REQUEST_STATUS_RESPONSE_TOO_LARGE;
    } else {
      response.reserve(responseSize > 0 ? responseSize + 1 : 512);
      response = http.getString();
    }
  }
  if (statusCode <= 0) {
    const String failure = requestStatusMessage(statusCode);
    Serial.printf("[HTTP] %s %s failed: %s (%d).\n",
      method.c_str(), path.c_str(), failure.c_str(), statusCode);
  }
  http.end();
  if (useHttps) {
    apiSecureClient.stop();
  } else {
    apiPlainClient.stop();
  }
  return response;
}

bool testApiConnection(bool showDisplay) {
  lastApiTest = millis();
  int status;
  const String response = signedRequest("GET", "/api/device/ping.php", "", status);
  lastPollStatus = status;

  JsonDocument doc;
  const DeserializationError parseError = deserializeJson(doc, response);
  apiAuthenticated = status >= 200
    && status < 300
    && !parseError
    && (doc["ok"] | false);

  if (apiAuthenticated) {
    const String serverMessage = doc["message"] | "Device API authenticated";
    Serial.printf("[API] AUTHENTICATED at %s (HTTP %d): %s\n",
      configuredApiBaseUrl.c_str(), status, serverMessage.c_str());
    if (showDisplay) {
      showMessage("API CONNECTED", "Website authenticated");
      setRgb(0, 65, 0);
      beepSuccess();
      serviceOperationDelay(1200);
    }
    return true;
  }

  String error = status <= 0 ? requestStatusMessage(status) : "No valid API response";
  if (!parseError) error = doc["error"] | error;
  Serial.printf("[API] CONNECTION/AUTH FAILED at %s (HTTP %d): %s\n",
    configuredApiBaseUrl.c_str(), status, error.c_str());
  if (showDisplay) {
    showMessage("API FAILED", error);
    setRgb(90, 0, 0);
    beepFailure();
    delay(1500);
  }
  return false;
}

bool waitForFingerRemoved(unsigned long timeoutMs, String& result) {
  const unsigned long started = millis();
  uint8_t stableNoFingerSamples = 0;
  uint8_t communicationErrors = 0;

  while (millis() - started < timeoutMs) {
    const uint8_t code = finger.getImage();
    if (code == FINGERPRINT_NOFINGER) {
      communicationErrors = 0;
      if (stableNoFingerSamples < 255) stableNoFingerSamples++;
      if (stableNoFingerSamples >= ENROLLMENT_RELEASE_STABLE_SAMPLES) {
        recognitionFingerReleaseRequired = false;
        recognitionReleaseSamples = 0;
        return true;
      }
    } else {
      // A partial lift or a sensor error is not proof that the finger was
      // removed. Restart the debounce window.
      stableNoFingerSamples = 0;
      if (code == FINGERPRINT_PACKETRECIEVEERR) {
        communicationErrors++;
        Serial.printf(
          "[AS608] UART error %u/5 while waiting for finger removal.\n",
          communicationErrors
        );
        if (communicationErrors >= 5) {
          result = "Sensor communication error during finger removal";
          markFingerprintOffline("recognition finger removal");
          return false;
        }
      } else {
        communicationErrors = 0;
      }
    }
    serviceSensorDelay(FINGER_SENSOR_POLL_INTERVAL_MS);
  }

  result = "Remove finger timeout";
  return false;
}

bool waitForEnrollmentFingerRelease(
  const String& capturedPosition,
  const String& nextPosition,
  const String& nextDetail,
  String& result
) {
  const unsigned long started = millis();
  unsigned long noFingerStarted = 0;
  unsigned long lastReminder = started;
  uint8_t noFingerSamples = 0;
  uint8_t communicationErrors = 0;

  Serial.printf(
    "[ENROLL] %s captured. Remove thumb completely; next step is %s.\n",
    capturedPosition.c_str(),
    nextPosition.c_str()
  );
  showMessage("SCAN CAPTURED", "Remove thumb completely");
  beep(45, 2, 100, 1550);

  while (millis() - started < ENROLLMENT_RELEASE_TIMEOUT_MS) {
    const uint8_t code = finger.getImage();
    const unsigned long now = millis();

    if (code == FINGERPRINT_NOFINGER) {
      communicationErrors = 0;
      if (noFingerSamples == 0) noFingerStarted = now;
      if (noFingerSamples < 255) noFingerSamples++;

      if (noFingerSamples >= ENROLLMENT_RELEASE_STABLE_SAMPLES
          && now - noFingerStarted >= ENROLLMENT_RELEASE_STABLE_MS) {
        Serial.printf(
          "[ENROLL] Stable thumb release confirmed after %s. Next: %s.\n",
          capturedPosition.c_str(),
          nextPosition.c_str()
        );
        const String releasedDetail = nextPosition == "SAVING"
          ? nextDetail
          : "Next: " + nextPosition;
        showMessage("THUMB REMOVED", releasedDetail);
        beepModeChanged();
        serviceSensorDelay(PROMPT_TRANSITION_MS);
        return true;
      }
    } else {
      noFingerSamples = 0;
      noFingerStarted = 0;

      if (code == FINGERPRINT_PACKETRECIEVEERR) {
        communicationErrors++;
        Serial.printf(
          "[ENROLL] UART error %u/5 while confirming thumb release.\n",
          communicationErrors
        );
        if (communicationErrors >= 5) {
          result = "Sensor communication error during thumb release";
          markFingerprintOffline("enrollment thumb release");
          return false;
        }
      } else {
        communicationErrors = 0;
      }
    }

    if (now - lastReminder >= ENROLLMENT_RELEASE_REMINDER_MS) {
      lastReminder = now;
      Serial.printf(
        "[ENROLL] Still waiting for complete thumb release. Next: %s.\n",
        nextPosition.c_str()
      );
      showMessage("REMOVE THUMB", "Take thumb off completely");
      beep(40, 2, 110, 1450);
    }

    serviceSensorDelay(FINGER_SENSOR_POLL_INTERVAL_MS);
  }

  result = "Thumb was not removed before " + nextPosition;
  Serial.println("[ENROLL] " + result + ".");
  return false;
}

void reportEnrollmentPosition(
  uint8_t positionNumber,
  const String& positionName,
  uint8_t attempt = 1
) {
  String message = "Capture " + String(positionNumber) + "/"
    + String(ENROLLMENT_POSITION_COUNT) + ": " + positionName;
  if (attempt > 1) {
    message += " retry " + String(attempt) + "/" + String(ENROLLMENT_POSITION_ATTEMPTS);
  }

  if (!reportEnrollmentProgress("SCANNING", message, 1)) {
    // The initial SCANNING transition in approvePendingEnrollment() remains
    // mandatory. Position updates are informational and must never interrupt a
    // physical capture after the website already approved the operation.
    Serial.println("[ENROLL] Live position progress was not acknowledged; capture continues: " + message);
  }
}

// Send the documented AS608 PS_Search command through Adafruit's public packet
// helpers. Starting at page 1 and searching 127 managed pages includes website
// slots 1..127 while excluding page 0 and stale templates above 127. Adafruit
// fingerFastSearch() cannot be used here because v2.1.4 hard-codes 0..162.
uint8_t searchManagedFingerprintBuffer(uint8_t bufferId) {
  if ((bufferId != 1 && bufferId != 2) || fingerprintPhysicalCapacity == 0) {
    return FINGERPRINT_PACKETRECIEVEERR;
  }

  const uint16_t pageCount = min(fingerprintPhysicalCapacity, AS608_MAX_SLOT);
  uint8_t data[] = {
    FINGERPRINT_SEARCH,
    bufferId,
    0x00,
    0x01,
    (uint8_t) (pageCount >> 8),
    (uint8_t) (pageCount & 0xFF)
  };
  Adafruit_Fingerprint_Packet packet(
    FINGERPRINT_COMMANDPACKET,
    sizeof(data),
    data
  );
  finger.writeStructuredPacket(packet);
  if (finger.getStructuredPacket(&packet) != FINGERPRINT_OK
      || packet.type != FINGERPRINT_ACKPACKET) {
    return FINGERPRINT_PACKETRECIEVEERR;
  }

  finger.fingerID = 0xFFFF;
  finger.confidence = 0;
  const uint8_t confirmationCode = packet.data[0];
  if (confirmationCode != FINGERPRINT_OK) return confirmationCode;
  if (packet.length < 7) return FINGERPRINT_PACKETRECIEVEERR;

  finger.fingerID = ((uint16_t) packet.data[1] << 8) | packet.data[2];
  finger.confidence = ((uint16_t) packet.data[3] << 8) | packet.data[4];
  return FINGERPRINT_OK;
}

bool identifyCapturedImage(
  int& slot,
  String& result,
  uint8_t* searchCodeOut = nullptr
) {
  if (searchCodeOut != nullptr) *searchCodeOut = FINGERPRINT_OK;
  const uint8_t convertCode = finger.image2Tz(1);
  if (convertCode != FINGERPRINT_OK) {
    if (convertCode == FINGERPRINT_PACKETRECIEVEERR
        && searchCodeOut != nullptr) {
      *searchCodeOut = convertCode;
    }
    const bool poorImage = convertCode == FINGERPRINT_IMAGEMESS
      || convertCode == FINGERPRINT_FEATUREFAIL
      || convertCode == FINGERPRINT_INVALIDIMAGE;
    result = poorImage
      ? "Fingerprint image unclear"
      : "Fingerprint conversion error";
    Serial.printf("[SCAN] image2Tz failed: 0x%02X.\n", convertCode);
    return false;
  }

  // Search the converted image immediately for quick feedback. Some AS608
  // variants report 0x17 while the thumb remains on the glass; only those
  // variants need a lift before searching the preserved character buffer.
  recognitionFingerReleaseRequired = true;
  recognitionReleaseSamples = 0;
  String removalResult;
  bool fingerWasReleased = false;
  uint8_t searchCode = searchManagedFingerprintBuffer(1);
  for (uint8_t attempt = 1; attempt <= MAX_RESIDUAL_FINGER_RETRIES; attempt++) {
    if (searchCode != AS608_RESIDUAL_FINGER) break;

    Serial.printf(
      "[SCAN] AS608 residual-finger response (0x17), recovery %u/%u; "
      "preserving the captured template buffer.\n",
      attempt,
      MAX_RESIDUAL_FINGER_RETRIES
    );
    showMessage("SCAN CAPTURED", "Lift thumb to finish check");
    if (!waitForFingerRemoved(FINGER_REMOVE_TIMEOUT_MS, removalResult)) {
      result = removalResult;
      return false;
    }
    fingerWasReleased = true;
    searchCode = searchManagedFingerprintBuffer(1);
    if (searchCode == AS608_RESIDUAL_FINGER && attempt < MAX_RESIDUAL_FINGER_RETRIES) {
      serviceSensorDelay(120);
    }
  }

  if (searchCodeOut != nullptr) *searchCodeOut = searchCode;
  if (searchCode == FINGERPRINT_NOTFOUND) {
    result = "Fingerprint not enrolled";
    return false;
  }
  if (searchCode == AS608_RESIDUAL_FINGER) {
    result = "Sensor needs a fresh scan; remove finger and try again";
    Serial.println(
      "[SCAN] Residual-finger recovery limit reached; ending this scan safely."
    );
    return false;
  }
  if (searchCode != FINGERPRINT_OK) {
    result = "Fingerprint search error";
    Serial.printf("[SCAN] Search failed: 0x%02X.\n", searchCode);
    return false;
  }

  slot = finger.fingerID;
  Serial.printf("[SCAN] Matched slot %d (confidence %u).\n", slot, finger.confidence);
  if (finger.confidence < MIN_MATCH_CONFIDENCE) {
    result = "Fingerprint confidence too low";
    Serial.printf(
      "[SCAN] Rejected slot %d: confidence %u is below %u.\n",
      slot,
      finger.confidence,
      MIN_MATCH_CONFIDENCE
    );
    slot = 0;
    return false;
  }
  // On a fast sensor the match is already known while the thumb is present;
  // still require removal before scan 2 or the next attendance transaction.
  showMessage("SCAN MATCHED", "Lift thumb to continue");
  if (!fingerWasReleased
      && !waitForFingerRemoved(FINGER_REMOVE_TIMEOUT_MS, removalResult)) {
    result = removalResult;
    return false;
  }
  return slot > 0;
}

bool resolveEmployeeFingerprintSlot(
  uint16_t sensorSlot,
  uint16_t& employeeSlot,
  String& result
) {
  employeeSlot = 0;
  if (sensorSlot == 0 || sensorSlot > AS608_MAX_SLOT) {
    result = "Invalid sensor template slot";
    return false;
  }

  JsonDocument requestDoc;
  requestDoc["sensorSlot"] = sensorSlot;
  String body;
  serializeJson(requestDoc, body);

  int status;
  const String response = signedRequest(
    "POST",
    "/api/device/resolve_fingerprint.php",
    body,
    status
  );
  lastPollStatus = status;
  lastApiTest = millis();

  JsonDocument responseDoc;
  const DeserializationError parseError = deserializeJson(responseDoc, response);
  if (status >= 200 && status < 300
      && !parseError
      && (responseDoc["ok"] | false)) {
    employeeSlot = responseDoc["fingerprintSlot"] | 0;
    if (employeeSlot > 0 && employeeSlot <= AS608_MAX_SLOT) {
      Serial.printf(
        "[SCAN] Sensor slot %u resolved to employee profile slot %u (%s).\n",
        sensorSlot,
        employeeSlot,
        (responseDoc["position"] | "LEGACY")
      );
      return true;
    }
  }

  if (!parseError) {
    result = responseDoc["error"] | "Fingerprint mapping not found";
  } else if (status <= 0) {
    result = "Website connection failed during fingerprint lookup";
  } else {
    result = "Invalid fingerprint lookup response";
  }
  Serial.printf(
    "[SCAN] Could not resolve sensor slot %u (HTTP %d): %s.\n",
    sensorSlot,
    status,
    result.c_str()
  );
  return false;
}

bool waitForIdentifiedFinger(
  unsigned long timeoutMs,
  int& slot,
  String& result,
  bool imageAlreadyCaptured = false
) {
  const unsigned long started = millis();
  bool useCapturedImage = imageAlreadyCaptured;
  uint8_t communicationErrors = 0;
  uint8_t qualityRetries = 0;
  uint8_t searchCommunicationErrors = 0;

  while (millis() - started < timeoutMs) {
    const uint8_t imageCode = useCapturedImage
      ? FINGERPRINT_OK
      : finger.getImage();
    useCapturedImage = false;

    if (imageCode == FINGERPRINT_OK) {
      uint8_t searchCode = FINGERPRINT_OK;
      if (identifyCapturedImage(slot, result, &searchCode)) return true;
      const bool unclearImage = result == "Fingerprint image unclear";
      const bool residualSearch = searchCode == AS608_RESIDUAL_FINGER;
      const bool failedSearchUart = searchCode == FINGERPRINT_PACKETRECIEVEERR;
      if (failedSearchUart && ++searchCommunicationErrors >= 3) {
        result = "Sensor communication error during fingerprint search";
        markFingerprintOffline("finger identification search");
        return false;
      }
      if (!failedSearchUart) searchCommunicationErrors = 0;
      if ((!unclearImage && !residualSearch && !failedSearchUart)
          || ++qualityRetries >= 3) {
        return false;
      }
      if (!residualSearch) {
        showMessage("SCAN UNCLEAR", "Lift thumb; place flat");
        recognitionFingerReleaseRequired = true;
        String removalResult;
        if (!waitForFingerRemoved(FINGER_REMOVE_TIMEOUT_MS, removalResult)) {
          result = removalResult;
          return false;
        }
      }
      showMessage("PLACE THUMB", "Retry this scan");
      continue;
    }
    if (imageCode == FINGERPRINT_IMAGEFAIL) {
      if (++qualityRetries >= 3) {
        result = "Fingerprint image unclear";
        return false;
      }
      showMessage("SCAN UNCLEAR", "Lift thumb; place flat");
      recognitionFingerReleaseRequired = true;
      String removalResult;
      if (!waitForFingerRemoved(FINGER_REMOVE_TIMEOUT_MS, removalResult)) {
        result = removalResult;
        return false;
      }
      showMessage("PLACE THUMB", "Try this scan again");
      continue;
    }
    if (imageCode == FINGERPRINT_PACKETRECIEVEERR) {
      communicationErrors++;
      if (communicationErrors >= 3) {
        result = "Sensor communication error";
        markFingerprintOffline("finger identification");
        return false;
      }
    } else {
      communicationErrors = 0;
    }
    serviceSensorDelay(FINGER_SENSOR_POLL_INTERVAL_MS);
  }

  result = "Fingerprint scan timeout";
  return false;
}

bool verifyFingerTwice(
  uint16_t expectedSlot,
  const String& employeeName,
  bool firstImageAlreadyCaptured,
  int& matchedSlot,
  String& result
) {
  showMessage(
    firstImageAlreadyCaptured ? "CHECKING SCAN 1" : "PLACE THUMB 1/2",
    firstImageAlreadyCaptured ? "Capture detected" : "Press flat and hold still"
  );
  Serial.printf("[VERIFY] Waiting for scan 1; expected slot=%u.\n", expectedSlot);

  int firstSensorSlot = 0;
  if (!waitForIdentifiedFinger(FINGER_SCAN_TIMEOUT_MS, firstSensorSlot, result, firstImageAlreadyCaptured)) {
    Serial.println("[VERIFY] Scan 1 failed: " + result);
    return false;
  }

  uint16_t firstSlot = 0;
  showMessage("CHECKING SCAN 1", "Matching employee...");
  if (!resolveEmployeeFingerprintSlot((uint16_t) firstSensorSlot, firstSlot, result)) {
    return false;
  }

  if (expectedSlot > 0 && firstSlot != expectedSlot) {
    result = "Wrong employee on scan 1";
    Serial.printf("[VERIFY] Scan 1 got slot %d, expected %u.\n", firstSlot, expectedSlot);
    return false;
  }

  // The first identification call has already confirmed a complete lift, so
  // the terminal can immediately request the independent second scan.
  showMessage("SCAN 1 MATCHED", "Place the same thumb again");
  beepModeChanged();
  serviceSensorDelay(PROMPT_TRANSITION_MS);
  showMessage("PLACE THUMB 2/2", "Same thumb; hold still");
  Serial.printf("[VERIFY] Waiting for scan 2; it must match slot %d.\n", firstSlot);

  int secondSensorSlot = 0;
  if (!waitForIdentifiedFinger(FINGER_SCAN_TIMEOUT_MS, secondSensorSlot, result)) {
    Serial.println("[VERIFY] Scan 2 failed: " + result);
    return false;
  }


  uint16_t secondSlot = 0;
  showMessage("CHECKING SCAN 2", "Matching employee...");
  if (!resolveEmployeeFingerprintSlot((uint16_t) secondSensorSlot, secondSlot, result)) {
    return false;
  }

  if (secondSlot != firstSlot || (expectedSlot > 0 && secondSlot != expectedSlot)) {
    result = "Two scans did not match";
    Serial.printf(
      "[VERIFY] Employee mismatch: sensor1=%d profile1=%u sensor2=%d profile2=%u expected=%u.\n",
      firstSensorSlot, firstSlot, secondSensorSlot, secondSlot, expectedSlot
    );
    return false;
  }

  matchedSlot = (int) secondSlot;
  result = "Verified twice at slot " + String(secondSlot);
  Serial.println("[VERIFY] SUCCESS: " + result);
  return true;
}

bool waitForEnrollmentImage(
  uint8_t bufferId,
  unsigned long timeoutMs,
  const String& promptTitle,
  const String& promptDetail,
  String& result
) {
  const unsigned long started = millis();
  uint8_t communicationErrors = 0;

  while (millis() - started < timeoutMs) {
    const uint8_t imageCode = finger.getImage();
    if (imageCode == FINGERPRINT_OK) {
      communicationErrors = 0;
      const uint8_t convertCode = finger.image2Tz(bufferId);
      if (convertCode == FINGERPRINT_OK) {
        Serial.printf(
          "[ENROLL] Image quality accepted in AS608 buffer %u.\n",
          bufferId
        );
        return true;
      }

      const bool poorImage = convertCode == FINGERPRINT_IMAGEMESS
        || convertCode == FINGERPRINT_FEATUREFAIL
        || convertCode == FINGERPRINT_INVALIDIMAGE;
      if (!poorImage) {
        result = convertCode == FINGERPRINT_PACKETRECIEVEERR
          ? "Sensor communication error during image conversion"
          : "Fingerprint image conversion failed";
        Serial.printf("[ENROLL] image2Tz(%u) failed: 0x%02X.\n", bufferId, convertCode);
        if (convertCode == FINGERPRINT_PACKETRECIEVEERR) {
          markFingerprintOffline("enrollment image conversion");
        }
        return false;
      }

      Serial.printf(
        "[ENROLL] Poor fingerprint image (0x%02X); rejecting and retrying.\n",
        convertCode
      );
      showMessage("IMAGE UNCLEAR", "Press flat and hold still");
      String removalResult;
      if (!waitForEnrollmentFingerRelease(
            "UNCLEAR SCAN",
            "SAME POSITION",
            promptDetail,
            removalResult
          )) {
        result = removalResult;
        return false;
      }
      showMessage(promptTitle, promptDetail);
    } else if (imageCode == FINGERPRINT_IMAGEFAIL) {
      showMessage("IMAGE UNCLEAR", "Lift thumb; place flat");
      String removalResult;
      if (!waitForEnrollmentFingerRelease(
            "UNCLEAR SCAN", "UNCLEAR SCAN", promptDetail, removalResult
          )) {
        result = removalResult;
        return false;
      }
      showMessage(promptTitle, promptDetail);
    } else if (imageCode == FINGERPRINT_PACKETRECIEVEERR) {
      communicationErrors++;
      if (communicationErrors >= 3) {
        result = "Sensor communication error";
        markFingerprintOffline("finger enrollment");
        return false;
      }
    } else {
      // Count only consecutive UART failures. NOFINGER and other valid sensor
      // replies prove communication recovered during the placement window.
      communicationErrors = 0;
    }
    serviceSensorDelay(FINGER_SENSOR_POLL_INTERVAL_MS);
  }

  result = "Enrollment scan timeout";
  return false;
}

// Enrollment uses only documented Adafruit_Fingerprint operations below.
enum class EnrollmentAuthorization : uint8_t {
  AUTHORIZED,
  RETRY_POSITION,
  REJECTED
};

EnrollmentAuthorization authorizeEnrollmentCapture(
  String& result,
  uint8_t bufferId = 1
) {
  if (bufferId != 1 && bufferId != 2) {
    result = "Invalid AS608 enrollment buffer";
    return EnrollmentAuthorization::REJECTED;
  }

  // A characteristic buffer can match more than one stale physical page.
  // Remove only pages that the website explicitly confirms are unassigned,
  // then search again without recapturing the image.
  constexpr uint8_t MAX_RECLAIM_CHECKS = 4;
  for (uint8_t reclaimCheck = 0;
       reclaimCheck <= MAX_RECLAIM_CHECKS;
       reclaimCheck++) {
    uint8_t searchCode = FINGERPRINT_PACKETRECIEVEERR;
    for (uint8_t attempt = 1;
         attempt <= ENROLLMENT_DUPLICATE_SEARCH_ATTEMPTS;
         attempt++) {
      searchCode = searchManagedFingerprintBuffer(bufferId);
      if (searchCode != FINGERPRINT_PACKETRECIEVEERR
          && searchCode != AS608_RESIDUAL_FINGER) break;

      if (searchCode == AS608_RESIDUAL_FINGER) {
        Serial.printf(
          "[ENROLL] Residual-finger response while checking buffer %u "
          "(attempt %u/%u); keeping the captured buffer.\n",
          bufferId,
          attempt,
          ENROLLMENT_DUPLICATE_SEARCH_ATTEMPTS
        );
        showMessage("KEEP THUMB OFF", "Checking captured fingerprint");
        String removalResult;
        recognitionFingerReleaseRequired = true;
        if (!waitForFingerRemoved(1500, removalResult)) {
          result = removalResult;
          return EnrollmentAuthorization::RETRY_POSITION;
        }
      }

      if (attempt < ENROLLMENT_DUPLICATE_SEARCH_ATTEMPTS) {
        serviceSensorDelay(searchCode == AS608_RESIDUAL_FINGER ? 120 : 200);
      }
    }

    if (searchCode == FINGERPRINT_NOTFOUND) {
      Serial.printf(
        "[ENROLL] AS608 buffer %u has no stored match at this angle.\n",
        bufferId
      );
      return EnrollmentAuthorization::AUTHORIZED;
    }
    if (searchCode == AS608_RESIDUAL_FINGER) {
      result = "Sensor needs a fresh thumb placement for this angle";
      Serial.println(
        "[ENROLL] Residual-finger recovery limit reached; this angle will retry."
      );
      return EnrollmentAuthorization::RETRY_POSITION;
    }
    if (searchCode != FINGERPRINT_OK) {
      result = "Could not check existing fingerprints";
      if (searchCode == FINGERPRINT_PACKETRECIEVEERR) {
        markFingerprintOffline("five-template duplicate check");
      }
      return EnrollmentAuthorization::REJECTED;
    }

    const uint16_t matchedSensorSlot = finger.fingerID;
    JsonDocument requestDoc;
    requestDoc["commandId"] = enrollmentWorkflow.commandId;
    requestDoc["intendedSlot"] = enrollmentWorkflow.slot;
    requestDoc["mappingVersion"] = enrollmentWorkflow.mappingVersion;
    requestDoc["matchedSlot"] = matchedSensorSlot;
    String body;
    serializeJson(requestDoc, body);

    int status;
    const String response = signedRequest(
      "POST",
      "/api/device/enrollment_slot_check.php",
      body,
      status
    );
    lastPollStatus = status;
    lastApiTest = millis();

    JsonDocument responseDoc;
    const DeserializationError parseError =
      deserializeJson(responseDoc, response);
    if (status < 200 || status >= 300
        || parseError
        || !(responseDoc["ok"] | false)) {
      result = !parseError
        ? String(responseDoc["reason"] | "Fingerprint ownership check failed")
        : "Invalid fingerprint ownership response";
      return EnrollmentAuthorization::REJECTED;
    }

    if (responseDoc["belongsToEmployee"] | false) {
      Serial.printf(
        "[ENROLL] Buffer %u matched this employee at sensor slot %u "
        "(confidence %u).\n",
        bufferId,
        matchedSensorSlot,
        finger.confidence
      );
      return EnrollmentAuthorization::AUTHORIZED;
    }

    if (!(responseDoc["reclaimable"] | false)) {
      result = responseDoc["reason"]
        | "Fingerprint belongs to another employee";
      return EnrollmentAuthorization::RETRY_POSITION;
    }

    const uint8_t deleteCode = finger.deleteModel(matchedSensorSlot);
    if (deleteCode != FINGERPRINT_OK
        && deleteCode != FINGERPRINT_NOTFOUND) {
      result = "Could not clear unassigned fingerprint template";
      return EnrollmentAuthorization::REJECTED;
    }
    Serial.printf(
      "[ENROLL] Cleared website-authorized unassigned sensor slot %u; "
      "checking for another match.\n",
      matchedSensorSlot
    );
  }

  result = "Too many unassigned duplicate fingerprint templates";
  return EnrollmentAuthorization::REJECTED;
}

bool verifyStoredEnrollment(uint16_t intendedSlot, String& result) {

  // Five templates from the same thumb may all match one scan. Searching here
  // can legitimately return a different position slot, so verify persistence
  // by loading the exact page that was just stored.
  uint8_t loadCode = FINGERPRINT_PACKETRECIEVEERR;
  for (uint8_t attempt = 1; attempt <= STORED_MODEL_LOAD_RETRIES; attempt++) {
    loadCode = finger.loadModel(intendedSlot);
    if (loadCode == FINGERPRINT_OK) {
      Serial.printf(
        "[ENROLL] Stored template reloaded from assigned slot %u (attempt %u/%u).\n",
        intendedSlot,
        attempt,
        STORED_MODEL_LOAD_RETRIES
      );
      Serial.printf("[ENROLL] Stored template verified at assigned slot %u.\n", intendedSlot);
      return true;
    }

    Serial.printf(
      "[ENROLL] loadModel(%u) attempt %u/%u failed: 0x%02X.\n",
      intendedSlot,
      attempt,
      STORED_MODEL_LOAD_RETRIES,
      loadCode
    );
    if (attempt < STORED_MODEL_LOAD_RETRIES) serviceSensorDelay(180);
  }

  result = "Could not reload stored fingerprint";
  Serial.println("[ENROLL] Stored slot was preserved for a safe enrollment retry.");
  return false;
}

// The active website-compatible enrollment implementation starts here.
bool enrollOnePositionTemplate(
  uint8_t positionIndex,
  uint16_t sensorSlot,
  bool& slotModified,
  String& result
) {
  slotModified = false;
  const String positionName = ENROLLMENT_POSITION_NAMES[positionIndex];
  const String positionDetail = ENROLLMENT_POSITION_DETAILS[positionIndex];
  const String captureTitle = String(positionIndex + 1) + "/"
    + String(ENROLLMENT_POSITION_COUNT) + " " + positionName;

  for (uint8_t attempt = 1; attempt <= ENROLLMENT_POSITION_ATTEMPTS; attempt++) {
    // A retry is already visible on the OLED; avoid a network round trip
    // before every replacement impression.
    if (attempt == 1) reportEnrollmentPosition(positionIndex + 1, positionName, attempt);
    showMessage(captureTitle, positionDetail);
    Serial.printf(
      "[ENROLL] Position %u/%u %s, model attempt %u/%u, physical slot %u.\n",
      positionIndex + 1,
      ENROLLMENT_POSITION_COUNT,
      positionName.c_str(),
      attempt,
      ENROLLMENT_POSITION_ATTEMPTS,
      sensorSlot
    );

    if (!waitForEnrollmentImage(
          1,
          FINGER_SCAN_TIMEOUT_MS,
          captureTitle,
          positionDetail,
          result
        )) {
      if (result == "Enrollment scan timeout" && attempt < ENROLLMENT_POSITION_ATTEMPTS) {
        showMessage("SCAN TIMED OUT", "Place thumb again");
        continue;
      }
      return false;
    }

    // Character buffers survive finger removal. Clear the glass before every
    // duplicate/ownership search; otherwise anti-residual AS608 firmware can
    // return 0x17 while the thumb is still touching the sensor.
    if (!waitForEnrollmentFingerRelease(
          positionName,
          "CHECKING",
          "Checking fingerprint",
          result
        )) return false;

    // Reject any first impression known to belong to another employee.
    // A new side angle can legitimately have no match to the center model.
    EnrollmentAuthorization authorization = authorizeEnrollmentCapture(
      result,
      1
    );
    if (authorization != EnrollmentAuthorization::AUTHORIZED) {
      if (authorization == EnrollmentAuthorization::REJECTED) return false;
      showMessage("SCAN NOT ACCEPTED", result);
      beep(50, 2, 100, 1350);
      if (attempt >= ENROLLMENT_POSITION_ATTEMPTS) return false;
      serviceSensorDelay(RESULT_DISPLAY_MS);
      continue;
    }

    const String confirmTitle = positionName + " AGAIN";
    showMessage(confirmTitle, "Same angle; hold still");
    Serial.printf(
      "[ENROLL] Confirming %s at the same thumb angle.\n",
      positionName.c_str()
    );
    if (!waitForEnrollmentImage(
          2,
          FINGER_SCAN_TIMEOUT_MS,
          confirmTitle,
          "Same angle; hold still",
          result
        )) {
      if (result == "Enrollment scan timeout" && attempt < ENROLLMENT_POSITION_ATTEMPTS) {
        showMessage("SCAN TIMED OUT", "Repeat the same angle");
        continue;
      }
      return false;
    }

    if (!waitForEnrollmentFingerRelease(
          positionName + " CONFIRMED",
          "CHECKING",
          "Checking fingerprint",
          result
        )) return false;

    // AS608 createModel() validates that the two captures belong together.
    // Searching the completed model below catches a known second thumb too,
    // without an extra sensor search and website round trip on every angle.
    const uint8_t modelCode = finger.createModel();
    if (modelCode != FINGERPRINT_OK) {
      Serial.printf(
        "[ENROLL] %s createModel attempt %u/%u failed: 0x%02X.\n",
        positionName.c_str(),
        attempt,
        ENROLLMENT_POSITION_ATTEMPTS,
        modelCode
      );
      if (modelCode != FINGERPRINT_ENROLLMISMATCH) {
        result = "Could not create " + positionName + " template";
        return false;
      }
      if (attempt >= ENROLLMENT_POSITION_ATTEMPTS) {
        result = positionName + " scans did not match";
        return false;
      }

      showMessage("SCANS NOT MATCHED", "Repeat the same angle");
      beep(50, 2, 100, 1350);
      serviceSensorDelay(RESULT_DISPLAY_MS);
      continue;
    }

    // createModel() combines only AS608 CharBuffer1 and CharBuffer2. Search
    // the completed model before storing it because the combined model can
    // reveal a duplicate that neither individual impression matched strongly.
    authorization = authorizeEnrollmentCapture(
      result,
      1
    );
    if (authorization != EnrollmentAuthorization::AUTHORIZED) {
      if (authorization == EnrollmentAuthorization::REJECTED) return false;
      showMessage("ANGLE NOT MATCHED", "Use the same thumb again");
      beep(50, 2, 100, 1350);
      if (attempt >= ENROLLMENT_POSITION_ATTEMPTS) return false;
      serviceSensorDelay(RESULT_DISPLAY_MS);
      continue;
    }
    // The sensor cannot prove that a genuinely new angled impression belongs
    // to the center template when both searches say NOTFOUND. The operator
    // must keep the same employee at the terminal; rejecting such impressions
    // would defeat five-angle enrollment. createModel() still checks that the
    // two impressions for this position are from the same thumb.

    const uint8_t deleteCode = finger.deleteModel(sensorSlot);
    if (deleteCode != FINGERPRINT_OK && deleteCode != FINGERPRINT_NOTFOUND) {
      result = "Could not replace " + positionName + " sensor slot";
      Serial.printf(
        "[ENROLL] deleteModel(%u) for %s failed: 0x%02X.\n",
        sensorSlot,
        positionName.c_str(),
        deleteCode
      );
      return false;
    }
    slotModified = deleteCode == FINGERPRINT_OK;

    const uint8_t storeCode = finger.storeModel(sensorSlot);
    if (storeCode != FINGERPRINT_OK) {
      result = "Could not save " + positionName + " template";
      Serial.printf(
        "[ENROLL] storeModel(%u) for %s failed: 0x%02X.\n",
        sensorSlot,
        positionName.c_str(),
        storeCode
      );
      return false;
    }
    slotModified = true;
    if (!verifyStoredEnrollment(sensorSlot, result)) return false;

    Serial.printf(
      "[ENROLL] Saved position %u/%u %s in physical AS608 slot %u.\n",
      positionIndex + 1,
      ENROLLMENT_POSITION_COUNT,
      positionName.c_str(),
      sensorSlot
    );
    return true;
  }

  result = positionName + " enrollment failed";
  return false;
}

bool clearFailedEnrollmentProfile(
  const uint16_t templateSlots[ENROLLMENT_POSITION_COUNT],
  const bool modifiedSlots[ENROLLMENT_POSITION_COUNT]
) {
  if (!fingerprintReady) {
    Serial.println(
      "[ENROLL] Partial-profile cleanup deferred: AS608 is offline."
    );
    return false;
  }

  bool allCleared = true;
  for (uint8_t index = 0; index < ENROLLMENT_POSITION_COUNT; index++) {
    // On re-enrollment, untouched slots still hold the employee's prior
    // templates. Never erase those because a later angle failed.
    if (!modifiedSlots[index]) continue;
    const uint8_t deleteCode = finger.deleteModel(templateSlots[index]);
    const bool cleared = deleteCode == FINGERPRINT_OK
      || deleteCode == FINGERPRINT_NOTFOUND;
    if (!cleared) allCleared = false;
    Serial.printf(
      "[ENROLL] Failure cleanup slot %u: 0x%02X (%s).\n",
      templateSlots[index],
      deleteCode,
      cleared ? "clear" : "ERROR"
    );
    serviceSensorDelay(80);
  }
  return allCleared;
}

bool enrollFingerprintTemplates(
  uint16_t canonicalSlot,
  const uint16_t templateSlots[ENROLLMENT_POSITION_COUNT],
  const String& employeeName,
  String& result
) {
  /*
    The website allocates five real AS608 pages for one employee. The AS608
    cannot merge five images into one model; createModel() supports exactly
    two characteristic buffers. We therefore capture each guided position
    twice, create one legitimate two-impression model for that position, and
    store the five resulting models in the five server-provided pages.

    Do not use the standalone sketch's arithmetic 2*N slot mapping here:
    employee IDs, canonical profile slots, and physical AS608 pages are
    different identifiers in the website/database contract.
  */
  if (canonicalSlot == 0 || templateSlots[0] != canonicalSlot) {
    result = "Center template slot does not match employee mapping";
    return false;
  }
  const uint16_t usableSensorCapacity = min(
    fingerprintPhysicalCapacity,
    AS608_MAX_SLOT
  );
  for (uint8_t index = 0; index < ENROLLMENT_POSITION_COUNT; index++) {
    if (templateSlots[index] == 0
        || templateSlots[index] > usableSensorCapacity) {
      result = "Fingerprint slot exceeds AS608 capacity";
      return false;
    }
    for (uint8_t earlier = 0; earlier < index; earlier++) {
      if (templateSlots[earlier] == templateSlots[index]) {
        result = "Five-template sensor slots must be unique";
        return false;
      }
    }
  }

  Serial.printf(
    "[ENROLL] Starting five-template profile %u for %s. Slots: %u,%u,%u,%u,%u.\n",
    canonicalSlot,
    employeeName.c_str(),
    templateSlots[0], templateSlots[1], templateSlots[2],
    templateSlots[3], templateSlots[4]
  );
  showMessage("5-ANGLE ENROLL", employeeName);
  setRgb(55, 35, 0);
  beepModeChanged();
  serviceSensorDelay(PROMPT_TRANSITION_MS);

  bool modifiedSlots[ENROLLMENT_POSITION_COUNT] = {};
  for (uint8_t index = 0; index < ENROLLMENT_POSITION_COUNT; index++) {
    bool slotModified = false;
    if (!enrollOnePositionTemplate(
          index,
          templateSlots[index],
          slotModified,
          result
        )) {
      modifiedSlots[index] = slotModified;
      bool anySlotModified = false;
      for (uint8_t modified = 0; modified < ENROLLMENT_POSITION_COUNT; modified++) {
        anySlotModified = anySlotModified || modifiedSlots[modified];
      }
      if (anySlotModified) {
        const bool cleaned = clearFailedEnrollmentProfile(templateSlots, modifiedSlots);
        result += cleaned
          ? "; partial sensor profile cleared"
          : "; WARNING: sensor cleanup incomplete";
      }
      return false;
    }
    modifiedSlots[index] = slotModified;

    const bool hasNextPosition = index + 1 < ENROLLMENT_POSITION_COUNT;
    if (hasNextPosition) {
      const String nextPosition = ENROLLMENT_POSITION_NAMES[index + 1];
      showMessage("NEXT: " + nextPosition, ENROLLMENT_POSITION_DETAILS[index + 1]);
      beepModeChanged();
      serviceSensorDelay(PROMPT_TRANSITION_MS);
    }
  }

  result = "Five thumb positions enrolled and verified for profile "
    + String(canonicalSlot);
  Serial.printf(
    "[ENROLL] SUCCESS: five physical templates saved for employee profile %u.\n",
    canonicalSlot
  );
  return true;
}

// Compact OLED-only formatter. The API and database continue to keep exact
// integer minutes for attendance rules and payroll deductions.
String formatAttendanceMinutes(int totalMinutes) {
  totalMinutes = max(0, totalMinutes);
  if (totalMinutes < 60) {
    return String(totalMinutes) + " min";
  }

  const int hours = totalMinutes / 60;
  const int minutes = totalMinutes % 60;
  String label = String(hours) + (hours == 1 ? " hr" : " hrs");
  if (minutes > 0) label += " " + String(minutes) + " min";
  return label;
}

String attendanceSessionName(const String& rawLabel, int sessionIndex) {
  String label = rawLabel;
  label.trim();
  String normalized = label;
  normalized.toUpperCase();

  if (normalized == "MORNING") return "Morning";
  if (normalized == "AFTERNOON") return "Afternoon";
  if (label.length() > 0) {
    label.toLowerCase();
    String firstLetter = label.substring(0, 1);
    firstLetter.toUpperCase();
    label = firstLetter + label.substring(1);
    return label;
  }
  if (sessionIndex > 0) return String("Session ") + String(sessionIndex);
  return "Attendance";
}

String attendanceActionTitle(const String& sessionName, const String& action) {
  if (sessionName == "Attendance") return action + " SAVED";
  String title = sessionName + " " + action;
  title.toUpperCase();
  return title;
}

String attendanceProgressDetail(
  int punchOrdinal,
  int requiredPunches,
  const String& nextAction,
  const String& attendanceState
) {
  String detail;
  if (punchOrdinal > 0 && requiredPunches > 0) {
    detail = String("Punch ") + String(punchOrdinal) + "/" + String(requiredPunches);
  }

  String next = nextAction;
  next.trim();
  next.toUpperCase();
  if (next.length() == 0 || next == "NONE") {
    String state = attendanceState;
    state.toUpperCase();
    if (state.indexOf("OUT") >= 0) {
      next = "OUT";
    } else if (state.indexOf("IN") >= 0 && state != "INCOMPLETE") {
      next = "IN";
    }
  }

  if (next.length() > 0 && next != "NONE") {
    if (detail.length() > 0) detail += " | ";
    detail += String("Next ") + next;
  }
  if (detail.length() == 0) detail = "Schedule state updated";
  return detail;
}

bool postAttendance(
  int slot,
  const String& requestedEventType,
  const String& commandId,
  const String& punchRequestId,
  bool verifiedTwice,
  String& result,
  String& responseAction
) {
  responseAction = "";
  const bool websiteCommand = commandId.length() > 0;
  const String submittedEventType = "AUTO";
  const String stablePunchRequestId = websiteCommand ? commandId : punchRequestId;
  if (stablePunchRequestId.length() == 0) {
    result = "Attendance request id is missing";
    showMessage("TIME NOT SAVED", result);
    setRgb(90, 0, 0);
    beepFailure();
    return false;
  }

  JsonDocument requestDoc;
  requestDoc["fingerprintSlot"] = slot;
  requestDoc["eventType"] = submittedEventType;
  requestDoc["punchRequestId"] = stablePunchRequestId;
  requestDoc["requestedEventType"] = requestedEventType;
  // This authenticated capture time stays unchanged across HTTP retries. A
  // response lost at a schedule boundary therefore cannot turn the same
  // physical scan into a different attendance action.
  requestDoc["capturedAt"] = (uint32_t) time(nullptr);
  if (websiteCommand) {
    requestDoc["commandId"] = commandId;
    requestDoc["verifiedTwice"] = verifiedTwice;
  }

  String body;
  serializeJson(requestDoc, body);

  Serial.printf("[ATTENDANCE] Posting %s for slot %d%s%s.\n",
    submittedEventType.c_str(),
    slot,
    websiteCommand ? " from web command; requested=" : "",
    websiteCommand ? requestedEventType.c_str() : "");

  int status = 0;
  String response;
  for (uint8_t attempt = 1; attempt <= ATTENDANCE_POST_ATTEMPTS; attempt++) {
    response = signedRequest("POST", "/api/device/attendance.php", body, status);
    if (status > 0 && status < 500) break;

    Serial.printf(
      "[ATTENDANCE] Post attempt %u/%u failed (HTTP %d); request=%s.\n",
      attempt,
      ATTENDANCE_POST_ATTEMPTS,
      status,
      stablePunchRequestId.c_str()
    );
    if (attempt < ATTENDANCE_POST_ATTEMPTS) serviceOperationDelay(500);
  }

  JsonDocument responseDoc;
  const DeserializationError parseError = deserializeJson(responseDoc, response);

  if (status >= 200 && status < 300 && !parseError && (responseDoc["ok"] | false)) {
    const String employee = responseDoc["employeeName"] | "Employee";
    responseAction = responseDoc["action"] | "ATTENDANCE_SAVED";
    const String attendanceStatus = responseDoc["status"] | "";
    const String attendanceState = responseDoc["attendanceState"] | "";
    const String nextAction = responseDoc["nextAction"] | "";
    const String sessionLabel = responseDoc["sessionLabel"] | "";
    const int sessionIndex = responseDoc["sessionIndex"] | 0;
    const int punchOrdinal = responseDoc["punchOrdinal"] | 0;
    const int requiredPunches = responseDoc["requiredPunches"] | 0;
    const int lateMinutes = responseDoc["lateMinutes"] | 0;
    const int undertimeMinutes = responseDoc["undertimeMinutes"] | 0;
    const int approvedOvertimeMinutes = responseDoc["approvedOvertimeMinutes"] | 0;
    result = responseDoc["message"] | "Attendance saved";
    const String sessionName = attendanceSessionName(sessionLabel, sessionIndex);
    const String progressDetail = attendanceProgressDetail(
      punchOrdinal,
      requiredPunches,
      nextAction,
      attendanceState
    );

    if (responseAction == "TIME_IN") {
      String detail = progressDetail;
      if (attendanceStatus == "ABSENT") {
        detail = "Status: Absent";
      } else if (lateMinutes > 0) {
        detail = String("Late ") + formatAttendanceMinutes(lateMinutes) + " | " + progressDetail;
      }
      showMessage(attendanceActionTitle(sessionName, "TIME IN"), detail);
      setRgb(0, 80, 0);
      beepSuccess();
    } else if (responseAction == "TIME_OUT") {
      String detail = progressDetail;
      if (approvedOvertimeMinutes > 0) {
        detail = String("Approved OT ") + formatAttendanceMinutes(approvedOvertimeMinutes);
      } else if (attendanceStatus == "PRESENT" && undertimeMinutes > 0) {
        detail = String("Under ") + formatAttendanceMinutes(undertimeMinutes);
      } else if (attendanceStatus == "HALF_DAY" && undertimeMinutes > 0) {
        detail = String("Half-Day | ") + formatAttendanceMinutes(undertimeMinutes) + " under";
      } else if ((nextAction.length() == 0 || nextAction == "NONE") && attendanceStatus.length() > 0) {
        detail = String("Status: ") + attendanceStatus;
      }
      showMessage(attendanceActionTitle(sessionName, "TIME OUT"), detail);
      setRgb(0, 80, 0);
      beepSuccess();
    } else if (responseAction == "NOT_YET_ELIGIBLE") {
      showMessage("PLEASE WAIT", sessionName + " | " + progressDetail);
      setRgb(70, 45, 0);
      beepModeChanged();
    } else if (responseAction == "BETWEEN_SESSIONS" || attendanceState == "BETWEEN_SESSIONS") {
      showMessage("BETWEEN SESSIONS", sessionName + " | " + progressDetail);
      setRgb(70, 45, 0);
      beepModeChanged();
    } else if (responseAction == "DAY_CLOSED" || attendanceState == "DAY_CLOSED") {
      showMessage("DAY CLOSED", attendanceStatus.length() > 0 ? attendanceStatus : "No more punches today");
      setRgb(70, 45, 0);
      beepModeChanged();
    } else if (responseAction == "ALREADY_RECORDED") {
      showMessage("PUNCH ALREADY SET", sessionName + " | " + progressDetail);
      setRgb(70, 45, 0);
      beepModeChanged();
    } else if (responseAction == "ALREADY_IN") {
      showMessage("ALREADY TIMED IN", sessionName + " | " + progressDetail);
      setRgb(70, 45, 0);
      beepModeChanged();
    } else if (responseAction == "ALREADY_OUT") {
      showMessage("ALREADY TIMED OUT", sessionName + " | " + progressDetail);
      setRgb(70, 45, 0);
      beepModeChanged();
    } else {
      showMessage("ATTENDANCE SAVED", employee);
      setRgb(0, 80, 0);
      beepSuccess();
    }

    Serial.printf(
      "[ATTENDANCE] SUCCESS HTTP %d request=%s action=%s state=%s session=%s punch=%d/%d status=%s late=%d under=%d approvedOT=%d: %s\n",
      status,
      stablePunchRequestId.c_str(),
      responseAction.c_str(),
      attendanceState.c_str(),
      sessionName.c_str(),
      punchOrdinal,
      requiredPunches,
      attendanceStatus.c_str(),
      lateMinutes,
      undertimeMinutes,
      approvedOvertimeMinutes,
      result.c_str()
    );
    return true;
  }

  if (!parseError) {
    result = responseDoc["error"] | "Server rejected attendance";
  } else if (status <= 0) {
    result = "Website connection failed";
  } else {
    result = "Invalid website response";
  }

  showMessage("TIME NOT SAVED", result);
  setRgb(90, 0, 0);
  beepFailure();
  Serial.printf("[ATTENDANCE] FAILED HTTP %d: %s\n", status, result.c_str());
  return false;
}

bool completeCommand(const String& commandId, bool success, const String& message) {
  JsonDocument doc;
  doc["commandId"] = commandId;
  doc["success"] = success;
  doc["message"] = message.substring(0, 220);

  String body;
  serializeJson(doc, body);

  for (uint8_t attempt = 1; attempt <= 3; attempt++) {
    int status;
    const String response = signedRequest("POST", "/api/device/command_result.php", body, status);
    lastPollStatus = status;
    lastApiTest = millis();
    if (status >= 200 && status < 300) {
      Serial.printf("[COMMAND] Result acknowledged (HTTP %d): %s\n", status, message.c_str());
      return true;
    }

    Serial.printf("[COMMAND] Result attempt %u failed (HTTP %d): %s\n",
      attempt, status, response.substring(0, 120).c_str());
    if (attempt < 3) serviceOperationDelay(500);
  }

  Serial.println("[COMMAND] ERROR: website did not acknowledge the command result.");
  return false;
}

void deferCommandResult(
  const String& commandId,
  bool success,
  const String& message
) {
  deferredCommandResult.active = true;
  deferredCommandResult.commandId = commandId;
  deferredCommandResult.success = success;
  deferredCommandResult.message = message.substring(0, 220);
  deferredCommandResult.nextRetryAt = millis() + COMMAND_RESULT_RETRY_INTERVAL_MS;
  Serial.printf(
    "[COMMAND] Result for %s is queued for idempotent retry; no new scan will start.\n",
    commandId.c_str()
  );
}

void retryDeferredCommandResult() {
  if (!deferredCommandResult.active
      || !deadlineReached(millis(), deferredCommandResult.nextRetryAt)) {
    return;
  }

  deferredCommandResult.nextRetryAt = millis() + COMMAND_RESULT_RETRY_INTERVAL_MS;
  if (WiFi.status() != WL_CONNECTED || !clockIsReady()) return;

  Serial.println("[COMMAND] Retrying unsynchronized command result.");
  if (!completeCommand(
        deferredCommandResult.commandId,
        deferredCommandResult.success,
        deferredCommandResult.message
      )) {
    showReady();
    return;
  }

  deferredCommandResult.active = false;
  deferredCommandResult.commandId = "";
  deferredCommandResult.message = "";
  deferredCommandResult.nextRetryAt = 0;
  Serial.println("[COMMAND] Deferred command result synchronized.");
  showMessage("RESULT SAVED", "Terminal ready again");
  beepModeChanged();
  serviceOperationDelay(600);
  showReady();
}

void finishCommandDisplay(bool success, const String& title, const String& result) {
  showMessage(title, result);
  setRgb(success ? 0 : 90, success ? 80 : 0, 0);
  success ? beepSuccess() : beepFailure();
  serviceOperationDelay(RESULT_DISPLAY_MS);
  recognitionFingerReleaseRequired = true;
  String removalResult;
  if (waitForFingerRemoved(2500, removalResult)) {
    showReady();
  } else {
    showMessage("REMOVE FINGER", "Ready after sensor is clear");
    setRgb(70, 45, 0);
  }
}

void clearEnrollmentWorkflow() {
  enrollmentWorkflow.state = EnrollmentWorkflowState::IDLE;
  enrollmentWorkflow.commandId = "";
  enrollmentWorkflow.employeeName = "";
  enrollmentWorkflow.slot = 0;
  for (uint8_t index = 0; index < ENROLLMENT_POSITION_COUNT; index++) {
    enrollmentWorkflow.templateSlots[index] = 0;
  }
  enrollmentWorkflow.mappingVersion = 0;
  enrollmentWorkflow.resultSuccess = false;
  enrollmentWorkflow.resultMessage = "";
  enrollmentWorkflow.nextResultRetryAt = 0;
}

const char* enrollmentWorkflowName() {
  if (deferredCommandResult.active) return "SYNC_COMMAND";
  switch (enrollmentWorkflow.state) {
    case EnrollmentWorkflowState::AWAITING_APPROVAL: return "WAIT_BTN1";
    case EnrollmentWorkflowState::ENROLLING: return "ENROLLING";
    case EnrollmentWorkflowState::AWAITING_RESULT_SYNC: return "SYNC_RESULT";
    default: return "IDLE";
  }
}

bool reportEnrollmentProgress(
  const String& state,
  const String& message,
  uint8_t maxAttempts
) {
  if (enrollmentWorkflow.commandId.length() == 0
      || enrollmentWorkflow.slot == 0
      || enrollmentWorkflow.mappingVersion == 0) {
    return false;
  }

  JsonDocument doc;
  doc["commandId"] = enrollmentWorkflow.commandId;
  doc["state"] = state;
  doc["message"] = message.substring(0, 220);
  doc["fingerprintSlot"] = enrollmentWorkflow.slot;
  doc["mappingVersion"] = enrollmentWorkflow.mappingVersion;

  String body;
  serializeJson(doc, body);

  maxAttempts = max((uint8_t) 1, maxAttempts);
  for (uint8_t attempt = 1; attempt <= maxAttempts; attempt++) {
    int status;
    const String response = signedRequest("POST", "/api/device/command_progress.php", body, status);
    lastPollStatus = status;
    lastApiTest = millis();

    JsonDocument responseDoc;
    const DeserializationError parseError = deserializeJson(responseDoc, response);
    if (status >= 200 && status < 300 && !parseError && (responseDoc["ok"] | false)) {
      Serial.printf("[ENROLL] Progress %s acknowledged (HTTP %d).\n", state.c_str(), status);
      return true;
    }

    Serial.printf("[ENROLL] Progress %s attempt %u failed (HTTP %d): %s\n",
      state.c_str(), attempt, status, response.substring(0, 120).c_str());
    if (attempt < maxAttempts) serviceOperationDelay(350);
  }

  return false;
}

void queueEnrollmentApproval(
  const String& commandId,
  uint16_t slot,
  const uint16_t templateSlots[ENROLLMENT_POSITION_COUNT],
  const String& employeeName,
  uint32_t mappingVersion
) {
  if (enrollmentWorkflow.state == EnrollmentWorkflowState::AWAITING_APPROVAL
      && enrollmentWorkflow.commandId == commandId) {
    bool slotsChanged = enrollmentWorkflow.slot != slot;
    for (uint8_t index = 0; index < ENROLLMENT_POSITION_COUNT; index++) {
      if (enrollmentWorkflow.templateSlots[index] != templateSlots[index]) {
        slotsChanged = true;
      }
    }
    if (slotsChanged || enrollmentWorkflow.mappingVersion != mappingVersion) {
      const String error = "Enrollment command slot/version changed unexpectedly";
      Serial.println("[ENROLL] SECURITY: " + error + ".");
      completeCommand(commandId, false, error);
      clearEnrollmentWorkflow();
      finishCommandDisplay(false, "COMMAND FAILED", error);
    }
    return;
  }

  if (enrollmentWorkflow.state != EnrollmentWorkflowState::IDLE) {
    Serial.printf("[ENROLL] Replacing inactive local command %s with %s.\n",
      enrollmentWorkflow.commandId.c_str(), commandId.c_str());
    clearEnrollmentWorkflow();
  }

  bool validTemplateSlots = slot > 0 && slot <= AS608_MAX_SLOT
    && templateSlots[0] == slot;
  for (uint8_t index = 0; index < ENROLLMENT_POSITION_COUNT; index++) {
    if (templateSlots[index] == 0 || templateSlots[index] > AS608_MAX_SLOT) {
      validTemplateSlots = false;
    }
    for (uint8_t earlier = 0; earlier < index; earlier++) {
      if (templateSlots[index] == templateSlots[earlier]) {
        validTemplateSlots = false;
      }
    }
  }
  if (!validTemplateSlots || mappingVersion == 0) {
    const String error = "Enrollment slot or mapping version is invalid";
    completeCommand(commandId, false, error);
    finishCommandDisplay(false, "COMMAND FAILED", error);
    return;
  }

  enrollmentWorkflow.state = EnrollmentWorkflowState::AWAITING_APPROVAL;
  enrollmentWorkflow.commandId = commandId;
  enrollmentWorkflow.employeeName = employeeName;
  enrollmentWorkflow.slot = slot;
  for (uint8_t index = 0; index < ENROLLMENT_POSITION_COUNT; index++) {
    enrollmentWorkflow.templateSlots[index] = templateSlots[index];
  }
  enrollmentWorkflow.mappingVersion = mappingVersion;

  Serial.printf(
    "[ENROLL] WAITING FOR BTN1: command=%s employee=%s profile=%u slots=%u,%u,%u,%u,%u mappingVersion=%lu. BTN2 cancels.\n",
    commandId.c_str(), employeeName.c_str(), slot,
    templateSlots[0], templateSlots[1], templateSlots[2],
    templateSlots[3], templateSlots[4],
    (unsigned long) mappingVersion
  );
  showReady();
  beep(55, 2, 80, 1850);

  // The command remains Running in MySQL; result_message exposes this physical
  // approval stage to the web UI without adding a fragile extra status enum.
  reportEnrollmentProgress(
    "AWAITING_APPROVAL",
    "Waiting for BTN1 approval at biometric terminal"
  );
}

void finishOrDeferEnrollmentResult(bool success, const String& result) {
  enrollmentWorkflow.resultSuccess = success;
  enrollmentWorkflow.resultMessage = result;

  if (completeCommand(enrollmentWorkflow.commandId, success, result)) {
    const String title = success ? "ENROLLMENT OK" : "ENROLL FAILED";
    clearEnrollmentWorkflow();
    finishCommandDisplay(success, title, result);
    return;
  }

  // Never return to attendance mode while the sensor and database disagree.
  // Keep retrying the idempotent command result until PHP acknowledges it.
  enrollmentWorkflow.state = EnrollmentWorkflowState::AWAITING_RESULT_SYNC;
  enrollmentWorkflow.nextResultRetryAt = millis() + COMMAND_RESULT_RETRY_INTERVAL_MS;
  Serial.println("[ENROLL] Sensor operation finished, but the database result is pending retry.");
  showReady();
}

void approvePendingEnrollment() {
  if (enrollmentWorkflow.state != EnrollmentWorkflowState::AWAITING_APPROVAL) return;

  if (!fingerprintReady) {
    Serial.println("[BUTTON] BTN1 approval paused: AS608 sensor is offline.");
    showReady();
    beepFailure();
    return;
  }

  if (WiFi.status() != WL_CONNECTED || !clockIsReady() || !apiAuthenticated) {
    Serial.println("[BUTTON] BTN1 approval paused: website connection is not ready.");
    showReady();
    beepFailure();
    return;
  }

  // Do not tell PHP that destructive enrollment has started until the sensor
  // glass is definitely clear. This prevents a thumb left from attendance (or
  // placed too early) from producing 0x17 and invalidating a good old mapping.
  showMessage("CHECKING SENSOR", "Remove thumb if present");
  recognitionFingerReleaseRequired = true;
  String clearSensorResult;
  if (!waitForFingerRemoved(4000, clearSensorResult)) {
    Serial.println("[ENROLL] BTN1 paused until the AS608 glass is clear: " + clearSensorResult);
    showMessage("REMOVE THUMB", "Then press BTN1 again");
    setRgb(70, 45, 0);
    beepFailure();
    return;
  }

  showMessage("APPROVING", enrollmentWorkflow.employeeName);
  setRgb(55, 35, 0);
  if (!reportEnrollmentProgress(
        "SCANNING",
        "BTN1 approved; ESP32 is storing five thumb-position templates"
      )) {
    Serial.println("[ENROLL] Approval was not acknowledged; no sensor template was changed.");
    showMessage("APPROVAL PAUSED", "Check website/WiFi");
    beepFailure();
    serviceOperationDelay(1200);
    showReady();
    return;
  }

  Serial.printf("[BUTTON] BTN1 approved enrollment for %s, slot %u, mapping v%lu.\n",
    enrollmentWorkflow.employeeName.c_str(),
    enrollmentWorkflow.slot,
    (unsigned long) enrollmentWorkflow.mappingVersion);

  enrollmentWorkflow.state = EnrollmentWorkflowState::ENROLLING;
  biometricOperationActive = true;
  String result;
  const bool success = enrollFingerprintTemplates(
    enrollmentWorkflow.slot,
    enrollmentWorkflow.templateSlots,
    enrollmentWorkflow.employeeName,
    result
  );
  biometricOperationActive = false;
  finishOrDeferEnrollmentResult(success, result);
}

void cancelPendingEnrollment() {
  if (enrollmentWorkflow.state != EnrollmentWorkflowState::AWAITING_APPROVAL) return;

  const String result = "Enrollment cancelled at biometric terminal by BTN2";
  Serial.printf("[BUTTON] BTN2 cancelled enrollment command %s before scanning.\n",
    enrollmentWorkflow.commandId.c_str());
  finishOrDeferEnrollmentResult(false, result);
}

void retryEnrollmentResultSync() {
  if (enrollmentWorkflow.state != EnrollmentWorkflowState::AWAITING_RESULT_SYNC
      || !deadlineReached(millis(), enrollmentWorkflow.nextResultRetryAt)) {
    return;
  }

  enrollmentWorkflow.nextResultRetryAt = millis() + COMMAND_RESULT_RETRY_INTERVAL_MS;
  if (WiFi.status() != WL_CONNECTED || !clockIsReady()) return;

  Serial.println("[ENROLL] Retrying command-result synchronization.");
  if (!completeCommand(
        enrollmentWorkflow.commandId,
        enrollmentWorkflow.resultSuccess,
        enrollmentWorkflow.resultMessage
      )) {
    showReady();
    return;
  }

  const bool success = enrollmentWorkflow.resultSuccess;
  const String result = enrollmentWorkflow.resultMessage;
  const String title = success ? "ENROLLMENT OK" : "ENROLL FAILED";
  clearEnrollmentWorkflow();
  finishCommandDisplay(success, title, result);
}

void handleVerifyAttendanceCommand(
  const String& commandId,
  uint16_t expectedSlot,
  const String& employeeName,
  String commandEventType
) {
  String result;
  String attendanceAction;
  bool success = false;
  commandEventType.toUpperCase();

  if (!fingerprintReady) {
    result = "AS608 sensor is offline";
  } else if (expectedSlot == 0 || expectedSlot > AS608_MAX_SLOT) {
    result = "Expected fingerprint slot is invalid";
  } else if (commandEventType != "IN" && commandEventType != "OUT") {
    result = "Attendance event must be IN or OUT";
  } else {
    int matchedSlot = 0;
    String verificationResult;
    if (!verifyFingerTwice(expectedSlot, employeeName, false, matchedSlot, verificationResult)) {
      result = verificationResult;
    } else {
      String attendanceResult;
      success = postAttendance(
        matchedSlot,
        commandEventType,
        commandId,
        commandId,
        true,
        attendanceResult,
        attendanceAction
      );
      result = success
        ? "Verified twice. " + attendanceResult
        : "Verified twice, but " + attendanceResult;
    }
  }

  const bool reported = completeCommand(commandId, success, result);
  if (!reported) {
    deferCommandResult(commandId, success, result);
    result += " (saving result when network returns)";
  }
  String resultTitle = "VERIFY FAILED";
  if (success) {
    if (attendanceAction == "ALREADY_IN"
        || attendanceAction == "ALREADY_OUT"
        || attendanceAction == "ALREADY_RECORDED"
    ) {
      resultTitle = "ATTENDANCE EXISTS";
    } else if (attendanceAction == "NOT_YET_ELIGIBLE"
        || attendanceAction == "BETWEEN_SESSIONS"
    ) {
      resultTitle = "ATTENDANCE WAIT";
    } else if (attendanceAction == "DAY_CLOSED") {
      resultTitle = "DAY CLOSED";
    } else if (attendanceAction == "TIME_IN") {
      resultTitle = "TIME IN PENDING";
    } else {
      resultTitle = "VERIFIED & SAVED";
    }
  }
  finishCommandDisplay(success, resultTitle, result);
}

void pollCommand(bool manualRequest = false) {
  pollCount++;
  int status;
  const String response = signedRequest("GET", "/api/device/commands.php", "", status);
  lastPollStatus = status;

  if (status < 200 || status >= 300) {
    // Stop two-second command polling while the website is unavailable. The
    // lighter authenticated ping path will retry after API_RETRY_INTERVAL_MS.
    apiAuthenticated = false;
    lastApiTest = millis();
    Serial.printf("[POLL #%lu] HTTP %d; %s\n",
      (unsigned long) pollCount,
      status,
      response.length() > 0 ? response.substring(0, 140).c_str() : "no response");
    if (manualRequest) {
      showMessage("SYNC FAILED", requestStatusMessage(status));
      setRgb(90, 0, 0);
      beepFailure();
      serviceOperationDelay(1200);
      showReady();
    }
    return;
  }

  JsonDocument doc;
  const DeserializationError parseError = deserializeJson(doc, response);
  if (parseError || !(doc["ok"] | false)) {
    apiAuthenticated = false;
    lastApiTest = millis();
    const String parseMessage = parseError
      ? "Invalid website JSON"
      : String(doc["error"] | "Website response was not OK");
    Serial.printf("[POLL #%lu] Invalid API response: %s\n",
      (unsigned long) pollCount, parseMessage.c_str());
    if (manualRequest) {
      showMessage("SYNC FAILED", parseMessage);
      beepFailure();
      serviceOperationDelay(1200);
      showReady();
    }
    return;
  }

  apiAuthenticated = true;

  if (doc["command"].isNull()) {
    if (enrollmentWorkflow.state == EnrollmentWorkflowState::AWAITING_APPROVAL) {
      Serial.printf("[ENROLL] Command %s is no longer active on the website; local approval cleared.\n",
        enrollmentWorkflow.commandId.c_str());
      clearEnrollmentWorkflow();
      showReady();
    }
    if (manualRequest || pollCount % 5 == 0) {
      Serial.printf("[POLL #%lu] HTTP %d; terminal idle, no command.\n",
        (unsigned long) pollCount, status);
    }
    if (manualRequest) {
      showMessage("SYNC COMPLETE", "No pending command");
      beepModeChanged();
      serviceOperationDelay(900);
      showReady();
    }
    return;
  }

  const String id = doc["command"]["id"].as<String>();
  String type = doc["command"]["type"].as<String>();
  const uint16_t slot = doc["command"]["slot"] | 0;
  uint16_t templateSlots[ENROLLMENT_POSITION_COUNT] = {};
  JsonArray templateSlotArray = doc["command"]["enrollmentSensorSlots"].as<JsonArray>();
  for (uint8_t index = 0; index < ENROLLMENT_POSITION_COUNT; index++) {
    templateSlots[index] = templateSlotArray[index] | 0;
  }
  const uint32_t mappingVersion = doc["command"]["mappingVersion"] | 0U;
  const String employeeName = doc["command"]["employeeName"] | "Employee";
  String commandEventType = doc["command"]["eventType"] | "IN";
  type.toUpperCase();

  const bool repeatedEnrollmentPoll =
    enrollmentWorkflow.state == EnrollmentWorkflowState::AWAITING_APPROVAL
    && enrollmentWorkflow.commandId == id;
  if (!repeatedEnrollmentPoll) {
    Serial.printf(
      "[COMMAND] Received id=%s type=%s slot=%u mappingVersion=%lu employee=%s event=%s.\n",
      id.c_str(), type.c_str(), slot, (unsigned long) mappingVersion,
      employeeName.c_str(), commandEventType.c_str()
    );
  }

  if (id.length() == 0) {
    Serial.println("[COMMAND] Ignored command without an ID.");
    showMessage("COMMAND ERROR", "Missing command ID");
    beepFailure();
    serviceOperationDelay(1200);
    showReady();
    return;
  }

  if (type == "ENROLL" || type == "RE_ENROLL") {
    queueEnrollmentApproval(id, slot, templateSlots, employeeName, mappingVersion);
  } else if (type == "VERIFY_ATTENDANCE") {
    if (enrollmentWorkflow.state == EnrollmentWorkflowState::AWAITING_APPROVAL) {
      Serial.println("[ENROLL] Website replaced the pending enrollment with another command.");
      clearEnrollmentWorkflow();
    }
    biometricOperationActive = true;
    handleVerifyAttendanceCommand(id, slot, employeeName, commandEventType);
    biometricOperationActive = false;
  } else {
    const String error = "Unsupported command: " + type;
    completeCommand(id, false, error);
    finishCommandDisplay(false, "COMMAND FAILED", error);
  }
}

const char* resetReasonName(esp_reset_reason_t reason) {
  switch (reason) {
    case ESP_RST_POWERON: return "POWER_ON";
    case ESP_RST_EXT: return "EXTERNAL_PIN";
    case ESP_RST_SW: return "SOFTWARE";
    case ESP_RST_PANIC: return "PANIC_EXCEPTION";
    case ESP_RST_INT_WDT: return "INTERRUPT_WATCHDOG";
    case ESP_RST_TASK_WDT: return "TASK_WATCHDOG";
    case ESP_RST_WDT: return "OTHER_WATCHDOG";
    case ESP_RST_DEEPSLEEP: return "DEEP_SLEEP";
    case ESP_RST_BROWNOUT: return "BROWNOUT";
    case ESP_RST_SDIO: return "SDIO";
    default: return "UNKNOWN";
  }
}

void printResetDiagnostics() {
  retainedBootCount++;
  const esp_reset_reason_t reason = esp_reset_reason();
  Serial.printf(
    "[BOOT] retainedBootCount=%lu resetReason=%s (%d)\n",
    (unsigned long) retainedBootCount,
    resetReasonName(reason),
    (int) reason
  );

  if (reason == ESP_RST_BROWNOUT) {
    Serial.println("[BOOT] Brownout detected: use a stable 5V supply and common ground.");
  } else if (reason == ESP_RST_PANIC
      || reason == ESP_RST_INT_WDT
      || reason == ESP_RST_TASK_WDT
      || reason == ESP_RST_WDT) {
    Serial.println("[BOOT] Crash/watchdog reset detected; keep the exception text above this banner.");
  }
}

const char* wifiStatusName(wl_status_t status) {
  switch (status) {
    case WL_IDLE_STATUS: return "IDLE";
    case WL_NO_SSID_AVAIL: return "SSID_NOT_FOUND";
    case WL_SCAN_COMPLETED: return "SCAN_COMPLETED";
    case WL_CONNECTED: return "CONNECTED";
    case WL_CONNECT_FAILED: return "AUTH_FAILED";
    case WL_CONNECTION_LOST: return "CONNECTION_LOST";
    case WL_DISCONNECTED: return "DISCONNECTED";
    default: return "UNKNOWN";
  }
}

void beginWifiAttempt() {
  if (WiFi.status() == WL_CONNECTED) return;

  wifiAttemptCount++;
  wifiConnectionState = WifiConnectionState::CONNECTING;
  wifiAttemptStarted = millis();

  Serial.printf(
    "[WIFI] Attempt #%lu: connecting to %s (non-blocking, %lus timeout).\n",
    (unsigned long) wifiAttemptCount,
    WIFI_SSID,
    (unsigned long) (WIFI_CONNECT_TIMEOUT_MS / 1000)
  );
  if (!biometricOperationActive) {
    showMessage("WIFI CONNECTING", "Attempt " + String(wifiAttemptCount));
    setRgb(60, 35, 0);
  }

 
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
}

void markWifiOnline() {
  wifiConnectionState = WifiConnectionState::ONLINE;
  wifiConnectedAt = millis();
  wifiRetryDelayMs = WIFI_RETRY_INITIAL_MS;
  apiAuthenticated = false;

  Serial.print("[WIFI] Connected. ESP32 IP: ");
  Serial.println(WiFi.localIP());
  Serial.printf("[WIFI] RSSI: %ld dBm.\n", (long) WiFi.RSSI());
  Serial.print("[API] Base URL: ");
  Serial.println(configuredApiBaseUrl);

  beginClockSync();
  lastClockRetry = millis();
  // Permit the API authentication check as soon as NTP has supplied a clock.
  lastApiTest = millis() - API_RETRY_INTERVAL_MS;
  if (!biometricOperationActive) showReady();
}

void scheduleWifiRetry(unsigned long now) {
  
  const wl_status_t timedOutStatus = WiFi.status();
  const bool cancelRequested = WiFi.disconnect(false, false);
  wifiConnectionState = WifiConnectionState::IDLE;
  wifiNextAttemptAt = now + wifiRetryDelayMs;
  Serial.printf(
    "[WIFI] Attempt timed out with status=%s. Driver cancel=%s; next attempt in %lus; the ESP32 did not reboot.\n",
    wifiStatusName(timedOutStatus),
    cancelRequested ? "requested" : "already idle",
    (unsigned long) (wifiRetryDelayMs / 1000)
  );
  wifiRetryDelayMs = min(wifiRetryDelayMs * 2UL, WIFI_RETRY_MAX_MS);
  if (!biometricOperationActive) showReady();
}

void serviceWifi() {
  const unsigned long now = millis();
  const wl_status_t status = WiFi.status();

  if (status == WL_CONNECTED) {
    if (wifiConnectionState != WifiConnectionState::ONLINE) markWifiOnline();
    return;
  }

  if (wifiConnectionState == WifiConnectionState::ONLINE) {
    Serial.printf(
      "[WIFI] Link lost after %lus (status=%s); scheduling controlled reconnect.\n",
      (unsigned long) ((now - wifiConnectedAt) / 1000),
      wifiStatusName(status)
    );
    apiAuthenticated = false;
    WiFi.disconnect(false, false);
    wifiConnectionState = WifiConnectionState::IDLE;
    wifiRetryDelayMs = WIFI_RETRY_INITIAL_MS;
    wifiNextAttemptAt = now + WIFI_RETRY_INITIAL_MS;
    if (!biometricOperationActive) showReady();
    return;
  }

  if (wifiConnectionState == WifiConnectionState::CONNECTING) {
    if (intervalElapsed(wifiAttemptStarted, WIFI_CONNECT_TIMEOUT_MS)) {
      scheduleWifiRetry(now);
    }
    return;
  }

  if (deadlineReached(now, wifiNextAttemptAt)) beginWifiAttempt();
}

void beginClockSync() {
  if (WiFi.status() != WL_CONNECTED) return;
  Serial.println("[CLOCK] Requesting NTP time (Asia/Manila UTC+8)...");
  configTime(8 * 3600, 0, "pool.ntp.org", "time.cloudflare.com");
}

void printHeartbeat() {
  const bool wifiOnline = WiFi.status() == WL_CONNECTED;
  Serial.printf(
    "[HEARTBEAT] uptime=%lus sensor=%s wifi=%s rssi=%ld ip=%s clock=%s mode=%s workflow=%s polls=%lu lastHttp=%d heap=%u minHeap=%u maxBlock=%u stackWords=%u\n",
    (unsigned long) (millis() / 1000),
    fingerprintReady ? "OK" : "OFFLINE",
    wifiOnline ? "OK" : "OFFLINE",
    wifiOnline ? (long) WiFi.RSSI() : 0L,
    wifiOnline ? WiFi.localIP().toString().c_str() : "0.0.0.0",
    clockIsReady() ? "OK" : "WAITING",
    eventType.c_str(),
    enrollmentWorkflowName(),
    (unsigned long) pollCount,
    lastPollStatus,
    (unsigned int) ESP.getFreeHeap(),
    (unsigned int) ESP.getMinFreeHeap(),
    (unsigned int) ESP.getMaxAllocHeap(),
    (unsigned int) uxTaskGetStackHighWaterMark(nullptr)
  );
}

void serviceSensorDelay(unsigned long durationMs) {
  // Keep Wi-Fi state and watchdog service alive while the AS608 is polling,
  // without launching a multi-second HTTP ping between image samples.
  const unsigned long started = millis();
  do {
    serviceWifi();
    if (intervalElapsed(lastHeartbeat, HEARTBEAT_INTERVAL_MS)) {
      lastHeartbeat = millis();
      printHeartbeat();
    }
    const unsigned long elapsed = millis() - started;
    if (elapsed >= durationMs) break;
    delay(min(20UL, durationMs - elapsed));
  } while (true);
}

void serviceOperationDelay(unsigned long durationMs) {
  const unsigned long started = millis();
  do {
    serviceWifi();

    if (intervalElapsed(lastHeartbeat, HEARTBEAT_INTERVAL_MS)) {
      lastHeartbeat = millis();
      printHeartbeat();
    }

    // Keep PHP's device_status heartbeat fresh while a user is placing or
    // removing a finger. This is a signed lightweight ping, never a command
    // poll, so an active biometric operation cannot be re-entered.
    if (biometricOperationActive
        && WiFi.status() == WL_CONNECTED
        && clockIsReady()
        && intervalElapsed(lastApiTest, API_RETRY_INTERVAL_MS)) {
      testApiConnection(false);
    }

    const unsigned long elapsed = millis() - started;
    if (elapsed >= durationMs) break;
    delay(min(20UL, durationMs - elapsed));
  } while (true);
}

void handleButtons() {
  if (millis() - lastButtonRead < 40) return;
  lastButtonRead = millis();

  const bool button1Pressed = digitalRead(BUTTON_1) == LOW;
  const bool button2Pressed = digitalRead(BUTTON_2) == LOW;

  if (button1Pressed && !lastButton1Pressed) {
    if (enrollmentWorkflow.state == EnrollmentWorkflowState::AWAITING_APPROVAL) {
      approvePendingEnrollment();
    } else if (deferredCommandResult.active) {
      Serial.println("[BUTTON] BTN1 ignored while a command result is synchronizing.");
      beepModeChanged();
    } else if (enrollmentWorkflow.state == EnrollmentWorkflowState::IDLE) {
      eventType = "IN";
      Serial.println("[BUTTON] BTN1 selected IN; API schedule state remains authoritative.");
      showMessage("MODE: TIME IN", "Schedule check enabled");
      beepModeChanged();
      serviceOperationDelay(900);
      showReady();
    } else {
      Serial.println("[BUTTON] BTN1 ignored while an enrollment result is synchronizing.");
      beepModeChanged();
    }
  }

  if (button2Pressed && !lastButton2Pressed) {
    if (enrollmentWorkflow.state == EnrollmentWorkflowState::AWAITING_APPROVAL) {
      cancelPendingEnrollment();
    } else if (deferredCommandResult.active) {
      Serial.println("[BUTTON] BTN2 ignored while a command result is synchronizing.");
      beepModeChanged();
    } else if (enrollmentWorkflow.state == EnrollmentWorkflowState::IDLE) {
      eventType = "OUT";
      Serial.println("[BUTTON] BTN2 selected OUT; API schedule state remains authoritative.");
      showMessage("MODE: TIME OUT", "Schedule check enabled");
      beepModeChanged();
      serviceOperationDelay(900);
      showReady();
    } else {
      Serial.println("[BUTTON] BTN2 ignored while an enrollment result is synchronizing.");
      beepModeChanged();
    }
  }

  lastButton1Pressed = button1Pressed;
  lastButton2Pressed = button2Pressed;
}

void handleAutonomousAttendance(uint8_t firstImageCode) {
  if (firstImageCode != FINGERPRINT_OK) return;

  // loop() polls website commands before idle sensor sampling. The image is
  // already captured here: a second HTTP command poll would stall image
  // conversion by up to the LAN timeout and make attendance feel unreliable.
  // A command arriving after that poll is handled on the next idle cycle.

  Serial.println("[AUTONOMOUS] Finger detected; two matching scans are required.");
  setRgb(55, 35, 0);

  int matchedSlot = 0;
  String verificationResult;
  if (!verifyFingerTwice(0, "Identify employee", true, matchedSlot, verificationResult)) {
    showMessage("VERIFY FAILED", verificationResult);
    setRgb(90, 0, 0);
    beepFailure();
    serviceOperationDelay(RESULT_DISPLAY_MS);
    recognitionFingerReleaseRequired = true;
    String removalResult;
    if (waitForFingerRemoved(2500, removalResult)) {
      showReady();
    } else {
      showMessage("REMOVE FINGER", "Ready after sensor is clear");
      setRgb(70, 45, 0);
    }
    return;
  }

  String attendanceResult;
  String attendanceAction;
  const String punchRequestId = randomNonce();
  postAttendance(
    matchedSlot,
    eventType,
    "",
    punchRequestId,
    false,
    attendanceResult,
    attendanceAction
  );
  serviceOperationDelay(RESULT_DISPLAY_MS);
  recognitionFingerReleaseRequired = true;
  String removalResult;
  if (waitForFingerRemoved(2500, removalResult)) {
    showReady();
  } else {
    showMessage("REMOVE FINGER", "Ready after sensor is clear");
    setRgb(70, 45, 0);
  }
}

void setup() {
  Serial.begin(115200);
  delay(750);
  Serial.println();
  Serial.println("================================================");
  Serial.println("UCC HR ESP32 biometric terminal starting");
  Serial.println("AS608: TX->GPIO33, RX->GPIO32");
  Serial.println("OLED: SDA->GPIO18, SCL->GPIO19");
  Serial.printf("Firmware: %s; capabilities: %s\n", FIRMWARE_VERSION, DEVICE_CAPABILITIES);
  Serial.println("Website ENROLL waits for BTN1 approval; BTN2 cancels");
  Serial.println("Idle BTN1=IN and BTN2=OUT; API schedule state prevents wrong/duplicate punches");
  Serial.println("Enrollment stores CENTER, LEFT, RIGHT, UPPER, and LOWER templates");
  Serial.println("Every attendance verification requires two scans");
  Serial.println("================================================");
  printResetDiagnostics();

  pinMode(BUZZER, OUTPUT);
  buzzerOff();
  pinMode(BUTTON_1, INPUT_PULLUP);
  pinMode(BUTTON_2, INPUT_PULLUP);
  pinMode(RGB_RED, OUTPUT);
  pinMode(RGB_GREEN, OUTPUT);
  pinMode(RGB_BLUE, OUTPUT);

  Wire.begin(OLED_SDA, OLED_SCL);
  oled.begin();
  showMessage("STARTING", "Checking hardware");

  fingerprintReady = startFingerprintSensor();
  if (!fingerprintReady) {
    showMessage("SENSOR OFFLINE", "Will keep retrying");
    setRgb(90, 0, 0);
    beepFailure();
  }

  initializeApiConfiguration();
  WiFi.mode(WIFI_STA);
  WiFi.persistent(false);
  // The explicit state machine below owns every reconnect attempt. Disabling
  // the SDK's parallel auto-reconnect prevents overlapping WiFi.begin() calls
  // and the ESP-IDF "sta is connecting, cannot set config" error.
  WiFi.setAutoReconnect(false);

  lastCommandPoll = millis() - COMMAND_POLL_INTERVAL_MS;
  lastHeartbeat = millis() - HEARTBEAT_INTERVAL_MS;
  lastClockRetry = millis();
  lastSensorRetry = millis();
  lastApiTest = millis() - API_RETRY_INTERVAL_MS;
  wifiNextAttemptAt = millis();
  showReady();
  serviceWifi();
}

void loop() {
  serviceWifi();
  handleButtons();
  retryEnrollmentResultSync();
  retryDeferredCommandResult();

  if (WiFi.status() == WL_CONNECTED && !clockIsReady()
      && intervalElapsed(lastClockRetry, CLOCK_RETRY_INTERVAL_MS)) {
    lastClockRetry = millis();
    beginClockSync();
    showReady();
  }

  if (WiFi.status() == WL_CONNECTED && clockIsReady() && !apiAuthenticated
      && intervalElapsed(lastApiTest, API_RETRY_INTERVAL_MS)) {
    // Background retries stay quiet and non-blocking apart from the bounded
    // HTTP timeout. Manual sync still displays explicit failures to the user.
    testApiConnection(false);
    showReady();
  }

  if (enrollmentWorkflow.state == EnrollmentWorkflowState::AWAITING_APPROVAL
      && WiFi.status() == WL_CONNECTED && clockIsReady() && apiAuthenticated
      && intervalElapsed(lastApiTest, API_RETRY_INTERVAL_MS)) {
    // Keep the web terminal online without re-fetching the already claimed
    // enrollment command or changing its exact slot/version.
    testApiConnection(false);
    showReady();
  }

  if (!fingerprintReady && intervalElapsed(lastSensorRetry, SENSOR_RETRY_INTERVAL_MS)) {
    lastSensorRetry = millis();
    Serial.println("[AS608] Retrying sensor detection...");
    fingerprintReady = startFingerprintSensor();
    showReady();
  }

  // Poll before autonomous scanning so queued website commands have priority.
  if (WiFi.status() == WL_CONNECTED && clockIsReady() && apiAuthenticated
      && enrollmentWorkflow.state == EnrollmentWorkflowState::IDLE
      && !deferredCommandResult.active
      && intervalElapsed(lastCommandPoll, COMMAND_POLL_INTERVAL_MS)) {
    lastCommandPoll = millis();
    pollCommand(false);
  }

  if (fingerprintReady
      && enrollmentWorkflow.state == EnrollmentWorkflowState::IDLE
      && !deferredCommandResult.active
      && intervalElapsed(lastFingerprintPoll, FINGER_IDLE_POLL_INTERVAL_MS)) {
    lastFingerprintPoll = millis();
    const uint8_t imageCode = finger.getImage();
    if (imageCode == FINGERPRINT_PACKETRECIEVEERR) {
      consecutiveSensorErrors++;
      Serial.printf("[AS608] Idle UART error %u/3.\n", consecutiveSensorErrors);
      if (consecutiveSensorErrors >= 3) {
        markFingerprintOffline("idle scan");
        showReady();
      }
    } else {
      consecutiveSensorErrors = 0;
      if (recognitionFingerReleaseRequired) {
        if (imageCode == FINGERPRINT_NOFINGER) {
          if (recognitionReleaseSamples < 255) recognitionReleaseSamples++;
          if (recognitionReleaseSamples >= ENROLLMENT_RELEASE_STABLE_SAMPLES) {
            recognitionFingerReleaseRequired = false;
            recognitionReleaseSamples = 0;
            Serial.println("[SCAN] Stable finger removal confirmed; terminal re-armed.");
            showReady();
          }
        } else {
          recognitionReleaseSamples = 0;
        }
      } else {
        biometricOperationActive = imageCode == FINGERPRINT_OK;
        handleAutonomousAttendance(imageCode);
        biometricOperationActive = false;
      }
    }
  }

  if (intervalElapsed(lastHeartbeat, HEARTBEAT_INTERVAL_MS)) {
    lastHeartbeat = millis();
    printHeartbeat();
  }

  serviceStandbyClock();
  delay(45);
}
