# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Hot tub controller system with a PHP backend API, SvelteKit frontend, and ESP32 temperature sensor. Controls real hardware via IFTTT webhooks (heater, pump, ionizer) and monitors temperature via ESP32 with DS18B20 probes.

## Project Structure

```
backend/           # PHP API (PHPUnit tests)
frontend/          # SvelteKit + TypeScript + Tailwind (Vitest unit tests, Playwright E2E)
esp32/             # ESP32 firmware (PlatformIO, Unity tests)
```

## Build & Test Commands

### Backend (PHP)
```bash
cd backend
composer test                           # Run tests (excludes live API tests)
composer test:all                       # Run ALL tests including live API tests
composer test:live                      # Run only live API tests
./vendor/bin/phpunit --filter=testName  # Run single test
php -S localhost:8080 -t public         # Start dev server
```

**Test Groups:**
- Default (`composer test`): Fast tests using stubs/mocks - safe for daily development
- Live (`composer test:live`): Tests that hit real external APIs (Healthchecks.io, etc.)
- All (`composer test:all`): Full suite - **run before pushing to production**

Tests tagged `@group live` are excluded by default to keep the feedback loop fast and avoid hitting external APIs unnecessarily.

### Frontend (SvelteKit)
```bash
cd frontend
npm run dev              # Start dev server (port 5173)
npm run build            # Production build
npm run test             # Run Vitest unit tests
npm run test:watch       # Unit tests in watch mode
npm run test:e2e         # Run Playwright E2E tests (auto-starts backend + frontend)
npm run test:e2e:ui      # E2E tests with interactive UI
npm run check            # TypeScript/Svelte type checking
```

**E2E Testing**: Playwright tests in `frontend/e2e/` test frontend-backend integration. They auto-start servers on ports 5174 (frontend) and 8081 (backend). The frontend is served at `/tub` base path.

### ESP32 (PlatformIO)
```bash
cd esp32
pio run                          # Build firmware
pio run -t upload                # Build and upload to device
pio test                         # Run unit tests only (safe for CI)
pio test -e hardware_test        # Run ALL tests including hardware integration
pio device monitor               # Serial monitor
```

**Test Environments:**
- Default (`pio test`): Runs only unit tests from `test/test_unit/`
- Hardware (`pio test -e hardware_test`): Runs ALL tests including `test/test_hardware_integration/`

Hardware integration tests require physical sensors connected and should only be run manually when working with the ESP32 device.

**ESP32 Configuration:**
The ESP32 uses build-time secrets injection via `load_env.py`. Create `esp32/.env`:
```bash
WIFI_SSID=your-wifi-name
WIFI_PASSWORD=your-wifi-password
API_ENDPOINT=http://your-server/backend/public/api/esp32/temperature
ESP32_API_KEY=your-api-key
```

### Test Artifact Cleanup

Tests create artifacts (user accounts, healthchecks.io checks) that are automatically cleaned up:

**E2E Tests (Users)** - Fully automatic:
- `global-setup.ts` cleans stale test users BEFORE tests run (catches previous run failures)
- `global-teardown.ts` cleans test users AFTER tests run (catches current run failures)
- Patterns cleaned: `testuser_*`, `deletetest_*`, `basic_e2e_test`

**PHPUnit Tests (Healthchecks)** - Automatic with live tests:
- Each test's `tearDown()` deletes checks it created
- `composer test:live` and `composer test:all` run cleanup script after tests
- Patterns cleaned: `poc-test-*`, `live-test-*`, `workflow-test-*`, `channel-test-*`

**Manual cleanup** (if needed):
```bash
# Check for stale healthchecks (dry run)
cd backend && composer cleanup:healthchecks:dry

# Delete stale healthchecks
cd backend && composer cleanup:healthchecks
```

**When writing new tests** that create external artifacts:
- Use recognizable test prefixes (e.g., `testuser_`, `poc-test-`)
- Add cleanup in `tearDown()` / `afterAll()` / `afterEach()`
- Add new patterns to cleanup scripts if introducing new prefixes

## Architecture

### Backend
- **Entry point**: `public/index.php` - Router setup and dependency wiring
- **Routing**: `src/Routing/Router.php` - Simple router with `{param}` pattern support
- **Controllers**:
  - `EquipmentController` - Heater/pump control via IFTTT, tracks equipment state
  - `ScheduleController` - Scheduled job management via crontab
  - `AuthController` - JWT-based authentication
  - `UserController` - User management (admin only)
  - `MaintenanceController` - Log rotation endpoint for cron
  - `TemperatureController` - Temperature readings from ESP32
  - `Esp32TemperatureController` - Receives temperature data from ESP32
  - `Esp32SensorConfigController` - Sensor role assignment and calibration
- **Services**:
  - `EnvLoader` - File-based `.env` configuration loading
  - `SchedulerService` - Creates/lists/cancels cron jobs with Healthchecks.io monitoring
  - `AuthService` - JWT token validation
  - `EquipmentStatusService` - Tracks heater/pump on/off state in JSON file
  - `RequestLogger` - API request logging in JSON Lines format
  - `LogRotationService` - Compresses and deletes old log files
  - `CrontabBackupService` - Timestamped backups before crontab modifications
  - `MaintenanceCronService` - Sets up monthly log rotation cron job
  - `Esp32TemperatureService` - Stores ESP32 temperature readings
  - `Esp32SensorConfigService` - Manages sensor roles and calibration offsets
  - `Esp32CalibratedTemperatureService` - Applies calibration to raw ESP32 readings
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
- **Components**:
  - `CompactControlButton.svelte` - Equipment control buttons with active glow state
  - `EquipmentStatusBar.svelte` - Last update time and manual refresh button
  - `SchedulePanel.svelte` - Scheduled jobs list with auto-refresh
  - `QuickSchedulePanel.svelte` - Quick scheduling UI
  - `TemperaturePanel.svelte` - Water/ambient temperature display
  - `SensorConfigPanel.svelte` - ESP32 sensor role/calibration configuration (admin only)

### API Endpoints
- `GET /api/health` - Health check with equipment status
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
- `POST /api/schedule` - Schedule a future action (auth required)
- `GET /api/schedule` - List scheduled jobs (auth required)
- `DELETE /api/schedule/{id}` - Cancel scheduled job (auth required)
- `GET /api/users` - List users (admin only)
- `POST /api/users` - Create user (admin only)
- `DELETE /api/users/{username}` - Delete user (admin only)
- `POST /api/maintenance/logs/rotate` - Rotate log files (cron auth)

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
- If uncertain about branch operations, ask the user before proceeding

```bash
# Check current branch
git branch

# Switch to main if on production
git checkout main

# Sync local main with origin (correct way)
git pull origin main
```

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

The app uses **file-based configuration** via `backend/.env` for simple deployment:

```
backend/
├── .env                          # Active config (gitignored)
├── .env.example                  # Template with instructions
├── config/
│   ├── env.development           # Local dev (stub mode)
│   ├── env.testing               # PHPUnit tests (stub mode)
│   ├── env.staging               # Staging server (live mode)
│   └── env.production.example    # Production template (live mode)
```

**Deployment workflow:**
1. Copy the appropriate `config/env.*` file to `backend/.env`
2. Update any placeholder values (API keys)
3. Deploy - the app always reads from `backend/.env`

```bash
# Local development
cp config/env.development .env

# Production deployment
cp config/env.production.example .env
# Edit .env to add your real IFTTT_WEBHOOK_KEY
```

### External API Mode Configuration
A unified `EXTERNAL_API_MODE` controls ALL external service calls (IFTTT, Healthchecks.io):

```bash
# In .env file:
EXTERNAL_API_MODE=stub   # Development: simulated calls (safe)
EXTERNAL_API_MODE=live   # Production: real API calls (requires keys)
```

**Mode behavior:**
- `stub` - All external APIs use simulated responses (no network calls)
- `live` - All external APIs make real calls (requires API keys)

**Required `.env` variables for live mode:**
```bash
EXTERNAL_API_MODE=live
IFTTT_WEBHOOK_KEY=your-ifttt-key          # For equipment control
ESP32_API_KEY=your-esp32-api-key          # For temperature sensor auth
```

**Safety rules:**
- Always use `EXTERNAL_API_MODE=stub` during development
- Never commit `backend/.env` (it's gitignored)
- Tests automatically use stub mode via `phpunit.xml`
- Live tests (`@group live`) explicitly pass `'live'` mode parameter
