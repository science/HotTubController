# 🗺️ Hot Tub Controller - Development Roadmap

## 📍 Current Status (September 2025)

### ✅ **Foundation Complete (Phase 0)**
- **External API Integration**: WirelessTag OAuth client, IFTTT webhook client with safety features
- **Testing Infrastructure**: 346+ tests passing, VCR simulation, comprehensive test coverage
- **Storage System**: Complete model-persistence layer with JsonStorageManager, Repository pattern, QueryBuilder
- **Safety Architecture**: Multiple safety layers, environment detection, test mode support
- **Authentication**: Token-based API access, master password system, secure credential storage

**Ready for**: Core heating control API development

---

## 🎯 **NEXT: Phase 1 - Heating Control APIs** ⬅️ **START HERE**

*Estimated Duration: 2-3 weeks*  
*Priority: Critical - This is the core functionality*

### Implementation Order:

#### 1.1 **Cron Management System** 📅
*Duration: 3-5 days*

**What to Build:**
```php
src/Services/CronManager.php              # Core cron manipulation
src/Domain/Heating/CronJobBuilder.php     # Safe cron command construction
storage/cron-api-key.txt                  # Secure API key for cron calls
storage/curl-config.conf                  # Curl configuration template
```

**Key Features:**
- Dynamic cron job creation/removal with type tagging (`HOT_TUB_START`, `HOT_TUB_MONITOR`)
- Secure API key management (separate from web API keys)
- Orphaned cron detection and cleanup utilities
- Comments-based cron identification for safe removal

**Testing Requirements:**
- Mock cron operations for unit tests
- Integration tests with temporary crontab manipulation
- Test cron cleanup without affecting system crons

#### 1.2 **Core Heating APIs** 🔥
*Duration: 7-10 days*

**Priority Order:**
1. **`POST /api/start-heating`** - Begin heating cycle with temperature monitoring
2. **`GET /api/monitor-temp`** - Temperature checking with intelligent rescheduling  
3. **`POST /api/stop-heating`** - Emergency stop with complete cleanup

**Integration Points:**
- Use existing WirelessTagClient for temperature readings
- Use existing IftttWebhookClient for equipment control
- Use HeatingCycle model for state persistence
- Use HeatingEvent model for scheduled monitoring

**Algorithm Development:**
- Time-to-heat estimation based on temperature differential
- Intelligent monitoring intervals (coarse → precision control near target)
- Safety limits and timeout handling

#### 1.3 **Management APIs** 📊
*Duration: 2-3 days*

- **`POST /api/cancel-scheduled-heating`** - Cancel future start events
- **`GET /api/list-heating-events`** - Enumerate scheduled and active events
- **`GET /api/heating-status`** - Real-time system status

---

## 🎯 **Phase 2 - Web Interface Foundation**

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

### **Phase 1 Success Criteria:**
- [ ] Can schedule heating start via cron
- [ ] Can monitor temperature and reschedule checks automatically
- [ ] Can emergency stop with complete cleanup
- [ ] All operations are logged and auditable
- [ ] System handles sensor failures gracefully

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

**🚀 Ready to begin Phase 1.1: CronManager implementation**

This roadmap provides clear next steps while maintaining the flexibility to adapt based on real-world testing and user feedback.