# Hot Tub Controller

A comprehensive web-based automation system for intelligent hot tub temperature management and equipment control.

## Overview

This project provides a complete solution for automated hot tub heating with temperature monitoring, intelligent scheduling, and equipment safety controls. Originally developed as a custom Android Tasker application, it has been modernized into a web-based system with a robust PHP backend, planned React frontend, and comprehensive safety features.

## System Architecture

### Backend Foundation
- **PHP API Backend**: Complete REST API with authentication, CORS proxy, and comprehensive testing
- **Model-Persistence Layer**: Custom JSON-based storage with file rotation and advanced querying
- **External API Integration**: WirelessTag temperature monitoring and IFTTT equipment control
- **Safety-First Design**: Multiple protection layers against accidental hardware activation
- **Cron-Based Automation**: Secure scheduling system with dynamic temperature monitoring

### Key Features
- **Intelligent Heating Control**: Automated heating cycles with temperature-based monitoring intervals
- **Configurable Heating Rate**: User-configurable heating velocity (0.1-2.0°F/min) with admin API control
- **Equipment Safety**: Emergency stop capabilities with proper equipment shutdown sequences
- **Temperature Monitoring**: Real-time water and ambient temperature tracking
- **Scheduling System**: Cron-based heating automation with overlap prevention
- **Secure API Access**: All system endpoints require authentication - no public data exposure
- **Comprehensive Logging**: Full audit trail for all operations and equipment interactions

## Development Status

### ✅ **Phase 1: Core Heating Control - COMPLETE**
- Complete heating control API suite with management endpoints
- **Configurable Heating Rate System**: Admin APIs for user-configurable heating velocity (0.1-2.0°F/min)
- Cron-based scheduling system with secure API key authentication
- WirelessTag sensor integration for temperature monitoring
- IFTTT webhook integration for equipment control (pump, heater, ionizer)
- **Enhanced Authentication System**: Token-based API access with admin/user roles
- **Bootstrap & Admin Management**: Complete admin user management system
- **Security Hardening**: All endpoints require authentication - no public system data exposure
- **Debug Output Control**: Level-based test output control for clean development workflow
- Comprehensive test coverage with safety features and error handling

### 🎯 **Phase 2: Web Interface Foundation - IN PROGRESS**

#### ✅ **Phase 2.1: Frontend Foundation - COMPLETE**
- **✅ Frontend Foundation**: React + TypeScript + Vite setup with Node 22 and Volta
- **✅ Node Version Management**: Volta configured for automatic Node 22.19.0/npm 11.6.0 switching
- **✅ Component Library**: Mobile-first UI components with Tailwind CSS v4
- **✅ Mock Data System**: Complete development environment without backend dependency

#### 🚧 **Phase 2.2: Backend Simulation Mode - IN PROGRESS**
**Goal**: Enable full frontend-backend integration with realistic simulation based on live hardware recordings.

**Backend Simulation Infrastructure**:
- **#28** Create live heating cycle recording script with VCR
- **#29** Execute live heating cycle recording session
- **#30** Create simulation data extraction tool
- **#31** Create backend mode detection system (ModeDetector)
- **#32** Create simulation state manager with live data interpolation
- **#33** Update service factories to use ModeDetector
- **#34** Add system info API endpoint for mode disclosure

**Frontend Integration**:
- **#35** Create frontend API service layer with authentication
- **#36** Add frontend mode detection and dev UI indicators
- **#37** Integration testing: Full frontend-backend simulation mode

#### ⏳ **Phase 2.3: Production Features - PLANNED**
- Real-time Dashboard with live temperature monitoring
- Mobile-responsive Design with PWA support
- Authentication integration and user management
- Historical Analytics and data visualization

### 📅 **Phase 3: Advanced Features - PLANNED** 
- Machine learning temperature prediction and optimization
- Push notifications and email alerts
- Native mobile application
- Weather integration and seasonal usage analysis

## Hardware Requirements

### Temperature Monitoring
- **WirelessTag Outdoor Probe**: DS18B20 sensor for accurate water temperature measurement
  - Product: [WirelessTag Outdoor Probe Basic](https://store.wirelesstag.net/products/outdoor-probe-basic)
- **WirelessTag Ethernet Manager**: Bridge device connecting sensors to internet
  - Product: [Ethernet Tag Manager](https://store.wirelesstag.net/products/ethernet-tag-manager)
- **WirelessTag OAuth API Key**: Account authentication for temperature data access
  - Note on obtaining and interacting with the OAuth key and WT APIs: https://groups.google.com/g/wireless-sensor-tags/c/YJ0lXGJUnkY/m/RNMqU1eJAQAJ
### Equipment Control
- **Smart Relay Controller**: IFTTT-compatible device for pump and heater control
  - Compatible devices: SmartLife or Tuya-based smart switches/relays
  - Example: 4-channel WiFi relay modules with smartphone app integration
- **IFTTT Webhook Integration**: Service connection for remote equipment operation

#### Required IFTTT Webhooks
The system requires four specific IFTTT webhook events to be configured:

1. **`hot-tub-heat-on`** (Required)
   - Triggers heating sequence via SmartLife/Tuya scene
   - Scene should: Turn on pump → wait ~1 minute → turn on heater
   
2. **`hot-tub-heat-off`** (Required) 
   - Triggers heating shutdown via SmartLife/Tuya scene
   - Scene should: Turn off heater → wait ~1.5 minutes → turn off pump

3. **`turn-on-hot-tub-ionizer`** (Optional)
   - Activates water ionization system
   - Can be stubbed to do nothing if ionizer not installed

4. **`turn-off-hot-tub-ionizer`** (Optional)
   - Deactivates water ionization system
   - Can be stubbed to do nothing if ionizer not installed

#### SmartLife/Tuya Scene Configuration
Since IFTTT cannot directly control individual SmartLife/Tuya switches via API, you must create "scenes" in the SmartLife app that IFTTT can trigger:

**Heat On Scene (`hot-tub-heat-on`):**
```
1. Turn ON hot tub water pump
2. Wait 60 seconds (allows water flow to reach full circulation)  
3. Turn ON hot tub heater
```

**Heat Off Scene (`hot-tub-heat-off`):**
```
1. Turn OFF hot tub heater
2. Wait 90 seconds (cooling circulation for heating elements)
3. Turn OFF hot tub water pump  
```

This sequencing protects heating elements by ensuring proper water flow during operation and cooling circulation after shutdown.

### System Integration
The controller manages equipment through carefully sequenced operations:
1. **Heating Start**: Activates pump → waits for circulation → enables heater
2. **Temperature Monitoring**: Continuous sensor readings with intelligent scheduling
3. **Heating Stop**: Disables heater → cooling pump cycle → stops pump
4. **Safety Controls**: Emergency stop capability with complete equipment shutdown

## Quick Start

### Backend Setup
```bash
cd backend
make install     # Install dependencies and create .env
make dev-setup   # Create directories and config files
make test        # Verify installation with full test suite
make serve       # Start development server (localhost:8080)
```

### Frontend Setup
```bash
cd frontend
npm install      # Install dependencies (uses Node 22.19.0 via Volta)
npm run dev      # Start development server (localhost:5173)
```

**Node Version Management**: This project uses [Volta](https://volta.sh/) to automatically switch to Node 22.19.0 when working in the frontend directory. Volta is configured in `frontend/package.json` and will automatically download and use the correct Node/npm versions.

### Environment Configuration
1. Configure WirelessTag OAuth token in `.env`
2. Set IFTTT webhook key for equipment control
3. Configure CORS origins for frontend domains
4. Review safety settings and test mode configuration

See [`backend/README.md`](./backend/README.md) for detailed installation instructions.

## Documentation
- **Backend Setup & API**: [`backend/README.md`](./backend/README.md)
- **Development Roadmap**: [`backend/ROADMAP.md`](./backend/ROADMAP.md) 
- **API Documentation**: [`backend/docs/`](./backend/docs/) directory
- **GitHub Operations**: [`GITHUB_OPERATIONS.md`](./GITHUB_OPERATIONS.md) - GitHub CLI operations and project management

## Safety Considerations

This system controls real hot tub hardware including pumps, heaters, and electrical equipment. Multiple safety layers are implemented:

- **Test Mode Detection**: Automatic safe mode when API keys are missing or invalid
- **Environment Separation**: Dedicated test configuration prevents accidental hardware activation  
- **Emergency Controls**: Immediate stop capability with proper equipment shutdown sequences
- **Comprehensive Logging**: Full audit trail for all equipment operations
- **Hardware Safety**: Proper sequencing prevents unsafe equipment states

Always use test mode during development and ensure proper safety measures before deployment.

## License

Licensed under the Apache License, Version 2.0. See [LICENSE](LICENSE) for details.

## Author

Stephen Midgley