# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Hot tub controller system with a PHP backend API and SvelteKit frontend. Controls real hardware via IFTTT webhooks (heater, pump, ionizer) and monitors temperature via WirelessTag sensors.

## Project Structure

```
backend/           # PHP API (PHPUnit tests)
frontend/          # SvelteKit + TypeScript + Tailwind (Vitest tests)
_archive/          # Previous implementation for reference patterns
```

## Build & Test Commands

### Backend (PHP)
```bash
cd backend
composer test                           # Run all PHPUnit tests
./vendor/bin/phpunit                    # Alternative test command
./vendor/bin/phpunit --filter=testName  # Run single test
php -S localhost:8080 -t public         # Start dev server
```

### Frontend (SvelteKit)
```bash
cd frontend
npm run dev              # Start dev server
npm run build            # Production build
npm run test             # Run Vitest tests
npm run test:watch       # Tests in watch mode
npm run check            # TypeScript/Svelte type checking
```

## Architecture

### Backend
- **Entry point**: `public/index.php` - Simple router with CORS handling
- **Controller**: `src/Controllers/EquipmentController.php` - Equipment control endpoints
- **EnvLoader**: `src/Services/EnvLoader.php` - File-based configuration loading
- **IFTTT Client Pattern**: Uses interface (`IftttClientInterface`) with unified client:
  - `IftttClient` - Unified client with injectable HTTP layer
  - `StubHttpClient` - Simulates API calls (safe for testing)
  - `CurlHttpClient` - Makes real IFTTT webhook calls
- **Factory**: `IftttClientFactory` - Creates appropriate client based on config

### Frontend
- **Framework**: SvelteKit with Svelte 5 runes (`$state`)
- **API client**: `src/lib/api.ts` - Typed API wrapper
- **Components**: `src/lib/components/` - Reusable UI components

### API Endpoints
- `GET /api/health` - Health check with IFTTT mode status
- `POST /api/equipment/heater/on` - Trigger IFTTT `hot-tub-heat-on`
- `POST /api/equipment/heater/off` - Trigger IFTTT `hot-tub-heat-off`
- `POST /api/equipment/pump/run` - Trigger IFTTT `pump-run-2hr`

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

### IFTTT Mode Configuration
Set via `IFTTT_MODE` in `.env` file:
- `stub` - Always use simulated calls (safe for development/testing)
- `live` - Use real IFTTT calls (requires `IFTTT_WEBHOOK_KEY`)
- `auto` - Uses stub in testing environment, live if key available

**Safety rules:**
- Always use `IFTTT_MODE=stub` during development
- Never commit `backend/.env` (it's gitignored)
- Get API keys from: https://ifttt.com/maker_webhooks/settings
- Tests automatically use stub mode

## Reference: Archived Implementation

The `_archive/` folder contains patterns to reference:
- IFTTT safety client with test/dry-run modes
- VCR testing for HTTP interactions
- Temperature simulation for heating cycle tests
