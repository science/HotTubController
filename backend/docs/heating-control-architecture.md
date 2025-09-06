# Hot Tub Heating Control System Architecture

## Overview

This document defines the architecture for the intelligent heating control system that manages hot tub temperature through dynamic cron scheduling and precise monitoring loops.

## Core Concepts

### Heating Event Types

The system manages two distinct types of scheduled events:

1. **Future Heating Start Events** (`HOT_TUB_START`)
   - User schedules heating to begin at a future time (e.g., "heat at 6:30 AM tomorrow")
   - Assumption: heater/pump is currently off
   - Creates cron job to trigger `/api/start-heating` at scheduled time

2. **Active Cycle Monitoring Events** (`HOT_TUB_MONITOR`) 
   - Ongoing temperature monitoring during active heating cycle
   - Schedules periodic temperature checks via `/api/monitor-temp`
   - Switches to tight polling (15s) when target temperature is imminent

### Intelligent Scheduling Strategy

1. **Initial Schedule**: User sets heating start time (e.g., 6:30 AM)
2. **Heating Start**: Measure current temp, start heater/pump, estimate time-to-heat
3. **Coarse Monitoring**: Schedule next temp check based on time estimate
4. **Precision Control**: When <1 minute to target, enter tight 15-second polling loop
5. **Completion**: Turn off heater/pump, clean up monitoring crons

## Security Model

### API Authentication
- **Cron API Key Storage**: `./storage/cron-api-key.txt`
- **File Permissions**: `chmod 600` (owner read/write only)
- **Key Format**: `api_<64-char-hex>` generated via `openssl rand -hex 32`
- **Cron Usage**: Inject the location of the api-key when creating the cron job using a cron config file in the storage folder that points to the correct api key as a data element: this will keep the api key out of `ps` as well as logs and crontabs.
- **Key Rotation**: Update file, all existing crons automatically use new key

### Cron Command Format
```bash
# Future start event
30 6 * * * curl --config [/calculated/path/to/project]/storage/curl-api-call.conf >/dev/null 2>&1 # HOT_TUB_START:abc123

# Active monitoring  
15 7 * * * curl --config [/calculated/path/to/project]/storage/curl-api-call.conf >/dev/null 2>&1 # HOT_TUB_MONITOR:def456
```

```
# Curl Config file
--silent
--show-error
--get
--url "https://site.com/api/api-path"
--data-urlencode "auth@/home/youruser/project/storage/cron-api-key.txt" 
--data-urlencode "id=123456"
```

## API Endpoints

### Core Heating Control

#### `POST /api/start-heating`
**Purpose**: Begin new heating cycle from scheduled start time

**Parameters**:
- `id` (string): Unique identifier for this heating event
- `auth` (string): API authentication key
- `target_temp` (optional, float): Target temperature (default from user profile)

**Process**:
1. Validate API key
2. Read current temperature from wireless tag API
3. Turn on heater/pump via control relay
4. Calculate estimated time-to-heat based on temperature differential
5. Schedule first monitoring check via cron
6. Remove the triggering `HOT_TUB_START` cron
7. Return cycle ID and estimated completion

**Response**:
```json
{
  "cycle_id": "def456",
  "current_temp": 92.5,
  "target_temp": 104.0,
  "estimated_completion": "2025-09-06T07:45:00Z",
  "next_check": "2025-09-06T07:15:00Z"
}
```

#### `GET /api/monitor-temp`
**Purpose**: Check temperature and manage active heating cycle

**Parameters**:
- `cycle_id` (string): Active heating cycle identifier  
- `auth` (string): API authentication key

**Process**:
1. Validate API key and cycle ID
2. Read current temperature
3. Check if target temperature reached
4. If target reached: Turn off heater/pump, clean up monitoring crons
5. If still heating: Calculate next check time or enter tight polling
6. Update cycle status in storage

**Response**:
```json
{
  "cycle_id": "def456", 
  "current_temp": 101.2,
  "target_temp": 104.0,
  "status": "heating|completed|error",
  "next_check": "2025-09-06T07:30:00Z",
  "time_remaining_estimate": "15 minutes"
}
```

### Control & Management

#### `POST /api/stop-heating`
**Purpose**: Emergency stop of active heating cycle

**Parameters**:
- `auth` (string): API authentication key
- `cycle_id` (optional, string): Specific cycle to stop (default: all active)

**Process**:
1. Turn off heater/pump immediately
2. Remove ALL `HOT_TUB_MONITOR` crons (active cycle only)
3. Preserve `HOT_TUB_START` crons (future scheduled events)
4. Log emergency stop event
5. Update cycle status to "stopped"

#### `POST /api/cancel-scheduled-heating`
**Purpose**: Cancel future heating start events

**Parameters**:
- `auth` (string): API authentication key
- `schedule_id` (optional, string): Specific event to cancel (default: all future)

**Process**:
1. Remove matching `HOT_TUB_START` crons
2. Leave `HOT_TUB_MONITOR` crons untouched (active cycle continues)
3. Return list of canceled events

#### `GET /api/list-heating-events`
**Purpose**: Enumerate all scheduled and active heating events

**Response**:
```json
{
  "scheduled_starts": [
    {
      "id": "abc123",
      "scheduled_time": "2025-09-06T06:30:00Z", 
      "target_temp": 104.0,
      "created": "2025-09-05T20:15:00Z"
    }
  ],
  "active_cycles": [
    {
      "cycle_id": "def456",
      "started": "2025-09-05T18:30:00Z",
      "current_temp": 101.2,
      "target_temp": 104.0, 
      "next_check": "2025-09-05T18:45:00Z",
      "status": "heating"
    }
  ]
}
```

## Data Storage

### File-based Storage Structure
```
[project folder path]/storage/
├── curl-api-key.txt              # API authentication key
├── active-cycles.json       # Current heating cycles state
├── heating-history.json     # Completed heating events log
└── system-config.json       # Default temperatures, safety limits
```

### Active Cycles Storage (`active-cycles.json`)
```json
{
  "cycles": [
    {
      "cycle_id": "def456",
      "started": "2025-09-05T18:30:00Z",
      "current_temp": 101.2,
      "target_temp": 104.0,
      "status": "heating",
      "last_temp_check": "2025-09-05T18:42:00Z",
      "estimated_completion": "2025-09-05T19:00:00Z"
    }
  ]
}
```

## Cron Management Utilities

### Core Functions

```php
class CronManager 
{
    // Add cron with type-specific comment
    public function addStartEvent(string $scheduleId, DateTime $startTime): void;
    public function addMonitoringEvent(string $cycleId, DateTime $checkTime): void;
    
    // Remove crons by type
    public function removeStartEvents(?string $scheduleId = null): array;
    public function removeMonitoringEvents(?string $cycleId = null): array;
    public function removeAllHotTubEvents(): array;
    
    // List existing crons
    public function listStartEvents(): array;
    public function listMonitoringEvents(): array;
}
```

## Safety Features

### Temperature Limits
- **Maximum Temperature**: 106°F (safety cutoff)
- **Maximum Heating Duration**: 4 hours (prevents runaway heating)
- **Sensor Timeout**: 5 minutes (detect wireless tag failures)

### Error Handling
- **Sensor Failure**: Stop heating, alert user, clean up crons
- **Relay Failure**: Log error, attempt manual shutoff via backup method
- **Cron Cleanup**: Orphaned cron detection and removal
- **Auth Failure**: Rate limiting on invalid API key attempts

### Manual Overrides
- **Emergency Stop**: `/api/stop-heating` accessible via web interface
- **Manual Control**: Direct heater on/off bypass for maintenance
- **Schedule Override**: Temporary disable of all automation

## Integration Points

### External APIs

The heating control system integrates with external services for temperature monitoring and equipment control. Detailed API documentation is available in separate files:

#### WirelessTag Temperature Monitoring
- **Purpose**: Wireless temperature sensor data for hot tub water and ambient readings
- **Implementation**: REST API with OAuth 2.0 Bearer token authentication
- **Documentation**: See [`external-apis.md`](./external-apis.md#wirelesstag-temperature-monitoring-api)
- **Usage Patterns**:
  - Cached readings via `GetTagList` for routine monitoring (every 2-5 minutes)
  - Fresh readings via `RequestImmediatePostback` for critical decisions (heating start/stop)
  - Battery conservation through intelligent polling strategies
- **Integration Points**:
  - `/api/start-heating`: Get fresh temperature before starting heating cycle
  - `/api/monitor-temp`: Regular temperature checks during heating
  - Temperature safety validation and sensor failure detection

#### IFTTT Webhook Equipment Control
- **Purpose**: Hot tub heater, pump, and ionizer control through SmartLife automation
- **Implementation**: Simple HTTP webhooks with API key authentication
- **Documentation**: See [`external-apis.md`](./external-apis.md#ifttt-webhook-api)
- **Controlled Equipment**:
  - Hot tub heating system (safe start/stop sequences)
  - Water ionization system
  - Pump circulation control
- **Integration Points**:
  - `/api/start-heating`: Trigger `hot-tub-heat-on` webhook
  - `/api/stop-heating`: Trigger `hot-tub-heat-off` webhook
  - Emergency stop procedures and manual override capabilities

#### API Implementation Details
- **Error Handling**: Comprehensive retry logic with exponential backoff
- **Security**: Encrypted credential storage and secure token management
- **Monitoring**: API health checks and failure alerting
- **Configuration**: Flexible device and endpoint configuration management
- **Documentation**: Complete implementation guide available in [`api-implementation-guide.md`](./api-implementation-guide.md)

### Temperature Data Processing

#### Data Sources and Accuracy
- **Primary Temperature**: WirelessTag water temperature sensor (`temperature` field)
- **Ambient Temperature**: WirelessTag air temperature sensor (`cap` field)
- **Calibration**: Ambient temperature adjusted for thermal influence from heated water
- **Validation**: Temperature bounds checking and sensor health monitoring

#### Battery Conservation Strategy
```
Routine Monitoring:     GetTagList every 2-5 minutes (cached data)
Critical Decisions:     RequestImmediatePostback + GetTagList (fresh data)
Heating Start:          Fresh reading required
Target Near (±2°F):     Fresh readings every 15 seconds
Heating Complete:       Fresh reading to confirm target reached
Emergency Stop:         Use last cached reading (time-critical)
```

#### Temperature Processing Pipeline
1. **Raw Data Retrieval**: API call to WirelessTag service
2. **Data Validation**: Bounds checking and sensor health verification  
3. **Unit Conversion**: Celsius to Fahrenheit conversion
4. **Calibration**: Environmental compensation for ambient readings
5. **Safety Checks**: Maximum temperature limits and sensor timeout detection
6. **Storage**: Cache processed readings for fallback scenarios

### Equipment Control Integration

#### IFTTT Automation Sequences
The system relies on pre-configured IFTTT automation scenes for safe equipment operation:

**Heating Start Sequence** (`hot-tub-heat-on`):
1. Activate water circulation pump
2. Wait for proper water flow (prevents dry heating)
3. Enable heating element
4. Optional: Start ionization system

**Heating Stop Sequence** (`hot-tub-heat-off`):
1. Disable heating element immediately
2. Continue pump operation for heater cooling
3. Stop pump after cooling period
4. Optional: Stop ionization system

#### Safety Interlocks
- **Dry Heat Prevention**: Pump must be running before heater activation
- **Thermal Protection**: Heater cooling cycle before pump shutdown
- **Emergency Stop**: Immediate heater shutdown with override capability
- **Maximum Runtime**: 4-hour heating limit to prevent equipment damage

### Failure Scenarios and Graceful Degradation

#### WirelessTag API Unavailable
- **Detection**: HTTP timeouts, authentication failures, or invalid responses
- **Fallback**: Use last known temperature readings with age warnings
- **Action**: Continue with caution, implement shorter heating cycles
- **Alert**: Notify administrators of sensor communication failure

#### IFTTT Service Unavailable  
- **Detection**: Webhook failures or timeout responses
- **Fallback**: No automated equipment control capability
- **Action**: Alert user for manual intervention, disable new heating schedules
- **Safety**: Maintain emergency stop capability through alternative methods

#### Partial Service Degradation
- **Monitoring Only**: If equipment control fails but temperature monitoring works
- **Control Only**: If sensors fail but equipment control is available
- **Manual Override**: Web interface bypass for critical operations
- **Status Reporting**: Clear indication of system capabilities to users

### User Notification Integration

### Web Interface Integration
- Real-time temperature display via WebSocket/SSE
- Schedule creation and management interface
- Historical data visualization
- Manual control panel for emergency situations

## Implementation Phases

### Phase 1: Core Infrastructure
- [ ] **External API Integration**
  - [ ] WirelessTag OAuth token acquisition and secure storage
  - [ ] IFTTT webhook client implementation
  - [ ] Temperature data processing and calibration
  - [ ] Equipment control safety sequences
- [ ] **Authentication & Security**
  - [ ] API key generation and storage system
  - [ ] Encrypted configuration management
  - [ ] Rate limiting and abuse prevention
- [ ] **Basic System Components**
  - [ ] Cron management utilities
  - [ ] File-based storage system
  - [ ] Logging and error handling framework

### Phase 2: Heating Control Logic  
- [ ] `/api/start-heating` endpoint implementation
- [ ] `/api/monitor-temp` endpoint with intelligent rescheduling
- [ ] Time-to-heat estimation algorithms
- [ ] Safety shutoff mechanisms

### Phase 3: Management & Control
- [ ] `/api/stop-heating` emergency stop
- [ ] `/api/cancel-scheduled-heating` future event management
- [ ] `/api/list-heating-events` enumeration
- [ ] Cron cleanup and maintenance utilities

### Phase 4: Web Interface
- [ ] Real-time temperature monitoring
- [ ] Schedule management interface
- [ ] Historical data tracking
- [ ] Mobile-responsive design

This architecture provides the foundation for a reliable, intelligent heating control system that leverages shared hosting capabilities while maintaining precise control over hot tub temperature management.