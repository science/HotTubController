# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Setup and Installation
```bash
make install          # Install composer dependencies and setup .env
make dev-setup        # Complete development environment setup
```

### Testing
```bash
make test             # Run all tests (vendor/bin/phpunit)
make test-unit        # Run only unit tests
make test-integration # Run only integration tests
make coverage         # Generate HTML coverage report in coverage/
```

#### Debug Output Control
Tests run silently by default. Use `DEBUG_LEVEL` to control output verbosity:
```bash
make test                    # Silent (default) - errors only
DEBUG_LEVEL=1 make test     # Show warnings (invalid readings, API issues)  
DEBUG_LEVEL=2 make test     # Show info (IFTTT success messages)
DEBUG_LEVEL=3 make test     # Show debug (full API call details)
```

### Code Quality
```bash
make analyze          # Run PHPStan static analysis (level 8)
make cs-check         # Check PSR-12 code style
make cs-fix           # Auto-fix code style issues
make quality          # Run all quality checks (cs-check + analyze + test)
```

### Development Server
```bash
make serve            # Start dev server at localhost:8080
make serve-test       # Start test server at localhost:8081
```

### API Testing
```bash
php test-external-apis.php              # Test WirelessTag & IFTTT API connectivity
php test-wirelesstag-client.php         # Test WirelessTag API client with VCR
php demo-vcr-temperature-simulation.php # Demo VCR temperature simulation system
php record-ifttt-webhooks.php          # Record IFTTT webhook responses (TRIGGERS HARDWARE!)
php demo-storage-system.php            # Demonstrate storage system functionality
```

### IFTTT Testing and Safety

**CRITICAL: IFTTT webhooks control real hardware!** The system has multiple safety layers:

#### Environment Safety Strategy
- **Production** (`.env`): Contains `IFTTT_WEBHOOK_KEY` for real hardware control
- **Testing** (`.env.testing`): INTENTIONALLY omits `IFTTT_WEBHOOK_KEY` to prevent hardware triggers
- **Automatic detection**: Test environment forces safe mode regardless of configuration

#### Safe Testing Commands
```bash
# All tests run in safe mode automatically
make test                    # Runs all tests safely (no hardware access)
make test-unit              # Unit tests with simulated responses
make test-integration       # Integration tests using VCR cassettes

# Manual testing with safety modes
php -d APP_ENV=testing test-external-apis.php  # Force test mode
```

#### Recording VCR Cassettes (DANGER: Hardware Control)
```bash
# This WILL trigger real hardware - use with extreme caution!
php record-ifttt-webhooks.php

# Safety features in recording script:
# - Uses production .env with real API key
# - Requires explicit confirmation for each webhook
# - 5-second countdown with abort option
# - Logs all operations for audit trail
# - Prompts to observe hardware behavior
```

#### IFTTT Client Safety Features
- **Test Mode**: Automatically activated when API key is missing
- **Dry Run Mode**: Logs operations but makes no HTTP calls
- **Audit Logging**: All operations logged to `storage/logs/ifttt-audit.log`
- **Environment Detection**: Refuses production operations in test environment
- **Simulation**: Realistic response timing and behavior for testing

#### Factory Pattern Usage
```php
use HotTubController\Services\IftttWebhookClientFactory;

// Safe: Auto-detects environment, uses test mode when appropriate
$client = IftttWebhookClientFactory::create();

// Safe: Always operates in safe mode regardless of environment
$client = IftttWebhookClientFactory::createSafe();

// Dangerous: Requires explicit API key, refuses to run in test env
$client = IftttWebhookClientFactory::createProduction('real-api-key');
```

#### Hardware Event Mapping
Based on Tasker XML analysis, these events control physical equipment:
- `hot-tub-heat-on`: Starts pump → waits for circulation → activates heater
- `hot-tub-heat-off`: Stops heater → continues pump for cooling → stops pump
- `turn-on-hot-tub-ionizer`: Activates ionizer system
- `turn-off-hot-tub-ionizer`: Deactivates ionizer system

## Architecture Overview

### Core Purpose
PHP-based API for hot tub controller, designed for intelligent heating control through:
- **WirelessTag API**: Temperature monitoring from wireless sensors  
- **IFTTT Webhooks**: Equipment control (heater, pump, ionizer) via SmartLife automation
- **Cron-based scheduling**: Dynamic heating cycles with temperature monitoring loops

### Application Structure
```
src/
├── Application/           # Slim framework application layer
│   ├── Actions/          # HTTP action handlers (Auth, Admin, Heating)
│   ├── Handlers/         # Error handlers
│   └── Middleware/       # HTTP middleware
├── Domain/               # Core business logic
│   ├── Token/           # Authentication token management
│   ├── Storage/         # Model-persistence framework
│   └── Heating/         # Heating cycle and event models
├── Infrastructure/       # External integrations
│   ├── Persistence/     # File-based storage (tokens, config)
│   └── Storage/         # JSON storage management
└── Services/            # Application services
```

### Key Components

**Authentication System**:
- Master password authentication for admin operations
- Token-based API access with user management
- File-based storage in `storage/tokens.json`

**External API Integration**:
- WirelessTag OAuth 2.0 client for temperature monitoring
- IFTTT webhook client for equipment control
- Comprehensive retry logic with exponential backoff
- VCR-based testing for API reliability

**Model-Persistence Infrastructure**:
- Custom JSON-based storage system with file rotation and cleanup
- Repository pattern with CRUD operations and advanced querying
- HeatingCycle and HeatingEvent models with validation
- QueryBuilder with filtering, sorting, pagination, and nested field access
- Automatic file management: daily/size-based rotation, compression, cleanup
- Thread-safe file locking and atomic operations

**Heating Control Architecture** (planned):
- Dynamic cron scheduling for future heating start times (`HOT_TUB_START`)
- Active monitoring loops during heating cycles (`HOT_TUB_MONITOR`)
- Intelligent temperature polling (coarse → precision control near target)
- Safety features: max temperature limits, heating duration limits, sensor timeout detection

### Testing Strategy
- **Unit Tests**: `tests/Unit/` - Domain logic and service classes
- **Integration Tests**: `tests/Integration/` - API clients and external service interactions
- **VCR Testing**: HTTP interaction recording for reliable API testing
- **VCR Temperature Simulation**: Dynamic temperature sequence generation for heating cycle testing
- **PHPUnit Configuration**: Separate test suites with coverage reporting

#### VCR Temperature Simulation System
A comprehensive testing framework for hot tub heating cycles:
- **Realistic Physics**: Simulates 0.5°F/minute heating rate (1°F every 2 minutes)
- **Dynamic Sequences**: Temperature progressions from initial to target temps (e.g., 88°F → 102°F over 28 minutes)
- **Precision Monitoring**: 15-second intervals when within 1°F of target temperature
- **Sensor Variations**: Battery degradation, signal strength changes, timestamp progression
- **Failure Scenarios**: Communication timeouts, invalid readings, low battery simulation
- **Deterministic Testing**: Reproducible test conditions without live API calls

Key Components:
- `TemperatureSequenceBuilder`: Generates realistic heating progressions
- `VCRCassetteGenerator`: Creates VCR cassettes with temperature data
- `VCRResponseInterceptor`: Injects dynamic values into recorded responses
- `HeatingTestHelpers`: High-level utilities for heating cycle validation

### Configuration
- **Environment**: `.env` file with secure permissions (600) for API tokens
- **Application Config**: `config.json` for application settings and feature flags
- **Storage**: File-based persistence in `storage/` directory for tokens and state

### Planned Features
The architecture is designed to support intelligent hot tub heating control with:
- Scheduled heating events with time-based estimation
- Dynamic temperature monitoring with precision control
- Safe equipment sequences (pump → heater → cooling cycle)
- Emergency stop capabilities and manual overrides
- Web interface for schedule management and real-time monitoring

### External Dependencies
- **WirelessTag API**: Temperature sensor data with battery conservation strategies
- **IFTTT Webhooks**: Equipment control through pre-configured automation scenes
- **Cron System**: Dynamic scheduling for heating cycles and temperature monitoring

## Security Requirements

### Authentication Architecture
The API uses a layered security model with role-based access control:

**CRITICAL**: NO public endpoints that expose system data. All data endpoints require authentication.

#### Authentication Types
1. **User Tokens**: Standard Bearer tokens for frontend applications
2. **Admin Tokens**: Elevated Bearer tokens for administrative operations
3. **Master Password**: System-level access for token management
4. **Cron API Keys**: System-to-system authentication for scheduled operations

#### Base Action Classes (MANDATORY)
- `AuthenticatedAction`: Extends Action, requires user/admin Bearer token
- `AdminAuthenticatedAction`: Extends AuthenticatedAction, requires admin role
- `CronAuthenticatedAction`: Extends Action, requires cron API key
- Plain `Action`: ONLY for public status endpoint with minimal info

#### Security Rules (ENFORCE STRICTLY)
- **NO bypassing authentication for "convenience"**
- **NO public endpoints that expose temperature, system status, or equipment data**
- **ALL heating control endpoints require authentication (user, admin, or cron)**
- **Emergency endpoints still require authentication (admin or cron)**
- **Use appropriate base class for each action**:
  - User data access → `AuthenticatedAction`
  - Admin operations → `AdminAuthenticatedAction` 
  - Cron operations → `CronAuthenticatedAction`
  - Public status only → `Action` (with minimal response)

#### Token Management
- Tokens have roles: `user` (default) or `admin`
- Admin tokens can access emergency stops and elevated operations
- User tokens can access heating scheduling and status
- Store roles in token objects and enforce in base classes
- Master password creates tokens, supports role specification

#### Security Checklist for New Endpoints
- [ ] Does endpoint extend appropriate base authentication class?
- [ ] Does endpoint expose sensitive data? (Requires auth if yes)
- [ ] Is role requirement appropriate for operation sensitivity?
- [ ] Does API documentation specify authentication requirements?
- [ ] Are authentication headers properly validated?

### Development Anti-Patterns (NEVER DO THESE)
- Creating public endpoints for system data "for testing"
- Bypassing authentication with hardcoded tokens
- Using inline authentication instead of base classes
- Returning verbose error messages that expose system internals
- Storing secrets in code or committing them to git

## Environment Setup
Ensure `.env` file exists with proper API tokens:
```bash
cp .env.example .env
chmod 600 .env
# Edit .env with actual WIRELESSTAG_OAUTH_TOKEN and IFTTT_WEBHOOK_KEY
```

## GitHub Operations
For GitHub CLI operations including project board management, issue tracking, and repository operations, see [GITHUB_OPERATIONS.md](../GITHUB_OPERATIONS.md) in the project root.