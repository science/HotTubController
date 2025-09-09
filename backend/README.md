# Hot Tub Controller - Backend API

A PHP-based backend API for intelligent hot tub temperature management and equipment control. This system provides CORS proxy functionality, external API integration, and a complete storage infrastructure for heating cycle management.

## 🎯 Project Overview

This backend serves as the core engine for automated hot tub heating control, featuring:

- **Temperature Monitoring**: Integration with WirelessTag API for precise water and ambient temperature readings
- **Equipment Control**: IFTTT webhook integration for safe heater, pump, and ionizer control via SmartLife automation  
- **Intelligent Scheduling**: Planned cron-based heating cycles with dynamic temperature monitoring
- **Safety-First Design**: Multiple layers of protection against accidental hardware triggers
- **Model-Persistence Layer**: Custom JSON-based storage with file rotation and advanced querying

## 🏗 Architecture

### Core Components

```
src/
├── Application/           # Slim framework HTTP layer
│   ├── Actions/          # API endpoints (Auth, Proxy, Admin)
│   ├── Handlers/         # Error handling
│   └── Middleware/       # CORS and authentication middleware
├── Domain/               # Business logic and models
│   ├── Token/           # Authentication token management
│   ├── Proxy/           # HTTP proxy domain logic
│   ├── Storage/         # Model-persistence framework
│   └── Heating/         # Heating cycle and event models
├── Infrastructure/       # External integrations
│   ├── Http/            # HTTP client implementations
│   ├── Persistence/     # File-based storage
│   └── Storage/         # JSON storage management
└── Services/            # Application services (WirelessTag, IFTTT)
```

### Design Patterns

- **Repository Pattern**: Clean separation between domain models and data persistence
- **Factory Pattern**: Environment-aware client creation with safety checks
- **Proxy Pattern**: CORS-enabled access to external APIs from web frontends
- **Strategy Pattern**: Flexible storage rotation and cleanup strategies

## 🚀 What's Implemented

### ✅ External API Integration
- **WirelessTag API Client** with OAuth 2.0 authentication and test mode support
- **IFTTT Webhook Client** with comprehensive safety features
- **Factory Pattern**: Environment-aware client creation with automatic test mode detection
- VCR (Video Cassette Recorder) testing for reliable API mocking
- Retry logic with exponential backoff for network resilience

### ✅ Model-Persistence Infrastructure  
- **JsonStorageManager**: Thread-safe JSON file storage with automatic rotation
- **Repository Pattern**: CRUD operations with advanced querying capabilities
- **QueryBuilder**: Fluent interface for filtering, sorting, and pagination
- **HeatingCycle Model**: Track heating sessions with status, temperatures, and timing
- **HeatingEvent Model**: Manage scheduled start/monitor events with cron integration
- **File Management**: Automatic rotation, compression, and cleanup of old data files

### ✅ Safety & Testing Infrastructure
- **Environment Detection**: Automatic test mode when API keys are missing/invalid
- **Dual Environment Strategy**: Separate `.env` and `.env.testing` configurations
- **Comprehensive Test Suite**: 486 unit and integration tests (all passing)
- **Test Mode Support**: Both WirelessTag and IFTTT clients support deterministic testing
- **VCR Temperature Simulation**: Realistic heating cycle testing without live APIs
- **Audit Logging**: Complete operation tracking for safety and debugging

### ✅ Authentication & Security
- Master password authentication for admin operations
- Token-based API access with user management
- File-based token storage with proper permissions
- Rate limiting and secure credential management

### ✅ Heating Control System (Phase 1 Complete)
- **Cron Management System**: CronManager, CronSecurityManager, and CronJobBuilder with secure API key authentication
- **Core Heating APIs**: StartHeatingAction, MonitorTempAction, StopHeatingAction fully implemented and tested
- **Management APIs**: Complete user-facing API suite for scheduling, monitoring, and controlling heating cycles
  - `POST /api/schedule-heating` - Schedule future heating with intelligent overlap prevention
  - `POST /api/cancel-scheduled-heating` - Cancel scheduled heating events 
  - `GET /api/list-heating-events` - Paginated listing of all heating events with filtering
  - `GET /api/heating-status` - Real-time system status and temperature monitoring
- **Equipment Safety**: Emergency stop capabilities, equipment safety sequences, orphaned cron cleanup
- **Integration Complete**: Full WirelessTag and IFTTT integration with comprehensive error handling

## 🔧 Installation & Setup

### Prerequisites
- PHP 8.1+
- Composer
- Git

### Quick Start

```bash
# Clone and install dependencies
git clone <repository-url>
cd backend
make install          # Installs composer deps and creates .env

# Set up development environment  
make dev-setup        # Creates directories and basic config files

# Configure environment
cp .env.example .env
chmod 600 .env
# Edit .env with your API tokens (see Configuration section)

# Run tests to verify everything works
make test

# Start development server
make serve            # http://localhost:8080
```

## ⚙️ Configuration

### Environment Variables

Create `.env` file with:

```env
# WirelessTag API (for temperature monitoring)
WIRELESSTAG_OAUTH_TOKEN=your_wirelesstag_oauth_token_here

# IFTTT Webhooks (for equipment control) - CRITICAL: CONTROLS REAL HARDWARE
IFTTT_WEBHOOK_KEY=your_ifttt_webhook_key_here

# Application settings
LOG_LEVEL=info
CORS_ALLOWED_ORIGINS=https://your-frontend-domain.com
```

### Test Environment Safety

For testing, create `.env.testing` that **intentionally omits** `IFTTT_WEBHOOK_KEY`:

```env
# Test environment - IFTTT_WEBHOOK_KEY intentionally omitted for safety
WIRELESSTAG_OAUTH_TOKEN=your_test_token_here
LOG_LEVEL=error
```

This prevents any accidental hardware triggers during testing.

## 🧪 Testing

### Running Tests

```bash
# All tests (recommended)
make test

# Specific test suites  
make test-unit         # Unit tests only (140+ tests)
make test-integration  # Integration tests only

# Manual PHPUnit commands
vendor/bin/phpunit                    # All tests
vendor/bin/phpunit --testdox         # Human-readable output  
vendor/bin/phpunit --testsuite=unit  # Unit tests only

# Generate coverage report
make coverage         # Creates coverage/index.html
```

### Test Structure

```
tests/
├── Unit/                    # Fast, isolated unit tests
│   ├── Domain/             # Model and domain logic tests
│   ├── Services/           # Service class tests  
│   └── Application/        # HTTP layer tests
├── Integration/            # Slower integration tests
│   └── Domain/            # Multi-component integration tests
└── cassettes/             # VCR recordings for API testing
```

### API Testing Scripts

```bash
# Test external API connectivity (safe)
php test-external-apis.php

# Test WirelessTag integration with VCR
php test-wirelesstag-client.php

# Demo VCR temperature simulation system
php demo-vcr-temperature-simulation.php

# Demo storage system functionality  
php demo-storage-system.php

# Record IFTTT webhooks (DANGEROUS - triggers real hardware!)
php record-ifttt-webhooks.php
```

## ⚠️ Safety Features

### IFTTT Hardware Control Safety

**CRITICAL**: IFTTT webhooks control real hot tub hardware (heater, pump, ionizer).

#### Multiple Safety Layers:
1. **Environment Detection**: Test environment automatically forces safe mode
2. **Missing API Key Safety**: No `IFTTT_WEBHOOK_KEY` = automatic test mode  
3. **Explicit Confirmation**: Recording script requires manual confirmation for each action
4. **Audit Logging**: All operations logged to `storage/logs/ifttt-audit.log`
5. **Dry Run Mode**: Simulate operations without making actual API calls
6. **Factory Safeguards**: `IftttWebhookClientFactory` prevents production operations in test env

#### Hardware Event Mapping:
- `hot-tub-heat-on`: Starts pump → waits → activates heater (async sequence)
- `hot-tub-heat-off`: Stops heater → cooling pump cycle → stops pump  
- `turn-on-hot-tub-ionizer`: Activates water ionization system
- `turn-off-hot-tub-ionizer`: Deactivates ionization system

## 📊 Storage System

### Models

**HeatingCycle**: Represents a heating session
```php
$cycle = new HeatingCycle();
$cycle->setTargetTemp(104.0);
$cycle->setCurrentTemp(88.5);
$cycle->setStatus(HeatingCycle::STATUS_HEATING);
$cycle->save();
```

**HeatingEvent**: Represents scheduled heating operations
```php
$event = new HeatingEvent();
$event->setEventType(HeatingEvent::EVENT_TYPE_START);
$event->setScheduledFor(new DateTime('+1 hour'));
$event->setTargetTemp(104.0);  
$event->save();
```

### Advanced Querying

```php
// Find active heating cycles with high target temps
$cycles = $cycleRepository->query()
    ->where('status', HeatingCycle::STATUS_HEATING)
    ->where('target_temp', '>', 100.0)
    ->orderBy('target_temp', 'desc')
    ->limit(10)
    ->get();

// Complex queries with nested field access
$demoCycles = $cycleRepository->query()  
    ->where('metadata.created_by', 'demo_script')
    ->whereBetween('target_temp', [100.0, 105.0])
    ->count();
```

### File Management

- **Automatic Rotation**: Daily or size-based file rotation
- **Compression**: Old files compressed with gzip to save space
- **Cleanup**: Files older than 7 days (configurable) automatically removed
- **Thread Safety**: File locking prevents corruption during concurrent access

Storage structure:
```
storage/data/
├── heating_cycles/
│   ├── 2025-09-07.json     # Today's cycles
│   ├── 2025-09-06.json     # Yesterday's cycles  
│   └── archive/
│       └── 2025-08-*.json.gz  # Compressed old files
└── heating_events/
    ├── current.json         # Active scheduled events
    └── history/
        └── 2025-09-*.json  # Historical events
```

## 🚢 Deployment

### Production Deployment

1. **Server Requirements**:
   - PHP 8.1+ with extensions: curl, json, mbstring, fileinfo
   - Web server (Apache/Nginx) with URL rewriting
   - Writable `storage/` directory with proper permissions

2. **Environment Setup**:
   ```bash
   # Install dependencies (production only)
   composer install --no-dev --optimize-autoloader
   
   # Set proper permissions
   chmod -R 755 storage/
   chmod 600 .env
   
   # Create required directories
   mkdir -p storage/{logs,data,demo}
   ```

3. **Web Server Configuration**:
   - Document root: `public/`
   - Rewrite all requests to `public/index.php`
   - Enable CORS headers for frontend domains

4. **Environment Variables**:
   - Set `IFTTT_WEBHOOK_KEY` for hardware control
   - Configure `CORS_ALLOWED_ORIGINS` for your frontend
   - Use secure `WIRELESSTAG_OAUTH_TOKEN`

### Docker Deployment (Future)

```dockerfile
FROM php:8.1-apache
RUN docker-php-ext-install curl json
COPY . /var/www/html
RUN chmod -R 755 storage/
EXPOSE 80
```

## 🔮 What's Next

**Phase 1 Complete!** 🎉 The entire heating control system is now fully implemented and tested with 486 passing tests.

### **🎯 CURRENT STATUS: Ready for Phase 2** 
With Phase 1 complete, the project now has:
- Complete heating control API suite with all management endpoints
- Robust cron-based scheduling system with security
- Comprehensive test coverage and safety features
- Full integration with WirelessTag sensors and IFTTT equipment control

### **📋 Complete Development Roadmap**
See [**ROADMAP.md**](./ROADMAP.md) for detailed implementation phases:

- **Phase 1**: Core heating control APIs ✅ **COMPLETE**
- **Phase 2**: Web interface foundation (3-4 weeks) ← **NEXT**
- **Phase 3**: Advanced features (4-6 weeks)
- **Phase 4**: Production deployment (2-3 weeks)

The roadmap includes specific implementation order, success criteria, and technical details for each component.

## 📖 API Documentation

Complete API documentation for frontend integration:

- **[API Guide](docs/API.md)** - Comprehensive guide for frontend engineers with examples and best practices
- **[OpenAPI Specification](docs/api-reference.yaml)** - Complete Swagger/OpenAPI 3.0 specification

### Key API Endpoints for Frontend:
- `GET /api/heating-status` - Real-time system status (public)
- `POST /api/schedule-heating` - Schedule heating cycles  
- `POST /api/cancel-scheduled-heating` - Cancel scheduled events
- `GET /api/list-heating-events` - List all heating events with filtering
- `POST /api/stop-heating` - Emergency stop functionality
- `POST /api/v1/auth` - Authentication endpoints

The API documentation includes authentication flows, request/response examples, error handling, and integration best practices.

## 🤝 Contributing

### Development Workflow

1. **Setup**: `make dev-setup`
2. **Code**: Follow PSR-12 coding standards
3. **Test**: `make test` before committing  
4. **Quality**: `make quality` runs style checks + static analysis + tests
5. **Commit**: Use descriptive commit messages

### Code Quality Tools

```bash
make cs-check     # Check PSR-12 code style
make cs-fix       # Auto-fix style issues
make analyze      # PHPStan static analysis (level 8)
make quality      # Run all quality checks
```

### Testing Guidelines

- Write unit tests for all new models and services
- Add integration tests for complex workflows  
- Use VCR for external API testing
- Maintain >90% code coverage
- Test both success and error scenarios

## 📝 License

This project is part of a personal hot tub automation system. See main project repository for licensing details.

## 🔗 Related Projects

- **Frontend**: React-based dashboard for monitoring and control
- **Mobile App**: Native mobile interface for remote control
- **Hardware**: Raspberry Pi controller with relay integration

---

**⚠️ Safety Warning**: This system controls real hot tub hardware. Always use test mode during development and ensure proper safety measures are in place before deployment.