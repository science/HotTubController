# 🗺️ Hot Tub Controller - Development Roadmap

## 📍 Current Status (September 2025)

### ✅ **Foundation Complete (Phase 0)**
- **External API Integration**: WirelessTag OAuth client, IFTTT webhook client with safety features
- **Testing Infrastructure**: Comprehensive test suite with VCR simulation and extensive test coverage
- **Storage System**: Complete model-persistence layer with JsonStorageManager, Repository pattern, QueryBuilder
- **Safety Architecture**: Multiple safety layers, environment detection, test mode support
- **Authentication**: Token-based API access, master password system, secure credential storage

### ✅ **Phase 1: Complete Heating Control System** 
- **Cron Management System**: CronManager, CronSecurityManager, CronJobBuilder with secure API key authentication
- **Core Heating APIs**: StartHeatingAction, MonitorTempAction, StopHeatingAction fully implemented
- **Management APIs**: Complete user-facing API suite for scheduling and monitoring
  - `POST /api/schedule-heating` - Schedule future heating with overlap prevention
  - `POST /api/cancel-scheduled-heating` - Cancel scheduled heating events
  - `GET /api/list-heating-events` - Paginated event listing with filtering
  - `GET /api/heating-status` - Real-time system status and temperature
- **Configurable Heating Rate System**: User-configurable heating velocity with admin control
  - `GET /api/v1/admin/config/heating` - Get current heating configuration
  - `PUT /api/v1/admin/config/heating` - Update heating rate (0.1-2.0°F/min) with validation
  - HeatingConfig class with environment defaults and user override persistence
- **Enhanced Authentication System**: Token-based API access with admin/user roles
- **Admin Management APIs**: Bootstrap system and complete user/token management
  - `POST /api/admin/bootstrap` - Initial admin token creation
  - `POST /api/admin/user` - Create user tokens (admin only)
  - `GET /api/admin/users` - List all users (admin only)
- **Authentication Architecture**: Proper security base classes enforcing strict authentication
- **Integration Complete**: WirelessTag and IFTTT clients fully integrated with heating control logic
- **Comprehensive Testing**: Extensive test coverage with complete coverage for all operations
- **Safety Features**: Emergency stop, equipment safety sequences, orphaned cron cleanup
- **Bug Fixes**: Resolved all test failures and repository pattern issues

**✅ PHASE 1 COMPLETE**: Ready for web interface development (Phase 2)

---

## 🎯 **COMPLETED: Phase 1 - Heating Control APIs** ✅

*Duration: 2-3 weeks (Completed)*  
*Priority: Critical - Core functionality complete*

### ✅ **Implementation Completed:**

#### 1.1 **Cron Management System** ✅
*Completed with full test coverage*

**Implemented Components:**
```php
src/Services/CronManager.php              # Core cron manipulation ✅
src/Services/CronSecurityManager.php      # API key security management ✅
src/Domain/Heating/CronJobBuilder.php     # Safe cron command construction ✅
storage/cron-api-key.txt                  # Secure API key for cron calls ✅
storage/bin/cron-wrapper.sh              # Cron execution wrapper ✅
```

**Key Features Implemented:**
- ✅ Dynamic cron job creation/removal with type tagging (`HOT_TUB_START`, `HOT_TUB_MONITOR`)
- ✅ Secure API key management (separate from web API keys)
- ✅ Orphaned cron detection and cleanup utilities
- ✅ Comments-based cron identification for safe removal

**Testing Complete:**
- ✅ Mock cron operations for unit tests
- ✅ Comprehensive test coverage for all cron management components
- ✅ Test cron cleanup without affecting system crons

#### 1.2 **Core Heating APIs** ✅
*All APIs fully implemented with safety features*

**Priority APIs Completed:**
1. ✅ **`POST /api/start-heating`** - Begin heating cycle with temperature monitoring
2. ✅ **`GET /api/monitor-temp`** - Temperature checking with intelligent rescheduling  
3. ✅ **`POST /api/stop-heating`** - Emergency stop with complete cleanup

**Integration Points Complete:**
- ✅ WirelessTagClient integrated for temperature readings
- ✅ IftttWebhookClient integrated for equipment control
- ✅ HeatingCycle model used for state persistence
- ✅ HeatingEvent model used for scheduled monitoring
- ✅ Complete dependency injection configuration

**Algorithm Implementation:**
- ✅ Time-to-heat estimation based on temperature differential
- ✅ Intelligent monitoring intervals (coarse → precision control near target)
- ✅ Safety limits and timeout handling
- ✅ Equipment safety sequences (pump → heater → cooling cycle)

#### 1.3 **Management APIs** ✅
*Completed: Complete user-facing API suite*

**Implemented APIs:**
- ✅ **`POST /api/schedule-heating`** - User-facing API to schedule future heating with overlap prevention
- ✅ **`POST /api/cancel-scheduled-heating`** - Cancel future scheduled events
- ✅ **`GET /api/list-heating-events`** - Paginated enumeration of all heating events with filtering
- ✅ **`GET /api/heating-status`** - Real-time system status with current temperature

**API Documentation Required:**
- OpenAPI/Swagger specification for all endpoints
- Request/response schemas with validation examples
- Error codes and handling documentation
- Authentication requirements and API key usage

**Benefits:**
- Completes the heating control API suite
- Enables full API testing before UI development
- Provides documented endpoints for frontend integration
- Clear API contracts for future mobile app development

---

## 🎯 **CURRENT: Phase 2 - Web Interface Foundation** ⬅️ **IN PROGRESS**

*Estimated Duration: 3-4 weeks*
*Priority: High - User interface for complete system*

**Phase 1 Achievement Summary:**
- Complete heating control system with all core and management APIs
- **Enhanced authentication system with token-based access control**
- **Admin management system with bootstrap and user management**
- **Strict authentication architecture with proper base classes**
- Comprehensive test coverage with extensive passing test suite
- All repository pattern bugs fixed and tested
- Ready for frontend development with stable, secure API foundation

### ✅ **Phase 2.1: Frontend Foundation - COMPLETE**
- ✅ **Frontend Foundation**: React 19 + TypeScript + Vite project structure
- ✅ **Node Version Management**: Volta configured for automatic Node 22.19.0/npm 11.6.0 switching
- ✅ **Component Library**: Mobile-first UI components with Tailwind CSS v4
- ✅ **Mock Data System**: Complete development environment without backend dependency
- ✅ **Realistic Temperature Simulation**: Frontend-side heating cycle simulation
- ✅ **Development Scenarios**: Scenario switching for testing different states

### 🚧 **Phase 2.2: Backend Simulation Mode - IN PROGRESS** ⬅️ **CURRENT FOCUS**

**Goal:** Enable full frontend-backend integration with realistic simulation based on live hardware recordings.

**Core Principle:** Backend as single source of truth for operating mode. Simulation built from REAL recorded data, not theoretical physics.

#### **Backend Simulation Infrastructure** (Issues #28-#34)

**Phase 1: Live Data Recording**
- **#28** Create live heating cycle recording script
  - VCR recording of all HTTP interactions
  - Comprehensive logging with timestamps
  - Safety prompts and abort mechanisms

- **#29** Execute live heating cycle recording session
  - Record actual hardware heating cycle (30-60 min)
  - Capture real temperature progressions
  - Document equipment timing

- **#30** Create simulation data extraction tool
  - Parse VCR cassettes for temperature sequences
  - Calculate actual heating rates and variance
  - Extract equipment response times

**Phase 2: Backend Architecture**
- **#31** Create backend mode detection system (ModeDetector)
  - Single source of truth for mode (simulation/test/production)
  - Centralized detection logic used by all services

- **#32** Create simulation state manager with live data interpolation
  - Load extracted live recording data
  - Stateful temperature progression using real patterns
  - Scenario support (idle, heating, cooling, error)

- **#33** Update service factories to use ModeDetector
  - Refactor IftttWebhookClientFactory
  - Refactor WirelessTagClientFactory
  - Integrate with SimulationStateManager

- **#34** Add system info API endpoint for mode disclosure
  - `GET /api/system/info` - Expose mode to frontend
  - Mode headers in authenticated responses
  - Optional dev simulation control endpoints

#### **Frontend Integration** (Issues #35-#36)

- **#35** Create frontend API service layer with authentication
  - Axios HTTP client with interceptors
  - Replace mock hooks with real API hooks
  - React Query for data fetching and caching
  - Authentication token management

- **#36** Add frontend mode detection and dev UI indicators
  - Detect backend mode from system info endpoint
  - Simulation mode banner and status badges
  - Optional dev controls for scenario switching
  - Mode-aware UI features

#### **Integration Testing** (Issue #37)

- **#37** Integration testing: Full frontend-backend simulation mode
  - End-to-end testing of all components
  - Validate simulation accuracy vs live recording
  - Performance and timing verification
  - Safety verification (no hardware triggers)
  - Documentation and troubleshooting guide

**Key Benefits:**
- ✅ Simulation based on **real hardware data**, not assumptions
- ✅ Backend controls operating mode (single source of truth)
- ✅ Frontend works identically in simulation and production
- ✅ Safe development with no hardware risk
- ✅ Realistic temperature progressions for testing

### ⏳ **Phase 2.3: Production Features - PLANNED**
- Real-time Dashboard with live temperature monitoring
- Heating schedule management interface
- Mobile-responsive Design with PWA support
- Historical data visualization and analytics
- WebSocket/Server-Sent Events for live updates

---

## 📊 **Phase 2.2 Implementation Summary**

**Simulation Project GitHub Issues:** [#28-#37](https://github.com/science/HotTubController/issues)

**Total Issues:** 10 (all in "Ready to Implement" status)

**Implementation Approach:**
1. Record live hardware data first (foundation)
2. Build simulation from real data (not assumptions)
3. Backend determines mode (single source of truth)
4. Frontend adapts transparently to mode
5. Full integration testing with accuracy validation

**Expected Timeline:** 2-3 weeks
- Week 1: Live recording + Backend simulation infrastructure
- Week 2: Frontend integration + Testing
- Week 3: Validation + Documentation

**Success Criteria:**
- [ ] Full heating cycle recorded from live hardware
- [ ] Simulation temperature progression matches recorded data (±5%)
- [ ] Frontend connects to backend seamlessly
- [ ] Mode detection works correctly
- [ ] No hardware can be triggered in simulation mode
- [ ] Documentation complete for simulation mode usage

---

## 🎯 **Phase 3 - Advanced Features**

*Estimated Duration: 4-6 weeks*  
*Priority: Medium - Enhancement features*

### 3.1 **Intelligent Scheduling** 🧠
- Machine learning temperature prediction
- Weather integration for heating optimization
- Seasonal usage pattern analysis
- Predictive maintenance alerts

### 3.2 **Notification System** 📱
- Email/SMS heating completion alerts
- System error notifications
- Maintenance reminders
- Weekly usage reports

### 3.3 **Mobile Application** 📱
- Native iOS/Android app using React Native
- Push notifications for heating events
- Offline capability for viewing historical data
- Quick heating start/stop controls

---

## 🎯 **Phase 4 - Production Deployment**

*Estimated Duration: 2-3 weeks*  
*Priority: High - Needed for real-world use*

### 4.1 **Production Infrastructure** 🚀
- Docker containerization
- CI/CD pipeline setup
- Production environment configuration
- SSL certificate management
- Domain setup and DNS configuration

### 4.2 **Monitoring & Observability** 📊
- Application performance monitoring
- Error tracking and alerting
- System health dashboards
- Log aggregation and analysis

### 4.3 **Backup & Recovery** 💾
- Automated database backups
- Configuration backup procedures
- Disaster recovery planning
- Data retention policies

---

## 📋 **Implementation Guidelines**

### **Start with Phase 1.1** 👈
The next logical step is implementing the **CronManager system** since:
1. It's required by all heating control APIs
2. It's self-contained and testable
3. It establishes the security foundation for cron-based operations

### **Development Best Practices**
- **Test-First**: Write tests before implementation for all new APIs
- **Safety-First**: All heating operations must have emergency stop capability
- **Documentation**: Update API docs as endpoints are implemented
- **Code Quality**: Maintain PSR-12 standards and >90% test coverage

### **External Dependencies**
- **WirelessTag API**: OAuth setup guide in `docs/wirelesstag-oauth.md`
- **IFTTT Webhooks**: Event configuration documented in `docs/external-apis.md`
- **Cron System**: Ensure cron service is available and properly configured

---

## 🔄 **Iterative Approach**

Each phase should be:
1. **Fully implemented** with comprehensive tests
2. **Documented** with API specifications and user guides
3. **Deployed** to staging environment for validation
4. **User tested** with real heating scenarios
5. **Production ready** before moving to next phase

This approach ensures each component is solid and reliable before building on top of it.

---

## 💡 **Success Metrics**

### **Phase 1 Success Criteria:** ✅ **COMPLETE**
- [✅] Can schedule heating start via cron
- [✅] Can monitor temperature and reschedule checks automatically
- [✅] Can emergency stop with complete cleanup
- [✅] All operations are logged and auditable
- [✅] System handles sensor failures gracefully

### **Phase 2 Success Criteria:**
- [ ] Web interface shows real-time temperature
- [ ] Can create/modify heating schedules via web
- [ ] Mobile-responsive interface works on phones/tablets
- [ ] Historical data is viewable and useful

### **Overall Project Success:**
- [ ] Family members can easily schedule hot tub heating
- [ ] System runs reliably without manual intervention
- [ ] Heating efficiency is optimized vs. manual control
- [ ] System is maintainable and extensible

---

**🚀 Ready to begin Phase 2.1: React Frontend Setup**

This roadmap provides clear next steps while maintaining the flexibility to adapt based on real-world testing and user feedback.

---

## 🎉 **Phase 1 Implementation Notes**

**Total Duration:** ~2.5 weeks (September 2025)  
**Lines of Code Added:** 3,600+ lines  
**Test Coverage:** 15+ new test files with comprehensive cron and heating API coverage  
**Key Achievements:**
- Complete cron-based heating automation system
- Secure API key management separate from web authentication  
- Emergency stop capabilities with complete equipment cleanup
- Integration with existing WirelessTag and IFTTT infrastructure
- Production-ready with comprehensive safety features

**Next Phase Focus:** Building the web interface to provide user-friendly access to the heating control APIs.