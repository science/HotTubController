# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Hot tub controller system with a PHP backend API, SvelteKit frontend, and ESP32 temperature sensor. Controls real hardware via IFTTT webhooks (heater, pump, ionizer) and monitors temperature via ESP32 with DS18B20 probes.

## Project Structure

```
backend/           # PHP API (PHPUnit tests)
frontend/          # SvelteKit + TypeScript + Tailwind (Vitest unit tests, Playwright E2E)
esp32/             # ESP32 firmware (PlatformIO, Unity tests)
scripts/           # Test and build automation scripts
```

## Running Tests

### Unified Test Script (REQUIRED)

**Always use the unified test script to run tests.** It handles environment setup, port cleanup, and configuration automatically. **Claude Code must always use `./scripts/test.sh` (not manual commands like `npm run test:e2e` or `php vendor/bin/phpunit`) to run tests, especially E2E tests which require backend setup.**

```bash
./scripts/test.sh              # Run ALL tests (setup + backend + frontend + esp32 + e2e)
./scripts/test.sh e2e          # Run only E2E tests
./scripts/test.sh backend      # Run only backend tests
./scripts/test.sh frontend     # Run only frontend unit tests
./scripts/test.sh esp32        # Run only ESP32 tests
./scripts/test.sh status       # Check if environment is ready for testing
./scripts/test.sh setup        # Configure environment for testing
./scripts/test.sh cleanup      # Kill leftover processes on ports 8080/5173
```

**What the script does automatically:**
1. Copies `backend/config/env.testing` → `backend/.env` (configures `JWT_SECRET`, `EXTERNAL_API_MODE=stub`)
2. Kills leftover processes on test ports (8080, 5173)
3. Runs the requested test suite(s)
4. Cleans up after tests complete

**Common issues the script prevents:**
- `JWT_SECRET` empty → "Key material must not be empty" error
- `EXTERNAL_API_MODE=live` → Tests hit real external APIs
- Port 8080/5173 in use → Playwright can't start servers

### Pre-PR Testing Checklist

**Before creating a PR to production:**

```bash
./scripts/test.sh              # Runs all test suites
```

This runs:
- Backend PHPUnit tests (685 tests)
- Frontend Vitest unit tests (150 tests)
- ESP32 Unity native tests (58 tests)
- Playwright E2E tests (104 tests)

### Manual Test Commands (Reference Only)

If you need to run tests manually (not recommended), here are the individual commands:

**Backend (PHP):**
```bash
cd backend
php vendor/bin/phpunit                  # Run tests (requires .env configured)
php vendor/bin/phpunit --filter=testName  # Run single test
```

**Frontend (SvelteKit):**
```bash
cd frontend
npm run test             # Run Vitest unit tests
npm run test:watch       # Unit tests in watch mode
npm run test:e2e         # Run Playwright E2E tests (requires backend .env configured)
npm run test:e2e:ui      # E2E tests with interactive UI
```

**ESP32 (PlatformIO):**
```bash
cd esp32
pio test -e native              # Run native unit tests (runs on host machine)
pio test                        # Run embedded unit tests (requires device)
pio test -e hardware_test       # Run ALL tests including hardware integration
```

### E2E Test Details

Playwright tests in `frontend/e2e/` test frontend-backend integration:
- Auto-starts PHP backend on port 8080
- Auto-starts Vite frontend on port 5174
- Frontend served at `/tub` base path
- Tests run against Chromium by default

**Test data setup:**
- `global-setup.ts` creates ESP32 test data and resets heat-target settings
- `global-teardown.ts` cleans up test user accounts

### Test Artifact Cleanup

Tests create artifacts that are automatically cleaned up:

**E2E Tests (Users):**
- `global-setup.ts` cleans stale test users BEFORE tests run
- `global-teardown.ts` cleans test users AFTER tests run
- Patterns cleaned: `testuser_*`, `deletetest_*`, `basic_e2e_test`, `testuser_heat_settings_*`

**PHPUnit Tests (Healthchecks):**
- Each test's `tearDown()` deletes checks it created
- Patterns cleaned: `poc-test-*`, `live-test-*`, `workflow-test-*`, `channel-test-*`

## Build Commands

### Backend
```bash
cd backend
php -S localhost:8080 -t public         # Start dev server
```

### Frontend
```bash
cd frontend
npm run dev              # Start dev server (port 5173)
npm run build            # Production build
npm run check            # TypeScript/Svelte type checking
```

### ESP32
```bash
cd esp32
pio run                          # Build firmware
pio run -t upload                # Build and upload to device
pio device monitor               # Serial monitor
```

**ESP32 Configuration:**
The ESP32 uses build-time secrets injection via `load_env.py`. Create `esp32/.env`:
```bash
WIFI_SSID=your-wifi-name
WIFI_PASSWORD=your-wifi-password
API_ENDPOINT=http://your-server/backend/public/api/esp32/temperature
ESP32_API_KEY=your-api-key
```

**ESP32 Remote Access:**
- **Telnet debugger**: `telnet <esp32-ip> 23` - commands: `diag`, `read`, `info`, `scan`
- **HTTP OTA updates**: Automatic via API response; deploy new firmware by updating `backend/storage/firmware/`

## Architecture

### Backend
- **Entry point**: `public/index.php` - Router setup and dependency wiring
- **Routing**: `src/Routing/Router.php` - Simple router with `{param}` pattern support
- **Controllers**:
  - `EquipmentController` - Heater/pump control via IFTTT, tracks equipment state
  - `ScheduleController` - Scheduled job management via crontab
  - `AuthController` - JWT-based authentication
  - `UserController` - User management (admin only)
  - `HeatTargetSettingsController` - Global heat-to-target settings (admin only)
  - `MaintenanceController` - Log rotation endpoint for cron
  - `TemperatureController` - Temperature readings from ESP32
  - `Esp32TemperatureController` - Receives temperature data from ESP32
  - `Esp32SensorConfigController` - Sensor role assignment and calibration
- **Services**:
  - `EnvLoader` - File-based `.env` configuration loading
  - `CronSchedulingService` - **Centralized cron scheduling with correct timezone handling** (see DRY Principles)
  - `SchedulerService` - Creates/lists/cancels cron jobs with Healthchecks.io monitoring
  - `TargetTemperatureService` - Heat-to-target feature with automatic cron-based temperature checks
  - `HeatTargetSettingsService` - Stores global heat-to-target enabled/target_temp settings
  - `AuthService` - JWT token validation
  - `EquipmentStatusService` - Tracks heater/pump on/off state in JSON file
  - `RequestLogger` - API request logging in JSON Lines format
  - `LogRotationService` - Compresses and deletes old log files
  - `CrontabBackupService` - Timestamped backups before crontab modifications
  - `MaintenanceCronService` - Sets up monthly log rotation cron job
  - `TimeConverter` - Timezone conversion between UTC, client offset, and system timezone
  - `Esp32TemperatureService` - Stores ESP32 temperature readings
  - `Esp32SensorConfigService` - Manages sensor roles and calibration offsets
  - `Esp32CalibratedTemperatureService` - Applies calibration to raw ESP32 readings
  - `Esp32FirmwareService` - Manages firmware versions for HTTP OTA updates
  - `Esp32ThinHandler` - Lightweight handler for ESP32 API (bypasses full framework)
- **IFTTT Client Pattern**: Uses interface (`IftttClientInterface`) with unified client:
  - `IftttClient` - Unified client with injectable HTTP layer
  - `StubHttpClient` - Simulates API calls (safe for testing)
  - `CurlHttpClient` - Makes real IFTTT webhook calls
- **Factory**: `IftttClientFactory` - Creates client based on EXTERNAL_API_MODE
- **Healthchecks.io Client**: Optional monitoring integration:
  - `HealthchecksClient` - Real API calls to Healthchecks.io
  - `NullHealthchecksClient` - No-op client (stub mode or no API key)
- **Factory**: `HealthchecksClientFactory` - Creates client based on EXTERNAL_API_MODE

### Frontend
- **Framework**: SvelteKit with Svelte 5 runes (`$state`, `$effect`)
- **Styling**: Tailwind CSS v4
- **API client**: `src/lib/api.ts` - Typed wrapper for all backend endpoints
- **Auth**: `src/lib/stores/auth.svelte.ts` - Reactive auth state with httpOnly cookie support
- **Stores**:
  - `auth.svelte.ts` - Authentication state with role-based access
  - `equipmentStatus.svelte.ts` - Equipment on/off state with auto-refresh
  - `heatTargetSettings.svelte.ts` - Global heat-to-target settings from server
- **Components**:
  - `CompactControlButton.svelte` - Equipment control buttons with active glow state
  - `EquipmentStatusBar.svelte` - Last update time and manual refresh button
  - `SchedulePanel.svelte` - Scheduled jobs list with auto-refresh
  - `QuickSchedulePanel.svelte` - Quick scheduling UI
  - `TemperaturePanel.svelte` - Water/ambient temperature display (ESP32 only)
  - `SettingsPanel.svelte` - User settings and admin heat-target configuration
  - `SensorConfigPanel.svelte` - ESP32 sensor role/calibration configuration (admin only)

### API Endpoints
- `GET /api/health` - Health check with equipment status and heat-target settings
- `POST /api/auth/login` - Login (sets httpOnly cookie)
- `POST /api/auth/logout` - Logout (clears cookie)
- `GET /api/auth/me` - Get current user info
- `POST /api/equipment/heater/on` - Trigger IFTTT `hot-tub-heat-on` (auth required)
- `POST /api/equipment/heater/off` - Trigger IFTTT `hot-tub-heat-off` (auth required)
- `POST /api/equipment/pump/run` - Trigger IFTTT `cycle_hot_tub_ionizer` (auth required)
- `GET /api/temperature` - Get current temperatures from ESP32
- `POST /api/esp32/temperature` - Receive temperature data from ESP32 (API key auth)
- `GET /api/esp32/sensors` - List ESP32 sensors with config (admin only)
- `PUT /api/esp32/sensors/{address}` - Update sensor role/calibration (admin only)
- `GET /api/settings/heat-target` - Get heat-to-target settings (auth required)
- `PUT /api/settings/heat-target` - Update heat-to-target settings (admin only)
- `POST /api/schedule` - Schedule a future action (auth required)
- `GET /api/schedule` - List scheduled jobs (auth required)
- `DELETE /api/schedule/{id}` - Cancel scheduled job (auth required)
- `GET /api/users` - List users (admin only)
- `POST /api/users` - Create user (admin only)
- `DELETE /api/users/{username}` - Delete user (admin only)
- `POST /api/maintenance/logs/rotate` - Rotate log files (cron auth)

## DRY Principles

### General Guidance

When implementing features that involve system-level operations (cron, timezones, external APIs), always check if a centralized service already exists. Duplicating this logic leads to subtle bugs.

**Before writing new code, check for existing services:**
- Timezone conversion → `TimeConverter`
- Cron scheduling → `CronSchedulingService`
- External API calls → Use existing client interfaces (`IftttClientInterface`, `HealthchecksClientInterface`)
- Crontab operations → `CrontabAdapterInterface`

### Cron Scheduling (CRITICAL)

**NEVER schedule cron jobs by directly formatting cron expressions.** Always use `CronSchedulingService`.

**Why this matters:** Cron daemon runs in the OS system timezone (e.g., `America/Los_Angeles`), but PHP often runs in UTC. Using `date_default_timezone_get()` or `date()` to format cron expressions causes jobs to fire hours early or late.

**Correct approach:**
```php
// One-time job at specific timestamp
$this->cronSchedulingService->scheduleAt($unixTimestamp, $command, $comment);

// Recurring daily job with timezone offset (e.g., "06:30-08:00" for 6:30 AM Pacific)
$this->cronSchedulingService->scheduleDaily($timeWithOffset, $command, $comment);

// Get cron expression without scheduling (for healthchecks, logging)
$cronExpr = $this->cronSchedulingService->getCronExpression($timestamp, useUtc: true);
```

**Wrong approach (DO NOT DO THIS):**
```php
// WRONG: Uses PHP timezone, not system timezone where cron runs
$dateTime = new DateTime('@' . $timestamp);
$dateTime->setTimezone(new DateTimeZone(date_default_timezone_get())); // BUG!
$cronExpr = sprintf('%d %d %d %d *', $minute, $hour, $day, $month);
$this->crontabAdapter->addEntry("$cronExpr $command");
```

**When is direct CrontabAdapter use acceptable?**
- Reading entries (`listEntries()`) - no timezone conversion needed
- Deleting entries (`removeByPattern()`) - pattern matching only
- Static schedules (e.g., `MaintenanceCronService` uses hardcoded `0 3 1 * *`)

**When MUST you use CronSchedulingService?**
- Any dynamic scheduling based on user input or timestamps
- Any job where the fire time matters (heat-target, scheduled equipment control)

## Git Workflow

**IMPORTANT: Never work directly on the `production` branch without explicit user approval.**

- Local development should always be on `main` branch
- Create PRs from `main` to `production` for deployment
- If you find yourself on `production`, switch to `main` before making changes
- The `production` branch represents what's deployed to the live server

**IMPORTANT: Code flows one direction only: main → production. Never merge production back into main.**

- Do NOT run `git merge origin/production` or similar commands
- After a PR is merged to production, the merge commits belong to production's history, not main's
- If you need to sync local main, use `git pull origin main` (not production)
- The only exception: hotfixes made directly on production (which should be rare and require user approval)

## Production Server Access

**CRITICAL: FTP access is READ-ONLY for debugging. NEVER upload files via FTP.**

FTP credentials in `backend/config/env.production` provide access to the production server for **diagnostic purposes only**:
- Reading log files (`storage/logs/api.log`, `storage/logs/cron.log`)
- Checking state files (`storage/state/*.json`)
- Investigating production issues

**Deployment rules:**
- ALL code changes MUST go through the git workflow: commit → push to main → PR to production → merge
- NEVER use FTP, curl, or any other method to upload/modify files on production
- Even for urgent hotfixes, use the PR workflow (it only takes a minute)

## Development Methodology: TDD Red/Green

**All new functionality MUST follow Test-Driven Development:**

1. **RED**: Write a failing test first, run to prove it fails
2. **GREEN**: Write minimal code to pass, run to prove it passes
3. **REFACTOR**: Clean up while keeping tests green
4. **REPEAT**: Build functionality incrementally with test coverage

### Key Principles
- Never skip the RED step - running before implementation proves the test can fail
- Small increments - each test covers one small behavior
- Console debugging is temporary - remove `console.log`/`var_dump` after fixing

## Critical Safety - Hardware Control

**This system controls real hardware!** The IFTTT webhooks trigger SmartLife automation for heater, pump, and ionizer.

### Environment Configuration

The app uses **file-based configuration** via `backend/.env`:

```
backend/
├── .env                          # Active config (gitignored, created by test script or manually)
├── config/
│   ├── env.development           # Local dev (stub mode)
│   ├── env.testing               # For tests (stub mode, has JWT_SECRET)
│   ├── env.staging               # Staging server (live mode)
│   └── env.production.example    # Production template (live mode)
```

**For testing:** The `./scripts/test.sh` script automatically configures `.env` correctly.

**For development:**
```bash
cp backend/config/env.development backend/.env
```

**For production:**
```bash
cp backend/config/env.production.example backend/.env
# Edit .env to add your real IFTTT_WEBHOOK_KEY
```

### External API Mode Configuration
A unified `EXTERNAL_API_MODE` controls ALL external service calls (IFTTT, Healthchecks.io):

```bash
# In .env file:
EXTERNAL_API_MODE=stub   # Development/Testing: simulated calls (safe)
EXTERNAL_API_MODE=live   # Production: real API calls (requires keys)
```

**Mode behavior:**
- `stub` - All external APIs use simulated responses (no network calls)
- `live` - All external APIs make real calls (requires API keys)

**Safety rules:**
- Always use `EXTERNAL_API_MODE=stub` during development and testing
- Never commit `backend/.env` (it's gitignored)
- The test script automatically sets stub mode
