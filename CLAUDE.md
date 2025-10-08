# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Setup and Installation
```bash
cd backend
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

#### Backend
```bash
cd backend
make serve            # Start default dev server at localhost:8080
make serve-sim        # Start simulation server (safe mode - no hardware)
make serve-live       # Start live dev server (DANGER: controls real hardware)
make serve-test       # Start test server at localhost:8081
```

#### Frontend
```bash
cd frontend
npm run dev           # Start Vite dev server at localhost:5173 (uses Node 22.19.0 via Volta)
npm run build         # Type-check and build for production
npm run preview       # Preview production build
npm run lint          # Run ESLint on TypeScript files
npm run type-check    # Run TypeScript compiler checks without emitting
```

#### Environment Switching
The backend supports multiple environment configurations for safe development:

- **Simulation Mode** (`make serve-sim`): Uses `.env.development-sim`
  - All API keys are empty, forcing safe mode
  - No hardware can be triggered
  - Ideal for frontend development and testing

- **Live Mode** (`make serve-live`): Uses `.env.development-live`
  - **WARNING**: Requires confirmation prompt
  - Uses real API credentials and controls actual hardware
  - Only use when you need to test real equipment

#### Frontend
**Note**: The frontend development server is typically running externally at `localhost:5173`.
When debugging frontend issues, assume the dev server is already running and access it directly at that port.

### API Testing
```bash
php test-external-apis.php              # Test WirelessTag & IFTTT API connectivity
php test-wirelesstag-client.php         # Test WirelessTag API client with VCR
php demo-vcr-temperature-simulation.php # Demo VCR temperature simulation system
php record-ifttt-webhooks.php          # Record IFTTT webhook responses (TRIGGERS HARDWARE!)
php demo-storage-system.php            # Demonstrate storage system functionality
```

## Critical Safety - IFTTT Hardware Control

**CRITICAL: IFTTT webhooks control real hardware!** The system has multiple safety layers:

### Environment Safety Strategy
- **Production** (`.env`): Contains `IFTTT_WEBHOOK_KEY` for real hardware control
- **Testing** (`.env.testing`): INTENTIONALLY omits `IFTTT_WEBHOOK_KEY` to prevent hardware triggers
- **Development Simulation** (`.env.development-sim`): All API keys empty, forcing safe mode
- **Development Live** (`.env.development-live`): Real API keys with safety confirmations
- **Automatic detection**: Test environment forces safe mode regardless of configuration

### Safe Testing Commands
```bash
# All tests run in safe mode automatically
make test                    # Runs all tests safely (no hardware access)
make test-unit              # Unit tests with simulated responses
make test-integration       # Integration tests using VCR cassettes

# Manual testing with safety modes
php -d APP_ENV=testing test-external-apis.php  # Force test mode
```

### Recording VCR Cassettes (DANGER: Hardware Control)
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

### IFTTT Client Safety Features
- **Test Mode**: Automatically activated when API key is missing
- **Dry Run Mode**: Logs operations but makes no HTTP calls
- **Audit Logging**: All operations logged to `storage/logs/ifttt-audit.log`
- **Environment Detection**: Refuses production operations in test environment
- **Simulation**: Realistic response timing and behavior for testing

### Factory Pattern Usage
```php
use HotTubController\Services\IftttWebhookClientFactory;

// Safe: Auto-detects environment, uses test mode when appropriate
$client = IftttWebhookClientFactory::create();

// Safe: Always operates in safe mode regardless of environment
$client = IftttWebhookClientFactory::createSafe();

// Dangerous: Requires explicit API key, refuses to run in test env
$client = IftttWebhookClientFactory::createProduction('real-api-key');
```

### Hardware Event Mapping
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
backend/src/
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

**Heating Control Architecture**:
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

## Project Status

### ✅ **Phase 1 Complete: Core Heating Control System**
The complete heating control system is fully implemented and tested with comprehensive test coverage:

- **Cron Management System**: CronManager, CronSecurityManager, and CronJobBuilder with secure API key authentication
- **Core Heating APIs**: StartHeatingAction, MonitorTempAction, StopHeatingAction fully implemented and tested
- **Management APIs**: Complete user-facing API suite for scheduling, monitoring, and controlling heating cycles
  - `POST /api/schedule-heating` - Schedule future heating with intelligent overlap prevention
  - `POST /api/cancel-scheduled-heating` - Cancel scheduled heating events
  - `GET /api/list-heating-events` - Paginated listing of all heating events with filtering
  - `GET /api/heating-status` - Real-time system status and temperature monitoring
- **Equipment Safety**: Emergency stop capabilities, equipment safety sequences, orphaned cron cleanup
- **Integration Complete**: Full WirelessTag and IFTTT integration with comprehensive error handling

### 🚧 **Phase 2 In Progress: Web Interface Foundation**
React-based frontend with comprehensive mock data system for independent development:

- **✅ Frontend Foundation**: React 19 + TypeScript + Vite with Node 22 (Volta)
- **✅ Component Library**: Mobile-first UI components with Tailwind CSS v4
  - Temperature display with progress indicators
  - Action buttons and target selector
  - Schedule management (quick schedule + event list)
  - Responsive layout with status bar
- **✅ Mock Data System**: Complete development environment without backend dependency
  - Realistic temperature simulation and heating progression
  - Development scenario switching (normal, heating, cooling, scheduled)
  - Configurable polling with auto-refresh
- **✅ State Management**: React Context for settings, custom hooks for data
- **⏳ API Integration**: Replace mock hooks with real API service layer
- **⏳ Authentication**: Add login flow and token management
- **⏳ Real-time Updates**: WebSocket or polling for live temperature data
- **⏳ PWA Features**: Offline support, install prompts, push notifications

## Authentication Architecture

The API uses a layered security model with role-based access control:

**CRITICAL**: NO public endpoints that expose system data. All data endpoints require authentication.

### Authentication Types
1. **User Tokens**: Standard Bearer tokens for frontend applications
2. **Admin Tokens**: Elevated Bearer tokens for administrative operations
3. **Master Password**: System-level access for token management
4. **Cron API Keys**: System-to-system authentication for scheduled operations

### Base Action Classes (MANDATORY)
- `AuthenticatedAction`: Extends Action, requires user/admin Bearer token
- `AdminAuthenticatedAction`: Extends AuthenticatedAction, requires admin role
- `CronAuthenticatedAction`: Extends Action, requires cron API key
- Plain `Action`: ONLY for public status endpoint with minimal info

### Security Rules (ENFORCE STRICTLY)
- **NO bypassing authentication for "convenience"**
- **NO public endpoints that expose temperature, system status, or equipment data**
- **ALL heating control endpoints require authentication (user, admin, or cron)**
- **Emergency endpoints still require authentication (admin or cron)**
- **Use appropriate base class for each action**:
  - User data access → `AuthenticatedAction`
  - Admin operations → `AdminAuthenticatedAction` 
  - Cron operations → `CronAuthenticatedAction`
  - Public status only → `Action` (with minimal response)

### Token Management
- Tokens have roles: `user` (default) or `admin`
- Admin tokens can access emergency stops and elevated operations
- User tokens can access heating scheduling and status
- Store roles in token objects and enforce in base classes
- Master password creates tokens, supports role specification

### Security Checklist for New Endpoints
- [ ] Does endpoint extend appropriate base authentication class?
- [ ] Does endpoint expose sensitive data? (Requires auth if yes)
- [ ] Is role requirement appropriate for operation sensitivity?
- [ ] Does API documentation specify authentication requirements?
- [ ] Are authentication headers properly validated?

## Frontend Architecture

### Component Organization
```
frontend/src/
├── components/
│   ├── ui/              # Reusable UI primitives (button, card, badge, progress)
│   ├── temperature/     # Temperature display and progress components
│   ├── controls/        # Action buttons and target selector
│   ├── schedule/        # Schedule management components
│   └── layout/          # Layout containers and status bar
├── contexts/            # React Context providers (SettingsContext)
├── hooks/               # Custom React hooks (useMockData, etc.)
├── lib/                 # Shared utilities (cn, formatting helpers)
├── types/               # TypeScript type definitions
└── mock/                # Mock data and simulators for development
```

### State Management Patterns
**React Context for Global Settings:**
- `SettingsContext`: Application-wide settings (polling state, preferences)
- Use custom hook pattern: `useSettings()` for type-safe access
- Provider wraps entire app in `App.tsx`

**Custom Hooks for Data:**
- `useMockHotTub()`: Combined hook providing all mock data and actions
- `useMockTemperature()`: Temperature data with auto-refresh
- `useMockSystemStatus()`: System status with polling
- `useMockEvents()`: Heating events with CRUD operations
- `useMockScenarios()`: Development scenario switching

**Component-Level State:**
- Use `useState` for local UI state (target temp, UI toggles)
- Prefer lifting state to parent when shared between siblings

### Development Mock System
The frontend uses a comprehensive mock data system for development without backend:

**Mock Data Features:**
- **Temperature Simulator**: Realistic heating progression (0.5°F/min default)
- **Global Scenario State**: Switch between development scenarios (normal, heating, cooling)
- **Simulated Delays**: Realistic API response timing (200-1000ms)
- **Polling System**: Auto-refresh with configurable intervals (disabled by default)
- **Action Simulation**: Full CRUD operations on mock events

**Using Mock Data:**
```typescript
// Combined hook for all data and actions
const mockData = useMockHotTub()

// Access data
mockData.temperature.current
mockData.systemStatus.isHeating
mockData.events

// Trigger actions
mockData.actions.startHeating(targetTemp)
mockData.actions.refreshAll()
```

**Scenario Switching:**
Development UI includes scenario buttons for testing different states:
- Normal (idle state)
- Heating (active heating cycle)
- Cooling (post-heating cooldown)
- Scheduled (future heating events)

**Transition to Real API:**
When ready to integrate with backend API:
1. Create `src/services/api.ts` using axios for HTTP client
2. Replace mock hooks with real API hooks using @tanstack/react-query
3. Maintain same hook interface (`useMockHotTub` → `useHotTub`)
4. Keep mock system available via environment flag for development/testing
5. Add authentication token management (stored in Context or zustand store)

### Utility Patterns
**Class Name Merging:**
```typescript
import { cn } from '@/lib/utils'

// Merge Tailwind classes with conditional logic
<div className={cn("base-class", condition && "conditional-class", className)} />
```

**Format Helpers:**
- `formatTemperature(temp, unit)`: Consistent temperature display with rounding
- `formatDuration(minutes)`: Human-readable time (e.g., "2hr 30min")
- `formatRelativeTime(date)`: Relative time strings (e.g., "in 2 hours")
- `vibrate(pattern)`: Mobile haptic feedback (gracefully degrades on desktop)

**Index Exports:**
Components use index files for clean imports:
```typescript
// components/schedule/index.ts
export { QuickSchedule } from './QuickSchedule'
export { ScheduleList } from './ScheduleList'

// Usage
import { QuickSchedule, ScheduleList } from '@/components/schedule'
```

### Component Patterns
**Consistent Props Structure:**
- Primitive values first (strings, numbers, booleans)
- Complex objects (data, status)
- Callbacks (onAction, onChange)
- UI state last (loading, disabled, className)

**Loading States:**
- Type: `'idle' | 'loading' | 'success' | 'error'`
- Show loading spinners/disabled states during async operations
- Provide visual feedback for user actions

**Mobile-First Considerations:**
- Touch targets minimum 44px (`min-h-touch-target`)
- Haptic feedback on important actions (`vibrate()`)
- Large, readable fonts for temperature displays
- Safe area insets for notched devices

**Icons and Visual Elements:**
- Use `lucide-react` for all icons (consistent design system)
- Import only needed icons: `import { Settings, RefreshCw } from 'lucide-react'`
- Standard icon size: `h-4 w-4` for small UI elements, `h-6 w-6` for prominent actions
- Add descriptive aria-labels for accessibility

**Testing:**
- Frontend tests not yet implemented
- Playwright installed but not configured
- Plan: Component tests + E2E tests with Playwright

## Frontend Development Guidelines

### Tailwind CSS v4 Usage
The frontend uses **Tailwind CSS v4** with modern configuration patterns. Follow these established patterns:

#### ✅ **Tailwind v4 Patterns (Use These)**
- **Import**: Use `@import "tailwindcss"` in CSS files
- **Theme Configuration**: Define design tokens in `@theme` blocks within CSS:
  ```css
  @theme {
    --color-primary-500: #3b82f6;
    --spacing-touch-target: 44px;
  }
  ```
- **Custom Utilities**: Use `@utility` directive for custom classes:
  ```css
  @utility text-temp-large {
    font-size: var(--fontSize-temp-large);
    line-height: var(--lineHeight-temp-large);
  }
  ```
- **PostCSS**: Use `@tailwindcss/postcss` plugin in `postcss.config.js`
- **CSS Variables**: Reference theme values as `var(--color-primary-500)`

#### ❌ **Tailwind v3 Patterns (Avoid These)**
- **Don't use `@tailwind` directives** (`@tailwind base`, `@tailwind components`, `@tailwind utilities`)
- **Don't extend in `tailwind.config.js`** - use `@theme` blocks in CSS instead
- **Don't create plugins with `addUtilities`** - use `@utility` directive
- **Don't use arbitrary values** when CSS variables exist (use `bg-[var(--color-primary-500)]` over `bg-[#3b82f6]`)

#### **Established Component Patterns**
- **Class Variance Authority**: Use `cva()` for component variants (see `frontend/src/components/ui/button.tsx`)
- **Class Merging**: Use `cn()` utility with `tailwind-merge` for conditional classes
- **Component Layers**: Define reusable styles in `@layer components` with `@apply`
- **Design Tokens**: All colors, spacing, fonts defined as CSS variables in `@theme`

#### **Mobile-First Development**
- Touch targets: Minimum 44px (`min-h-touch-target`, `min-w-touch-target`)
- Safe areas: Use `env(safe-area-inset-*)` for mobile device notches
- Responsive breakpoints: Mobile-first approach with `xs:`, `sm:`, `md:`, etc.

## External Dependencies

### WirelessTag API
- Temperature sensor data with battery conservation strategies
- OAuth 2.0 authentication with test mode support
- Automatic retry logic with exponential backoff
- VCR recording for reliable testing

### IFTTT Webhooks
- Equipment control through pre-configured automation scenes
- Multiple safety layers prevent accidental hardware triggers
- Comprehensive audit logging of all operations
- Test mode simulation for safe development

### Cron System
- Dynamic scheduling for heating cycles and temperature monitoring
- Self-deleting cron wrapper script for "one-shot" execution
- Secure API key management separate from web authentication
- Automatic cleanup of orphaned cron jobs

## Environment Setup

The project supports multiple environment configurations for different development needs:

### Quick Start (Recommended)
For safe development without hardware risk:
```bash
make serve-sim    # Automatically copies .env.development-sim to .env
```

### Manual Environment Setup

#### Production Environment
```bash
cp .env.example .env
chmod 600 .env
# Edit .env with actual WIRELESSTAG_OAUTH_TOKEN and IFTTT_WEBHOOK_KEY
```

#### Development Environments

**Simulation Mode (Safe)**:
```bash
cp .env.development-sim .env    # Or use: make serve-sim
```
- All API keys empty, forcing safe mode
- No hardware can be triggered
- Ideal for frontend development and testing

**Live Development Mode (Hardware Control)**:
```bash
cp .env.development-live .env   # Or use: make serve-live
```
- **WARNING**: Controls real hardware
- Requires real API credentials
- Use only when testing actual equipment
- Built-in confirmation prompts prevent accidents

**Testing Environment**:
```bash
# Automatically used by test commands
# Uses .env.testing with safety features
```

### Environment File Summary
- `.env.example` - Template for production setup
- `.env.development-sim` - Safe simulation mode (no hardware access)
- `.env.development-live` - Live development mode (real hardware control)
- `.env.testing` - Test environment (automatically safe)

### Documentation Guidelines
- **DO NOT include specific test counts** in documentation (e.g., "486 tests passing")
- Use phrases like "comprehensive test suite", "extensive test coverage", or "all tests passing"
- Avoid numbers that require constant updates when tests are added or modified
- Focus on test quality and coverage rather than quantity

### Development Anti-Patterns (NEVER DO THESE)
- Creating public endpoints for system data "for testing"
- Bypassing authentication with hardcoded tokens
- Using inline authentication instead of base classes
- Returning verbose error messages that expose system internals
- Storing secrets in code or committing them to git
- **Including specific test counts in documentation** (creates maintenance burden)