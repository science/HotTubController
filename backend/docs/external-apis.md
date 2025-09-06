# External API Documentation

This document provides comprehensive documentation for the external APIs used by the hot tub controller system: IFTTT webhooks for equipment control and WirelessTag for temperature monitoring.

## IFTTT Webhook API

### Overview

The IFTTT (If This Then That) webhook API provides simple, reliable control of hot tub equipment through SmartLife automation scenes. This approach abstracts the complex timing and sequencing requirements of hot tub operation into pre-configured automation scenes.

### Authentication

**Method**: API Key in URL
- Simple API key authentication via webhook URL parameter
- Key stored in system configuration (implementation determines storage method)
- No complex authentication flow required

### Base URL Pattern
```
https://maker.ifttt.com/trigger/{event_name}/with/key/{api_key}
```

### Equipment Control Endpoints

#### Hot Tub Heating Control

**Start Heating Sequence**
- **URL**: `https://maker.ifttt.com/trigger/hot-tub-heat-on/with/key/{api_key}`
- **Method**: GET
- **Purpose**: Initiates safe hot tub heating sequence
- **SmartLife Scene Actions**:
  1. Start water circulation pump
  2. Wait for proper water circulation (prevents dry heating)
  3. Activate heating element
- **Request**: No body required
- **Response**: HTTP 200 on success (content not parsed)
- **Timeout**: 30 seconds recommended

**Stop Heating Sequence**
- **URL**: `https://maker.ifttt.com/trigger/hot-tub-heat-off/with/key/{api_key}`
- **Method**: GET
- **Purpose**: Safely shuts down hot tub heating
- **SmartLife Scene Actions**:
  1. Turn off heating element immediately
  2. Continue pump operation to cool heater fins
  3. Stop pump after cooling period
- **Request**: No body required
- **Response**: HTTP 200 on success (content not parsed)
- **Timeout**: 30 seconds recommended

#### Ionizer Control

**Activate Ionizer**
- **URL**: `https://maker.ifttt.com/trigger/turn-on-hot-tub-ionizer/with/key/{api_key}`
- **Method**: GET
- **Purpose**: Starts water ionization system for water treatment

**Deactivate Ionizer**
- **URL**: `https://maker.ifttt.com/trigger/turn-off-hot-tub-ionizer/with/key/{api_key}`
- **Method**: GET
- **Purpose**: Stops water ionization system

### Implementation Characteristics

- **Fire-and-Forget**: No response parsing required
- **Reliability**: IFTTT handles retry logic internally
- **Latency**: Typical response time 2-5 seconds
- **Error Handling**: HTTP status codes indicate success/failure
- **Rate Limiting**: No documented limits, but avoid rapid successive calls

---

## WirelessTag Temperature Monitoring API

### Overview

The WirelessTag API provides wireless temperature sensor data through a cloud-based service. The API is designed to minimize battery drain on remote sensors by caching readings and allowing both cached data retrieval and on-demand sensor activation.

### Authentication

**Method**: OAuth 2.0 Bearer Token (Non-standard implementation)
- Uses Bearer token authentication
- Token acquisition process documented in `wirelesstag-oauth.md`
- Required headers:
  ```
  Authorization: Bearer {oauth_token}
  Content-Type: application/json; charset=utf-8
  ```

### Base URL
```
https://wirelesstag.net/ethClient.asmx/
```

### Core Endpoints

#### GetTagList - Retrieve Cached Sensor Data

**Purpose**: Reads last known temperature data from WirelessTag cloud without activating sensor hardware

- **URL**: `https://wirelesstag.net/ethClient.asmx/GetTagList`
- **Method**: POST
- **Battery Impact**: None (reads cached data only)
- **Use Case**: Regular temperature monitoring, dashboard updates

**Request Format**:
```json
{
  "id": "{device_id}"
}
```

**Response Structure**:
```json
{
  "d": [
    {
      "temperature": 23.5,     // Primary temperature probe (°C)
      "cap": 22.8,            // Ambient air temperature (°C)
      "batteryVolt": 3.1,     // Battery voltage
      "signaldBm": -65,       // Signal strength
      "uuid": "device-uuid",   // Device identifier
      // ... additional sensor fields
    }
  ]
}
```

**Response Field Details**:
- **`d`**: Array of device data (index corresponds to device order in account)
- **`temperature`**: Main temperature probe reading in Celsius
  - This is the primary water/surface temperature measurement
  - Most accurate reading for heating control decisions
- **`cap`**: Capacitive ambient temperature sensor reading in Celsius
  - Measures air temperature around the sensor unit itself
  - Useful for environmental context and calibration
  - May be affected by proximity to heated surfaces
- **`batteryVolt`**: Current battery voltage (informational)
- **`signaldBm`**: Wireless signal strength (informational)

#### RequestImmediatePostback - Force Fresh Reading

**Purpose**: Activates sensor hardware to take new temperature measurement

- **URL**: `https://wirelesstag.net/ethClient.asmx/RequestImmediatePostback`
- **Method**: POST
- **Battery Impact**: High (forces sensor to wake up and measure)
- **Use Case**: Critical temperature checks before heating decisions

**Request Format**:
```json
{
  "id": "{device_id}"
}
```

**Response**: 
- Simple acknowledgment (content varies)
- Does not contain sensor data

**Usage Pattern**:
1. Call `RequestImmediatePostback` to trigger measurement
2. Wait 2-3 seconds for sensor to complete reading
3. Call `GetTagList` to retrieve the fresh data

### Temperature Data Processing

#### Temperature Conversion
All WirelessTag temperatures are provided in Celsius:
```php
$fahrenheit = ($celsius * 1.8) + 32;
```

#### Multi-Device Data Access
When multiple sensors are configured:
- Hot tub sensor: `response.d[0].temperature` (device index 0)
- Ambient sensor: `response.d[1].temperature` (device index 1)
- Device order corresponds to account configuration

#### Ambient Temperature Calibration
Based on analysis of existing implementation, ambient readings may need calibration when sensors are positioned near heated surfaces:

```php
// Calibration formula observed in existing system
$calibration_offset = ($ambient_temp - $water_temp) * 0.15;
$calibrated_ambient = $ambient_temp + $calibration_offset;
```

This compensates for thermal influence of hot water on ambient temperature sensors.

### Error Handling and Reliability

#### HTTP Response Codes
- **200**: Success
- **401**: Authentication failure (token expired/invalid)
- **403**: Access denied
- **404**: Device not found
- **5xx**: Service unavailable

#### Retry Logic
Implement exponential backoff for failed requests:
```
Attempt 1: Immediate
Attempt 2: 30 seconds
Attempt 3: 60 seconds
...
Maximum: 8 attempts
```

#### Timeout Configuration
- **Connection timeout**: 12 seconds
- **Read timeout**: 30 seconds
- **Maximum retries**: 8 attempts

#### Battery Conservation Strategy
- Use `GetTagList` for routine monitoring (every 2-5 minutes)
- Use `RequestImmediatePostback` only for critical decisions:
  - Before starting heating cycle
  - When target temperature is near
  - During emergency shutoff scenarios
- Avoid rapid successive calls to `RequestImmediatePostback`

### Device Configuration

#### Device ID Management
- Each WirelessTag sensor has a unique device ID
- Device IDs are strings (not integers)
- System must track multiple device IDs:
  - Hot tub water temperature sensor
  - Ambient/environmental temperature sensor
  - Additional sensors as needed

#### Example Configuration Structure
```json
{
  "wirelesstag": {
    "devices": {
      "hot_tub_water": "device-uuid-1",
      "ambient_temp": "device-uuid-2"
    },
    "oauth_token": "bearer-token",
    "polling_interval": 300
  }
}
```

## Integration Considerations

### API Call Frequency
- **IFTTT**: As needed (fire-and-forget operations)
- **WirelessTag Cached**: Every 2-5 minutes for monitoring
- **WirelessTag Fresh**: Only for critical decisions

### Failure Scenarios
- **IFTTT Unavailable**: Manual override controls required
- **WirelessTag Unavailable**: Use last known values with age warnings
- **Authentication Failure**: Token refresh required

### Performance Characteristics
- **IFTTT Response Time**: 2-5 seconds
- **WirelessTag Cached**: 1-2 seconds
- **WirelessTag Fresh**: 5-10 seconds (includes sensor wake time)

### Security Considerations
- Store API keys securely (encrypted configuration)
- Implement token refresh for WirelessTag OAuth
- Log API failures for monitoring without exposing credentials
- Validate response data before use in control decisions