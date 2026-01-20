# ESP32 Hardware Notes

Reference information for the ESP32 hot tub controller hardware.

## ESP32 Device

- **IP Address**: 10.21.11.148 (DHCP, may change)
- **Telnet Debug**: `telnet 10.21.11.148 23`
- **Telnet Commands**: `help`, `diag`, `scan`, `read`, `info`

## DS18B20 Temperature Probes

| Address | Location | Notes |
|---------|----------|-------|
| `28:B4:51:02:00:00:00:9A` | Hot tub water | Sealed in project box (production) |
| `28:F6:DD:87:00:88:1E:E8` | Test probe | Used as water role in dev |
| `28:D5:AA:87:00:23:16:34` | Test probe | Used as ambient role in dev |

## Sensor Role Configuration

Sensor roles are configured via the admin UI at `/api/esp32/sensors`. Each sensor can be assigned:
- `water` - Primary hot tub water temperature
- `ambient` - Outdoor/ambient air temperature
- `null` - Unassigned/ignored

The calibration offset (in Fahrenheit) can also be set per-sensor to compensate for probe inaccuracies.
