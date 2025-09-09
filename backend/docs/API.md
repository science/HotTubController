# Hot Tub Controller API Documentation

## Overview

The Hot Tub Controller API provides intelligent temperature management and equipment control for hot tub systems. This RESTful API is designed for frontend integration and supports scheduled heating cycles, real-time temperature monitoring, and emergency safety controls.

**Base URL**: `http://localhost:8080` (development) or your deployed domain  
**API Version**: v1  
**Content Type**: `application/json`

## Authentication

The API uses role-based authentication with multiple methods depending on the endpoint type:

### 1. Master Password Authentication
Used for administrative operations and initial setup.

```bash
curl -X POST http://localhost:8080/api/v1/auth \
  -H "Content-Type: application/json" \
  -d '{"password": "your_master_password"}'
```

**Response:**
```json
{
  "authenticated": true,
  "message": "Master authentication successful"
}
```

### 2. Token-Based Authentication
Used for regular API access. Get a token first:

```bash
curl -X POST http://localhost:8080/api/v1/admin/user \
  -H "Content-Type: application/json" \
  -d '{
    "master_password": "your_master_password",
    "name": "Frontend App",
    "role": "user"
  }'
```

**Response:**
```json
{
  "token": "htc_1a2b3c4d5e6f...",
  "user_id": "user_123",
  "role": "user",
  "created": "2025-09-09T10:30:00+00:00"
}
```

Use the token in subsequent requests:
```bash
curl -H "Authorization: Bearer htc_1a2b3c4d5e6f..."
```

### 3. Cron API Key Authentication
Used for system-to-system communication (heating control endpoints triggered by cron jobs).

### Authentication Summary by Endpoint Type

- **Public Status**: `/` (minimal service info, no sensitive data)
- **User Authenticated**: Most heating APIs, proxy (`/api/heating-status`, `/api/schedule-heating`, `/api/v1/proxy`)
- **Admin Authenticated**: Emergency stop manual triggers (`/api/stop-heating` with Bearer token)
- **Master Password**: Admin token management (`/api/v1/admin/*`)
- **Cron API Key**: System heating control (`/api/start-heating`, `/api/monitor-temp`)

## Core APIs for Frontend Integration

### System Status

#### GET `/api/heating-status`
Get real-time system status including temperature, active cycles, and next scheduled events.

**Authentication**: Required (Bearer token with user or admin role)

```bash
curl -H "Authorization: Bearer htc_1a2b3c4d5e6f..." \
     http://localhost:8080/api/heating-status
```

**Response:**
```json
{
  "timestamp": "2025-09-09T15:30:00+00:00",
  "temperature": {
    "value": 88.5,
    "unit": "fahrenheit",
    "sensor_name": "Hot Tub Sensor",
    "last_updated": "2025-09-09T15:29:30+00:00",
    "battery_level": 85,
    "signal_strength": -45
  },
  "active_cycle": {
    "id": "cycle_abc123",
    "status": "heating",
    "started_at": "2025-09-09T14:00:00+00:00",
    "target_temp": 104.0,
    "current_temp": 88.5,
    "elapsed_time_seconds": 5400,
    "temperature_difference": 15.5,
    "progress": 0.45,
    "estimated_time_remaining_seconds": 1800
  },
  "next_scheduled_event": {
    "id": "event_xyz789",
    "scheduled_for": "2025-09-09T18:00:00+00:00",
    "target_temp": 102.0,
    "name": "Evening Heat",
    "time_until_execution": 9000
  },
  "system_health": {
    "status": "healthy",
    "issues": [],
    "statistics": {
      "scheduled_events": 3,
      "active_cycles": 1,
      "past_due_events": 0
    }
  }
}
```

### Heating Control

#### POST `/api/schedule-heating`
Schedule a future heating cycle. Temperature checks are performed when the heating actually starts, not during scheduling.

**Authentication**: Required (Bearer token)

```bash
curl -X POST http://localhost:8080/api/schedule-heating \
  -H "Authorization: Bearer htc_1a2b3c4d5e6f..." \
  -H "Content-Type: application/json" \
  -d '{
    "start_time": "2025-09-09T18:00:00",
    "target_temp": 104.0,
    "name": "Evening Session",
    "description": "Pre-dinner heating cycle"
  }'
```

**Parameters:**
- `start_time` (required): ISO 8601 datetime string, must be in the future
- `target_temp` (optional): Target temperature in Fahrenheit (50-110°F, default: 102°F)
- `name` (optional): Display name for the heating event
- `description` (optional): Additional description
- `role` (optional): Token role when creating admin tokens ("user" or "admin", default: "user")

**Response:**
```json
{
  "status": "scheduled",
  "event_id": "event_abc123",
  "start_time": "2025-09-09T18:00:00+00:00",
  "target_temp": 104.0,
  "current_temp": 88.5,
  "name": "Evening Session",
  "description": "Pre-dinner heating cycle",
  "cron_id": "cron_xyz789"
}
```

#### POST `/api/cancel-scheduled-heating`
Cancel a scheduled heating event.

**Authentication**: Required (Bearer token)

```bash
curl -X POST http://localhost:8080/api/cancel-scheduled-heating \
  -H "Authorization: Bearer htc_1a2b3c4d5e6f..." \
  -H "Content-Type: application/json" \
  -d '{"event_id": "event_abc123"}'
```

**Response:**
```json
{
  "status": "cancelled",
  "event_id": "event_abc123",
  "message": "Scheduled heating event cancelled successfully",
  "was_scheduled_for": "2025-09-09T18:00:00+00:00",
  "target_temp": 104.0,
  "name": "Evening Session"
}
```

#### POST `/api/stop-heating`
Emergency stop for active heating cycles.

**Authentication**: Required (Admin Bearer token for manual stops, or cron API key for automated stops)

```bash
curl -X POST http://localhost:8080/api/stop-heating \
  -H "Authorization: Bearer htc_1a2b3c4d5e6f..." \
  -H "Content-Type: application/json" \
  -d '{
    "cycle_id": "cycle_abc123",
    "reason": "user_request"
  }'
```

**Parameters:**
- `cycle_id` (optional): Specific cycle to stop, omit to stop all active cycles
- `reason` (optional): Stop reason (`manual_stop`, `emergency`, `safety_limit`, etc.)

**Response:**
```json
{
  "status": "stopped",
  "stopped_cycles": [
    {
      "cycle_id": "cycle_abc123",
      "started_at": "2025-09-09T14:00:00+00:00",
      "target_temp": 104.0,
      "final_temp": 92.3
    }
  ],
  "removed_monitoring_crons": 2,
  "current_temp": 92.3,
  "stop_reason": "user_request",
  "stopped_at": "2025-09-09T15:45:00+00:00",
  "message": "Heating stopped successfully"
}
```

### Event Management

#### GET `/api/list-heating-events`
List heating events with filtering and pagination.

**Authentication**: Required (Bearer token)

```bash
curl "http://localhost:8080/api/list-heating-events?status=scheduled&limit=20&offset=0" \
  -H "Authorization: Bearer htc_1a2b3c4d5e6f..."
```

**Query Parameters:**
- `status` (optional): Filter by status (`scheduled`, `triggered`, `cancelled`, `error`)
- `event_type` (optional): Filter by type (`start`, `monitor`)
- `from_date` (optional): Start date filter (ISO 8601)
- `to_date` (optional): End date filter (ISO 8601)
- `limit` (optional): Number of results (1-100, default: 20)
- `offset` (optional): Pagination offset (default: 0)

**Response:**
```json
{
  "events": [
    {
      "id": "event_abc123",
      "event_type": "start",
      "status": "scheduled",
      "scheduled_for": "2025-09-09T18:00:00+00:00",
      "target_temp": 104.0,
      "name": "Evening Session",
      "description": "Pre-dinner heating cycle",
      "created_at": "2025-09-09T10:00:00+00:00",
      "next_execution": "2025-09-09T18:00:00+00:00",
      "time_until_execution": 9000,
      "metadata": {
        "scheduled_via_api": true,
        "cron_id": "cron_xyz789"
      }
    }
  ],
  "pagination": {
    "total": 15,
    "limit": 20,
    "offset": 0,
    "has_more": false
  },
  "filters": {
    "status": "scheduled"
  }
}
```

### Admin Endpoints

#### POST `/api/v1/admin/user`
Create a new API token.

**Authentication**: Master password required

```bash
curl -X POST http://localhost:8080/api/v1/admin/user \
  -H "Content-Type: application/json" \
  -d '{
    "master_password": "your_master_password",
    "name": "Mobile App",
    "role": "admin"
  }'
```

#### GET `/api/v1/admin/users`
List all API tokens.

**Authentication**: Master password required

```bash
curl "http://localhost:8080/api/v1/admin/users?master_password=your_master_password"
```

### CORS Proxy

#### POST `/api/v1/proxy`
Proxy requests to external APIs (WirelessTag, etc.).

**Authentication**: Required (Bearer token)

**Note**: Token is now passed via Authorization header instead of request body.

```bash
curl -X POST http://localhost:8080/api/v1/proxy \
  -H "Authorization: Bearer htc_1a2b3c4d5e6f..." \
  -H "Content-Type: application/json" \
  -d '{
    "endpoint": "https://api.wirelesstag.net/some-endpoint",
    "method": "GET",
    "headers": {
      "Authorization": "Bearer external_api_token"
    }
  }'
```

## Real-Time Updates

### Polling Strategy
For real-time updates, poll the `/api/heating-status` endpoint (requires authentication):

```javascript
// Poll every 15 seconds during active heating
// Poll every 60 seconds when idle
const pollInterval = activeHeating ? 15000 : 60000;

setInterval(async () => {
  const response = await fetch('/api/heating-status', {
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });
  const status = await response.json();
  updateUI(status);
}, pollInterval);
```

### Temperature Monitoring
During active heating cycles, the system monitors temperature at these intervals:
- **Coarse mode**: Every 10-15 minutes when > 2°F from target
- **Precision mode**: Every 15 seconds when ≤ 2°F from target

## Error Handling

### Standard Error Format
All errors follow this format:

```json
{
  "error": "Invalid request parameters",
  "message": "Target temperature out of safe range (50-110°F): 120",
  "timestamp": "2025-09-09T15:30:00+00:00"
}
```

### Common HTTP Status Codes
- `200` - Success
- `400` - Bad Request (validation errors)
- `401` - Unauthorized (invalid/missing token)
- `404` - Not Found
- `500` - Internal Server Error

### Authentication Errors
```json
{
  "error": "Unauthorized",
  "message": "Invalid or expired token",
  "timestamp": "2025-09-09T15:30:00+00:00"
}
```

### Validation Errors
```json
{
  "error": "Validation failed",
  "message": "Missing required fields: start_time",
  "timestamp": "2025-09-09T15:30:00+00:00"
}
```

## Safety Considerations

### Hardware Control Safety
⚠️ **CRITICAL**: This API controls real hot tub hardware including heater, pump, and ionizer.

**Safety Features:**
- Temperature limits enforced (50-110°F)
- Maximum heating duration (4 hours)
- Overlap prevention for scheduled events
- Emergency stop capabilities
- Equipment safety sequences (pump runs before/after heater)

**Equipment Control Events:**
- `hot-tub-heat-on`: Activates pump → circulation delay → heater
- `hot-tub-heat-off`: Stops heater → cooling cycle → pump stop
- `turn-on-hot-tub-ionizer`: Activates ionizer system
- `turn-off-hot-tub-ionizer`: Deactivates ionizer

### Rate Limiting
- API calls are logged and monitored
- Emergency stop endpoint has higher priority
- Scheduling operations check for conflicts

## Integration Best Practices

### 1. Initial Setup
```javascript
// 1. Authenticate and get token
const authResponse = await fetch('/api/v1/admin/user', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    master_password: 'your_password',
    name: 'My Frontend App'
  })
});
const { token } = await authResponse.json();

// 2. Store token securely
localStorage.setItem('htc_token', token);
```

### 2. Status Monitoring
```javascript
class HotTubController {
  constructor(token) {
    this.token = token;
    this.headers = {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    };
  }

  async getStatus() {
    const response = await fetch('/api/heating-status', {
      headers: this.headers
    });
    return await response.json();
  }

  async scheduleHeating(startTime, targetTemp, name) {
    const response = await fetch('/api/schedule-heating', {
      method: 'POST',
      headers: this.headers,
      body: JSON.stringify({
        start_time: startTime,
        target_temp: targetTemp,
        name: name
      })
    });
    return await response.json();
  }

  async emergencyStop(cycleId = null) {
    const response = await fetch('/api/stop-heating', {
      method: 'POST',
      headers: this.headers,
      body: JSON.stringify({
        cycle_id: cycleId,
        reason: 'user_request'
      })
    });
    return await response.json();
  }
}
```

### 3. Error Handling
```javascript
async function apiCall(url, options = {}) {
  try {
    const response = await fetch(url, options);
    
    if (!response.ok) {
      const error = await response.json();
      throw new Error(`API Error: ${error.message}`);
    }
    
    return await response.json();
  } catch (error) {
    console.error('API call failed:', error);
    // Handle network errors, API errors, etc.
    throw error;
  }
}
```

### 4. Responsive Updates
```javascript
// Update UI based on system status
function updateUI(status) {
  // Update temperature display
  document.getElementById('temp').textContent = 
    `${status.temperature?.value}°F`;
    
  // Update heating status
  const isHeating = status.active_cycle !== null;
  document.getElementById('heating-indicator').classList.toggle('active', isHeating);
  
  // Update next scheduled event
  if (status.next_scheduled_event) {
    const nextEvent = status.next_scheduled_event;
    document.getElementById('next-heating').textContent = 
      `${nextEvent.name} at ${new Date(nextEvent.scheduled_for).toLocaleTimeString()}`;
  }
  
  // Progress bar for active heating
  if (isHeating && status.active_cycle.progress) {
    document.getElementById('progress').style.width = 
      `${status.active_cycle.progress * 100}%`;
  }
}
```

## System Architecture Notes

### Cron-Based Scheduling
The system uses dynamic cron job scheduling:
- Heating events create self-deleting cron jobs
- Monitoring loops adjust frequency based on temperature proximity
- All cron operations use secure API key authentication

### External Dependencies
- **WirelessTag API**: Temperature sensor readings
- **IFTTT Webhooks**: Equipment control via SmartLife automation
- **System Cron**: Dynamic heating schedule management

### Data Persistence
- JSON file-based storage with automatic rotation
- Thread-safe operations with file locking
- Automatic cleanup of old data files

For complete technical details, see the [main README](../README.md) and [OpenAPI specification](./api-reference.yaml).