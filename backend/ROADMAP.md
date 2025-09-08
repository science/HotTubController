# 🗺️ Hot Tub Controller - Development Roadmap

## 📍 Current Status (September 2025)

### ✅ **Foundation Complete (Phase 0)**
- **External API Integration**: WirelessTag OAuth client, IFTTT webhook client with safety features
- **Testing Infrastructure**: 346+ tests passing, VCR simulation, comprehensive test coverage
- **Storage System**: Complete model-persistence layer with JsonStorageManager, Repository pattern, QueryBuilder
- **Safety Architecture**: Multiple safety layers, environment detection, test mode support
- **Authentication**: Token-based API access, master password system, secure credential storage

### ✅ **Phase 1: Heating Control APIs Complete**
- **Cron Management System**: CronManager, CronSecurityManager, CronJobBuilder with secure API key authentication
- **Core Heating APIs**: StartHeatingAction, MonitorTempAction, StopHeatingAction fully implemented
- **Integration Complete**: WirelessTag and IFTTT clients integrated with heating control logic
- **Comprehensive Testing**: Full test coverage for all cron operations and heating control components
- **Safety Features**: Emergency stop, equipment safety sequences, orphaned cron cleanup

**Ready for**: Web interface development (Phase 2)

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

#### 1.3 **Management APIs** 📅
*NEXT IMMEDIATE PRIORITY: Complete API layer before frontend*

**Implementation Target: 2-3 days**
- **`POST /api/schedule-heating`** - User-facing API to schedule future heating  
- **`POST /api/cancel-scheduled-heating`** - Cancel future start events
- **`GET /api/list-heating-events`** - Enumerate scheduled and active events  
- **`GET /api/heating-status`** - Real-time system status with current temperature

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

## 🎯 **NEXT: Phase 1.3 Management APIs** ⬅️ **START HERE**

*Estimated Duration: 2-3 days*  
*Priority: Critical - Complete API layer*

**High-level project priority strategy: Why Management APIs First:**
- Frontend needs complete API specification to begin development
- API testing can validate entire heating control workflow
- Clear separation between backend completion and UI development
- Documentation serves as contract for future frontend work

**Detailed Management API Plan**
_Management APIs Implementation Plan_

1. Schedule Heating API (POST /api/schedule-heating)
Purpose: User-facing API to schedule future heating cycles
Implementation:
- Create ScheduleHeatingAction.php
- Accept parameters: start_time, target_temp (default: 102°F), optional name/description
  - target_temp can be in 1/10 (0.1) degree increments: e.g. 101.9, 102, 102.1 are all valid inputs
  - target_temp range can >50 <110
  - if target_temp is less than the measured temp, no action should be taken, but notification in API should be provided to caller, so it knows that the hot tub is already at the desired temperature.
- Validate authentication (token-based, not cron API key)
  - User based validation: system will store/track issued API keys by user. If a user frontend presents their API key, the system will accept it and run the commands; others APIs error with Not Authorized status.
    - Users all share cron events: cron heating events are system-wide, so all users can see/schedule/edit/cancel all heating events on the hot tub. There is no per-user-isolation of heating scheduled.
  - Admin based key issuance: During deployment a hardcoded admin key will be generated and stored in a specific, designated location. This key will be accepted for user management functions as well as other API functions. So this admin key can be used to "mint" new user-based keys, that can then be distributed to users (as well as delete users or user keys)
- Create HeatingEvent with status=SCHEDULED
- Use CronJobBuilder to create cron expression
- Schedule cron job via CronManager
- Return event ID and confirmation
Key Features:
- Prevent duplicate schedules for same time
  - Monitor for registration of new heating schedules that overlap with projected duration of a scheduled heating event. Use default heating duration estimate of 30 minutes. (e.g., If a heating event is scheduled for 630am tomorrow, and another event is scheduled within 30 minutes, the second event will be rejected unless the first event is canceled. The API will inform the frontend as to why the event scheduling is rejected: overlapping heating event detected. The API will inform the frontend as to precisely which cron job is the cause for the failure/rejection.)
- Validate future times only (no past scheduling)
- Temperature safety validation (50-110°F)
- Optional recurrence support (future enhancement)
  - Currently no recurring heating events will be accepted by the API. Recurring scheduling will be an entirely new feature with entirely new APIs handling that.
2. Cancel Scheduled Heating API (POST /api/cancel-scheduled-heating)
Purpose: Cancel future scheduled heating events
Implementation:
- Create CancelScheduledHeatingAction.php
- Accept parameter: event_id
- Validate event exists and is SCHEDULED status
- Remove associated cron job via CronManager
- Update HeatingEvent status to CANCELLED
- Return confirmation
Security:
- Only allow cancelling SCHEDULED events (not active)
- Verify ownership/permissions (future enhancement)
3. List Heating Events API (GET /api/list-heating-events)
Purpose: Enumerate all heating events (scheduled, active, completed)
Implementation:
- Create ListHeatingEventsAction.php
- Query parameters: status filter, from/to date range, limit, offset
- Use HeatingEventRepository with QueryBuilder
- Include related HeatingCycle data for triggered events
- Return paginated results with metadata
Response includes:
- Event details (ID, type, status, scheduled time)
- Associated cycle info (for active/completed)
- Cron expression and next execution time
- Sorting by scheduled_for DESC (most recent first)
4. Heating Status API (GET /api/heating-status)
Purpose: Real-time system status with current temperature
Implementation:
- Create HeatingStatusAction.php
- No authentication required (read-only status)
- Get current temperature from WirelessTagClient
- Query active HeatingCycle (status=HEATING)
- Include upcoming scheduled events
- Return comprehensive status
Response includes:
- Current water temperature
- Active heating cycle (if any) with progress
- Next scheduled event
- System health indicators
- Last update timestamp
5. Authentication Middleware
Purpose: Secure management APIs with token validation
Implementation:
- Create TokenValidationMiddleware.php
- Use existing token system from TokenManager
- Apply to management routes (except status endpoint)
- Support Bearer token in Authorization header
- Return 401 for invalid/missing tokens
6. API Documentation
Purpose: OpenAPI/Swagger specification
Implementation:
- Create docs/api-specification.yaml
- Document all endpoints with schemas
- Include authentication requirements
- Provide request/response examples
- Error code documentation
7. Integration Tests
Purpose: Validate complete workflow
Tests to create:
- tests/Integration/HeatingManagementWorkflowTest.php
- Test scheduling → monitoring → completion flow
- Test cancellation scenarios
- Test concurrent scheduling edge cases
- Test API authentication
File Structure:
backend/
├── src/Application/Actions/Heating/
│   ├── ScheduleHeatingAction.php      (new)
│   ├── CancelScheduledHeatingAction.php (new)
│   ├── ListHeatingEventsAction.php    (new)
│   └── HeatingStatusAction.php        (new)
├── src/Application/Middleware/
│   └── TokenValidationMiddleware.php  (new)
├── docs/
│   └── api-specification.yaml         (new)
└── tests/Integration/
    └── HeatingManagementWorkflowTest.php (new)
Route Updates:
Add to config/routes.php:
- /api/schedule-heating (POST, authenticated)
- /api/cancel-scheduled-heating (POST, authenticated)
- /api/list-heating-events (GET, authenticated)
- /api/heating-status (GET, public)
Dependency Updates:
Update config/dependencies.php to wire new actions with required services.
Testing Strategy:
1. Unit tests for each new action
2. Integration tests for complete workflows
3. Manual testing with curl/Postman
4. VCR cassettes for external API interactions
This completes the heating control API suite, enabling full testing before UI development and providing clear API contracts for frontend integration.



## 1.4 **User management APIs and system**
 - Not yet defined: develop user management architecture, based on needs from 1.3 API system.
 - Implement user management system


---

## 🎯 **THEN: Phase 2 - Web Interface Foundation**

*Estimated Duration: 3-4 weeks*  
*Priority: High - Needed for usable system*

### 2.1 **React Frontend Setup** ⚛️
*Duration: 4-6 days*

**Project Structure:**
```
frontend/
├── src/
│   ├── components/
│   │   ├── Dashboard/          # Real-time temperature display
│   │   ├── Scheduling/         # Heat scheduling interface
│   │   ├── History/            # Historical data visualization
│   │   └── Controls/           # Manual override controls
│   ├── services/
│   │   ├── api.js             # Backend API client
│   │   ├── websocket.js       # Real-time updates
│   │   └── auth.js            # Authentication management
│   └── utils/
├── public/
└── package.json
```

**Key Features:**
- Real-time temperature monitoring dashboard
- Heating schedule management interface
- Mobile-responsive design (PWA capabilities)
- Authentication and secure API communication


### 2.2 **Real-time Communication** 📡
*Duration: 3-4 days*

**Backend additions:**
- WebSocket/Server-Sent Events for live temperature updates
- Real-time heating status notifications
- Equipment status monitoring

### 2.3 **Historical Analytics** 📈
*Duration: 5-7 days*

**Features:**
- Heating cycle performance tracking
- Temperature trend visualization
- Energy usage estimation
- System health monitoring

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