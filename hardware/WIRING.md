# UCC HR ESP32 Fingerprint Terminal Wiring

Use this wiring for the revised `UCC_HR_ESP32.ino` sketch.

## ESP32 DevKit V1 Pins

| Component | Component pin | ESP32 pin |
| --- | --- | --- |
| Fingerprint module | VCC | 5V or 3V3, depending on your module |
| Fingerprint module | GND | GND |
| Fingerprint module | TX | GPIO 33 |
| Fingerprint module | RX | GPIO 32 |
| OLED 1.3 inch I2C | VCC | 3V3 |
| OLED 1.3 inch I2C | GND | GND |
| OLED 1.3 inch I2C | SDA | GPIO 16 |
| OLED 1.3 inch I2C | SCL | GPIO 17 |
| Push button 1, Time In / approve enrollment | One leg | GPIO 22 |
| Push button 1, Time In / approve enrollment | Other leg | GND |
| Push button 2, Time Out / cancel enrollment | One leg | GPIO 21 |
| Push button 2, Time Out / cancel enrollment | Other leg | GND |
| RGB module | R | GPIO 27 |
| RGB module | G | GPIO 14 |
| RGB module | B | GPIO 26 |
| RGB module | GND/common cathode | GND |
| Buzzer | Signal/+ | GPIO 23 |
| Buzzer | GND/- | GND |

## Notes

- The two buttons use `INPUT_PULLUP`, so the button must connect the GPIO pin to GND when pressed.
- When no enrollment is waiting, Button 1 selects `IN` and Button 2 selects `OUT`. This is a user-facing mode hint; the API still derives the only valid next punch from the assigned schedule and prevents a wrong/duplicate action.
- After the website queues an enrollment or re-enrollment, Button 1 approves that exact employee profile/version and starts five stored thumb templates: CENTER, LEFT, RIGHT, UPPER, and LOWER. Each position is confirmed twice. It does not change attendance mode in this state.
- While enrollment approval is waiting, Button 2 cancels the command before the AS608 template is changed. Automatic website command polling continues every two seconds while idle.
- Both buttons are edge-triggered and debounced. Holding Button 1 cannot approve the same command twice.
- If your RGB module is common anode, the colors will be inverted. In that case, replace `analogWrite(pin, value)` with `analogWrite(pin, 255 - value)` in `setRgb()`.
- If the fingerprint sensor does not respond, swap only the TX/RX wires first: fingerprint TX must go to ESP32 RX, and fingerprint RX must go to ESP32 TX.
- If Serial Monitor reports `resetReason=BROWNOUT`, the reset is electrical rather than a Wi-Fi-code loop. Power the ESP32 from a stable USB/5V source with adequate current, use a sound data cable, and keep the ESP32, AS608, and all modules on a common ground. Do not power a 5V-only AS608 variant from an overloaded 3V3 pin.
- The OLED constructor is for SH1106 128x64. If your OLED is SSD1306, change the display object in the sketch to the matching U8g2 constructor.
- Enrollment requires five guided thumb positions. Keep the center thumb pad on the glass and tilt or move only slightly; scanning only an outer edge can make the AS608 reject a pair. Fully lift after each capture and follow the OLED prompt. Each position uses two captures for one real AS608 template and can retry that position after an unclear or mismatched pair. Website verification and autonomous attendance retain their existing two-scan requirement.
