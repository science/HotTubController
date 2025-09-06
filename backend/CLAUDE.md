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
```

## Architecture Overview

### Core Purpose
PHP-based CORS proxy API for hot tub controller, designed for intelligent heating control through:
- **WirelessTag API**: Temperature monitoring from wireless sensors  
- **IFTTT Webhooks**: Equipment control (heater, pump, ionizer) via SmartLife automation
- **Cron-based scheduling**: Dynamic heating cycles with temperature monitoring loops

### Application Structure
```
src/
├── Application/           # Slim framework application layer
│   ├── Actions/          # HTTP action handlers (Auth, Proxy, Admin)
│   ├── Handlers/         # Error handlers
│   └── Middleware/       # HTTP middleware
├── Domain/               # Core business logic
│   ├── Token/           # Authentication token management
│   └── Proxy/           # HTTP proxying domain logic
├── Infrastructure/       # External integrations
│   ├── Http/            # HTTP client implementations
│   └── Persistence/     # File-based storage (tokens, config)
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
- **Application Config**: `config.json` for CORS settings and feature flags
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

## Environment Setup
Ensure `.env` file exists with proper API tokens:
```bash
cp .env.example .env
chmod 600 .env
# Edit .env with actual WIRELESSTAG_OAUTH_TOKEN and IFTTT_WEBHOOK_KEY
```