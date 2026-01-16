# Hot Tub Controller

A web-based automation system for intelligent hot tub temperature management and equipment control. Control your hot tub heater, pump, and ionizer remotely via a mobile-friendly web interface with scheduling, temperature monitoring, and safety features. 

## Why Use This?

If you have a hot tub without built-in smart controls, this project lets you:

- **Turn heating on/off remotely** - No more walking outside in the cold to check if the tub is ready
- **Schedule heating in advance** - Wake up to a hot tub at the perfect temperature
- **Monitor water temperature** - Check current temperature from anywhere
- **Automate heating cycles** - Schedule heater on, then auto-off after a set duration
- **Run the circulation pump** - Keep water clean with scheduled ionizer/pump cycles

The system uses IFTTT webhooks to control SmartLife/Tuya smart relays, making it compatible with most affordable smart home equipment.

## System Architecture

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  Web Browser    │───▶│   PHP Backend   │───▶│  IFTTT Webhooks │
│  (SvelteKit)    │◀───│   REST API      │     └────────┬────────┘
└─────────────────┘     └────────┬────────┘              │
                                 │                        ▼
                                 │              ┌─────────────────┐
                                 │              │ SmartLife/Tuya  │
                        ┌────────▼────────┐     │ Smart Relays    │
                        │  ESP32 Sensor   │     └────────┬────────┘
                        │  (DS18B20)      │              │
                        └─────────────────┘              ▼
                                                ┌─────────────────┐
                                                │   Hot Tub       │
                                                │   Equipment     │
                                                └─────────────────┘
```

## Features

- **Mobile-First Web UI** - Dark-themed, responsive interface optimized for phones
- **One-Tap Controls** - Quick buttons for heater on/off and pump activation
- **Quick Scheduling** - Pre-configured buttons to heat "In 30 min", "In 1 hour", etc.
- **Full Scheduler** - Create one-time or recurring heating schedules
- **Auto Heat-Off** - Automatically schedule heater shutdown after configurable duration
- **Temperature Display** - Real-time water and ambient temperature from WirelessTag sensors
- **Equipment Status Display** - Control buttons illuminate when equipment is active
- **User Authentication** - JWT-based login with three user roles (admin/user/basic)
- **User Management** - Admin interface for creating and managing users
- **Optional Monitoring** - Healthchecks.io integration for cron job alerts
- **Comprehensive Logging** - Request logging with automatic rotation
- **Safety First** - Stub mode for testing without triggering real hardware

## Hardware Requirements

### Temperature Monitoring

**Option 1: ESP32 with DS18B20 (Recommended)**
- **ESP32 Development Board** - WiFi-enabled microcontroller
  - ESP32-WROOM-32 based board (NodeMCU, DevKit, etc.) uch as [this one](https://www.amazon.com/dp/B0DNFN7FHD).
- **DS18B20 Temperature Sensor** - Waterproof digital thermometer
  - 1-Wire interface, accurate to 0.5C
  - 4.7K pull-up resistor required (connect between data and VCC) such as [this one](https://www.amazon.com/dp/B08V93CTM2)
- **PlatformIO** - For firmware development and flashing
  - See `esp32/` directory for firmware code

**Option 2: WirelessTag Cloud Sensors**
- **WirelessTag Outdoor Probe** - DS18B20 sensor for accurate water temperature
  - Product: [WirelessTag Outdoor Probe Basic](https://store.wirelesstag.net/products/outdoor-probe-basic)
- **WirelessTag Ethernet Manager** - Bridge device connecting sensors to cloud
  - Product: [Ethernet Tag Manager](https://store.wirelesstag.net/products/ethernet-tag-manager)
- **WirelessTag OAuth API Key** - Account authentication for temperature data
  - See: [WirelessTag OAuth Setup](https://groups.google.com/g/wireless-sensor-tags/c/YJ0lXGJUnkY/m/RNMqU1eJAQAJ)

### Equipment Control

- **Smart Relay Controller** - IFTTT-compatible device for pump and heater control
  - Compatible: SmartLife or Tuya-based smart switches/relays such as [this one](https://www.amazon.com/dp/B08M3B1TZW)
  - Example: 4-channel WiFi relay modules with smartphone app
- **IFTTT Account** - Free tier works fine for webhook integrations
  - Sign up: [IFTTT.com](https://ifttt.com)

## IFTTT Webhook Setup

The system requires IFTTT webhook events connected to SmartLife/Tuya "scenes". Since IFTTT cannot directly control individual SmartLife switches, you create scenes that IFTTT can trigger. 

If you use other smart home control systems, they will work fine as long as IFTTT can trigger the appropriate events associated with the Webhooks described below.

### Required Webhooks

| Webhook Event | Purpose | SmartLife Scene |
|---------------|---------|-----------------|
| `hot-tub-heat-on` | Start heating | Turn on pump, wait 60s, turn on heater |
| `hot-tub-heat-off` | Stop heating | Turn off heater, wait 90s, turn off pump |
| `cycle_hot_tub_ionizer` | Run pump/ionizer | Turn on pump for 2 hours |

### SmartLife Scene Configuration

**Heat On Scene (`hot-tub-heat-on`):**
```
1. Turn ON hot tub water pump
2. Wait 60 seconds (allows water circulation)
3. Turn ON hot tub heater
```

**Heat Off Scene (`hot-tub-heat-off`):**
```
1. Turn OFF hot tub heater
2. Wait 90 seconds (cooling circulation)
3. Turn OFF hot tub water pump
```

This sequencing protects heating elements by ensuring proper water flow during operation.

### Creating the IFTTT Applets

1. Go to [IFTTT Create](https://ifttt.com/create)
2. **If This**: Choose "Webhooks" → "Receive a web request"
3. **Event Name**: Enter exactly `hot-tub-heat-on` (case-sensitive)
4. **Then That**: Choose your controller system such as "SmartLife" → "Activate scene"
5. **Scene**: Select your "Heat On" scene or however your backend controller works
6. Save and repeat for other webhooks

Get your webhook key from: https://ifttt.com/maker_webhooks/settings

## Quick Start

### Backend Setup

```bash
cd backend
composer install

# Copy development config (uses stub mode - no real hardware triggers)
cp config/env.development .env

# For production, use:
# cp config/env.production.example .env
# Then edit .env to add your real API keys

# Start development server
php -S localhost:8080 -t public
```

### Frontend Setup

```bash
cd frontend
npm install
npm run dev    # Starts on http://localhost:5173
```

Default login: `admin` / `password` (change in production!)

### ESP32 Firmware Setup (Optional)

If using an ESP32 for temperature sensing:

```bash
cd esp32

# Create .env file with your WiFi and API credentials
cat > .env << 'EOF'
WIFI_SSID=your-wifi-network
WIFI_PASSWORD=your-wifi-password
ESP32_API_KEY=your-secure-api-key
API_ENDPOINT=http://your-server.com/api/esp32/temperature
EOF

# Install PlatformIO (if not already installed)
# pip install platformio

# Build and upload firmware
pio run --target upload

# Monitor serial output
pio device monitor
```

The ESP32 will:
- Connect to WiFi and read temperature from DS18B20 sensor (GPIO 4)
- POST temperature readings to the backend API every 5 minutes
- Use exponential backoff on failures (10s to 5 min)
- Auto-reboot after 30 minutes of continuous failures

Hardware wiring:
- DS18B20 VCC to ESP32 3.3V
- DS18B20 GND to ESP32 GND
- DS18B20 DATA to ESP32 GPIO 4
- 4.7K resistor between DATA and VCC

## Configuration

All configuration is via `backend/.env`:

```bash
# Application mode
APP_ENV=development

# External API Mode - controls ALL external services (IFTTT, WirelessTag, Healthchecks.io)
EXTERNAL_API_MODE=stub       # stub (safe) or live (requires keys)

# IFTTT webhook integration
IFTTT_WEBHOOK_KEY=your-key   # From IFTTT Maker Webhooks settings

# ESP32 temperature sensor
ESP32_API_KEY=your-secure-api-key        # Secure API key for ESP32 authentication

# WirelessTag temperature sensors (alternative)
WIRELESSTAG_OAUTH_TOKEN=your-token       # OAuth token from WirelessTag
WIRELESSTAG_DEVICE_ID=0                  # Your hot tub sensor device ID

# Healthchecks.io monitoring (optional)
HEALTHCHECKS_IO_KEY=your-key             # API key for cron job monitoring
HEALTHCHECKS_IO_CHANNEL=your-channel     # Notification channel slug

# Authentication
AUTH_ADMIN_USERNAME=admin
AUTH_ADMIN_PASSWORD=change-this!
JWT_SECRET=generate-a-secure-random-string

# API Base URL - Required for scheduled jobs (used by cron)
API_BASE_URL=https://your-server.com/path/to/backend/public
```

### External API Mode

| Mode | Description |
|------|-------------|
| `stub` | Simulates all API calls - safe for development/testing |
| `live` | Makes real API calls to IFTTT, WirelessTag, and Healthchecks.io |

The unified `EXTERNAL_API_MODE` defaults to `stub` for fail-safe behavior.

### User Roles

| Role | Capabilities |
|------|--------------|
| `admin` | Full access: equipment control, scheduling, settings, user management |
| `user` | Standard access: equipment control, scheduling, settings |
| `basic` | Simplified UI: equipment control and temperature display only |

The `basic` role is useful for household members who just need to turn the hot tub on/off without the complexity of scheduling features.

## Running Tests

```bash
# Backend tests (excludes live API tests)
cd backend && composer test

# All backend tests including live API tests
cd backend && composer test:all

# Frontend unit tests
cd frontend && npm test

# End-to-end tests (starts servers automatically)
cd frontend && npm run test:e2e
```

## Project Structure

```
hot-tub-controller/
├── backend/                 # PHP REST API
│   ├── public/              # Web root (index.php entry point)
│   ├── src/
│   │   ├── Controllers/     # API endpoint handlers
│   │   ├── Services/        # Business logic, external API clients
│   │   ├── Middleware/      # Auth, CORS
│   │   └── Routing/         # URL router
│   ├── storage/             # Job files, logs, user data
│   └── tests/               # PHPUnit tests
│
├── frontend/                # SvelteKit web UI
│   ├── src/
│   │   ├── lib/components/  # UI components
│   │   ├── lib/stores/      # State management
│   │   └── routes/          # Pages
│   └── e2e/                 # Playwright E2E tests
│
├── esp32/                   # ESP32 firmware (PlatformIO)
│   ├── src/                 # Main firmware code
│   ├── lib/                 # ApiClient library
│   └── test/                # Unity unit tests
│
└── CLAUDE.md                # Development guidelines
```

## API Endpoints

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/health` | No | Health check with equipment status |
| POST | `/api/auth/login` | No | Login |
| POST | `/api/auth/logout` | No | Logout |
| GET | `/api/auth/me` | Yes | Current user info |
| POST | `/api/equipment/heater/on` | Yes | Turn heater on |
| POST | `/api/equipment/heater/off` | Yes | Turn heater off |
| POST | `/api/equipment/pump/run` | Yes | Run pump (2 hours) |
| GET | `/api/temperature` | Yes | Get current temperatures |
| POST | `/api/esp32/temperature` | API Key | Receive temperature from ESP32 |
| POST | `/api/schedule` | Yes | Schedule a job |
| GET | `/api/schedule` | Yes | List scheduled jobs |
| DELETE | `/api/schedule/{id}` | Yes | Cancel a job |
| GET | `/api/users` | Admin | List users |
| POST | `/api/users` | Admin | Create user |
| DELETE | `/api/users/{username}` | Admin | Delete user |
| POST | `/api/maintenance/logs/rotate` | Cron | Rotate log files |

## Safety Considerations

This system controls real electrical equipment. Safety features include:

- **Test Mode**: Always develop with `EXTERNAL_API_MODE=stub`
- **Equipment Sequencing**: Pump starts before heater, heater stops before pump
- **Authentication Required**: All control endpoints require login
- **Audit Logging**: All API requests are logged with timestamps

Never deploy to production without:
1. Changing default passwords
2. Setting a secure JWT_SECRET
3. Configuring HTTPS
4. Testing equipment sequencing with your actual setup

## Deployment Notes
The system is designed to run on "low cost web hosting services" such as cPanel hosts. The main requirement is that your host must allow cron job scheduling (and scheduled crons must execute reliable). Otherwise it uses only basic http APIs between front and backend, and between backend. And calls to IFTTT and Wirelesstag are similarly basic. The ESP32 thermometer integration is "receive only" - so the ESP32 device sends temperature on a schedule, which the backend can alter whenever the device phones in (to increase or decrease the frequency of reporting). 

### FTP Deploy Action Tool for FTP+SSL - Thanks Sam!
Due to the low cost host, "real" deployment techniques may not be available. Currently I'm deploying this to my host via FTP+SSL in a Github Action, which is working perfectly thanks to this free tool:

[<img alt="Website Deployed for Free with FTP Deploy Action" src="https://img.shields.io/badge/Website deployed for free with-FTP DEPLOY ACTION-%3CCOLOR%3E?style=for-the-badge&color=d00000">](https://github.com/SamKirkland/FTP-Deploy-Action)



## Logging & Monitoring

### Request Logging

All API requests are logged in JSON Lines format to `backend/storage/logs/api-requests-*.jsonl`:
- Timestamp, IP address, HTTP method, URI
- Response status and duration
- Authenticated username (if applicable)

### Log Rotation

Automated log rotation runs monthly via cron:
- Compress logs older than 30 days
- Delete compressed logs older than 6 months
- Crontab backups follow the same retention policy

### Healthchecks.io Integration (Optional)

When `HEALTHCHECKS_IO_KEY` is configured, scheduled jobs are monitored:
- Health check created when job is scheduled
- Alert triggered if job fails to execute within timeout
- Check deleted on successful completion

This provides proactive notification if a scheduled heating job fails to fire.

## Contributing

This project uses Test-Driven Development (TDD):

1. **RED**: Write a failing test first
2. **GREEN**: Write minimal code to pass
3. **REFACTOR**: Clean up while tests stay green

See `CLAUDE.md` for detailed development guidelines.

## License

Licensed under the Apache License, Version 2.0.

## Author

Stephen Midgley
