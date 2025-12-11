# Job Scheduler MVP Plan

## Goal

Enable users to schedule one-off equipment commands like "Start the hot tub heater at 6:30am tomorrow" from the frontend. The system runs on cPanel with cron job capability.

## Design Principles

1. **Minimal complexity** - One-off jobs only, no recurring schedules
2. **Security first** - No secrets in crontab or logs
3. **Self-cleaning** - Crons delete themselves FIRST (before any other work)
4. **Single access mode** - All execution via HTTPS (cron → curl → API)
5. **Reuse existing auth** - No new auth middleware; use existing JWT system

---

## Architecture Decision: Deploy-Time JWT

Generate a long-lived `CRON_JWT` during deployment, store in `.env`, cron reads it at execution time.

**Why this approach:**
- Reuses existing `AuthService` and `AuthMiddleware` - zero new auth code
- Token regenerated on each deploy - natural refresh cycle
- Credentials already in `.env` - no additional secret storage
- Cron reads `.env` at runtime - always gets current token

**Token lifecycle:**
```
Deploy #1 → Generate JWT (30yr expiry) → stored in .env
     ↓
Cron jobs use this JWT for months...
     ↓
Deploy #2 → Generate new JWT → replaces old in .env
     ↓
Existing scheduled jobs work (they read .env at runtime)
```

---

## System Components

### 1. JWT Generation Script

```
backend/bin/generate-cron-jwt.php
```

Run during deployment. Uses existing `AuthService::createToken()` to generate a JWT with:
- Subject: `cron-system`
- Role: `admin`
- Expiry: 30 years (but regenerated on next deploy anyway)

Writes `CRON_JWT=xxx` to `.env`.

### 2. Scheduler Service

```
backend/src/Services/SchedulerService.php
```

- `scheduleJob(action, scheduledTime)` - Create a one-off job
- `listJobs()` - Return pending jobs
- `cancelJob(jobId)` - Remove a scheduled job

### 3. Schedule Controller

```
backend/src/Controllers/ScheduleController.php
```

**Endpoints (all use existing JWT auth):**
| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/api/schedule` | JWT | Create scheduled job |
| GET | `/api/schedule` | JWT | List scheduled jobs |
| DELETE | `/api/schedule/{id}` | JWT | Cancel scheduled job |

**No dedicated cron endpoint needed** - cron calls existing equipment endpoints directly.

### 4. Cron Execution Infrastructure

```
backend/storage/
├── scheduled-jobs/          # Job metadata files
│   └── job-{id}.json        # Individual job config
├── logs/
│   └── cron.log             # Execution log
└── bin/
    └── cron-runner.sh       # Wrapper script for cron execution
```

**cron-runner.sh execution order (CRITICAL):**
```bash
#!/bin/bash
JOB_ID="$1"

# 1. REMOVE SELF FROM CRONTAB FIRST - prevents orphaned crons if script crashes
crontab -l | grep -v "HOTTUB:$JOB_ID" | crontab -

# 2. Read CRON_JWT from .env
# 3. Read job file to get endpoint
# 4. Call existing equipment endpoint with Bearer token
# 5. Delete job file
# 6. Log result
```

### 5. Crontab Entry Format

```
30 6 11 12 * /path/to/storage/bin/cron-runner.sh job-abc123 # HOTTUB:job-abc123
```

- Time fields set to scheduled execution time (minute hour day month *)
- Job ID passed as argument and in trailing comment (for grep-based removal)
- Comment tag `HOTTUB:` identifies our application's crons

### 6. Frontend Components

```
frontend/src/lib/
├── api.ts                   # Add schedule endpoints
└── components/
    └── SchedulePanel.svelte # UI for scheduling
```

---

## Security Model

### Secrets Storage
| Secret | Location | Access |
|--------|----------|--------|
| CRON_JWT | `.env` | cron-runner.sh reads at runtime |
| JWT_SECRET | `.env` | AuthService validates tokens |
| Admin credentials | `.env` | AuthController login |

### What Goes Where
| Location | Contains | Never Contains |
|----------|----------|----------------|
| `.env` | CRON_JWT, JWT_SECRET, credentials | - |
| Crontab | Times, paths, job IDs | Tokens, passwords |
| Job JSON files | Action, endpoint, scheduled time | Tokens |
| cron-runner.sh | Logic to read .env | Hardcoded secrets |
| Logs | Job IDs, timestamps, HTTP status | Tokens, secrets |

### Storage Directory Security
```bash
# storage/ should not be web-accessible
# Verify by trying to access https://example.com/backend/storage/
chmod 755 backend/storage
chmod 755 backend/storage/bin
chmod 755 backend/storage/scheduled-jobs
chmod 755 backend/storage/logs
```

---

## Data Flow

### Creating a Scheduled Job
```
Frontend                    Backend                         System
   |                           |                              |
   |-- POST /api/schedule ---->|                              |
   |   {action: "heater-on",   |                              |
   |    time: "2024-12-11T06:30"}                             |
   |                           |                              |
   |                           |-- Create job-xyz.json ------>|
   |                           |-- Add crontab entry -------->|
   |                           |                              |
   |<-- {jobId: "xyz", ...} ---|                              |
```

### Executing a Scheduled Job
```
Cron                        cron-runner.sh              Backend API
  |                              |                          |
  |-- Execute at 6:30 ---------> |                          |
  |                              |                          |
  |                              |-- REMOVE SELF FROM CRONTAB (first!)
  |                              |-- Read CRON_JWT from .env |
  |                              |-- Read job file for endpoint
  |                              |-- curl POST /api/equipment/heater/on
  |                              |   Authorization: Bearer xxx
  |                              |                          |
  |                              |                          |-- Validate JWT (existing auth)
  |                              |                          |-- Call IFTTT webhook
  |                              |                          |-- Log result
  |                              |<-- {success: true} ------|
  |                              |                          |
  |                              |-- Delete job-xyz.json    |
  |                              |-- Log completion         |
```

**Key point:** Cron removal happens FIRST. If script crashes after that, we have an orphaned job file (harmless). If it crashed before removal, we'd have a cron that keeps firing (dangerous).

---

## API Specifications

### New Endpoints (Schedule Management)

#### POST /api/schedule (Create Job)
**Auth:** JWT required (user must be logged in)

**Request:**
```json
{
  "action": "heater-on",
  "scheduledTime": "2024-12-11T06:30:00-05:00"
}
```

**Valid actions:** `heater-on`, `heater-off`, `pump-run`

**Response (201):**
```json
{
  "jobId": "job-abc123",
  "action": "heater-on",
  "scheduledTime": "2024-12-11T06:30:00-05:00",
  "createdAt": "2024-12-10T20:00:00-05:00"
}
```

#### GET /api/schedule (List Jobs)
**Auth:** JWT required

**Response (200):**
```json
{
  "jobs": [
    {
      "jobId": "job-abc123",
      "action": "heater-on",
      "scheduledTime": "2024-12-11T06:30:00-05:00",
      "createdAt": "2024-12-10T20:00:00-05:00"
    }
  ]
}
```

#### DELETE /api/schedule/{jobId} (Cancel Job)
**Auth:** JWT required

**Response (200):**
```json
{
  "success": true,
  "message": "Job cancelled"
}
```

### Existing Endpoints (Used by Cron)

Cron calls existing equipment endpoints directly - no new execution endpoint needed:

| Action | Endpoint Called by Cron |
|--------|------------------------|
| `heater-on` | `POST /api/equipment/heater/on` |
| `heater-off` | `POST /api/equipment/heater/off` |
| `pump-run` | `POST /api/equipment/pump/run` |

The cron passes `CRON_JWT` as `Authorization: Bearer` header, using existing auth middleware.

---

## File Formats

### Job Metadata (storage/scheduled-jobs/job-{id}.json)
```json
{
  "jobId": "job-abc123",
  "action": "heater-on",
  "endpoint": "/api/equipment/heater/on",
  "scheduledTime": "2024-12-11T06:30:00-05:00",
  "createdAt": "2024-12-10T20:00:00-05:00"
}
```

### Environment File (.env additions)
```bash
# Added by bin/generate-cron-jwt.php during deployment
CRON_JWT=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

---

## Setup Requirements

### Deployment Steps
1. Copy appropriate config file to `.env`
2. Run `php bin/generate-cron-jwt.php` to create CRON_JWT
3. Ensure storage directories exist with correct permissions
4. Verify crontab access works (test with `crontab -l`)

### Directory Setup
```bash
mkdir -p backend/storage/scheduled-jobs
mkdir -p backend/storage/logs
mkdir -p backend/storage/bin
chmod 755 backend/storage backend/storage/*
```

---

## Error Handling

### Scheduling Errors (API responses)
- Invalid action type → 400 Bad Request
- Time in the past → 400 Bad Request
- Crontab write failure → 500 Internal Server Error

### Execution Errors (logged by cron-runner.sh)
- CRON_JWT not found in .env → Log error, exit
- Job file not found → Log error, exit (cron already removed)
- JWT expired/invalid → API returns 401, logged
- IFTTT call failure → API returns 500, logged

### Cleanup Strategy
- Cron removes itself FIRST, so orphaned crons are impossible
- Orphaned job files (cron removed but file remains) are harmless
- Optional: Periodic cleanup script to remove stale job files

---

## Limitations (MVP Scope)

1. **One-off only** - No recurring schedules
2. **No timezone UI** - Server timezone used (configurable later)
3. **No job editing** - Cancel and recreate instead
4. **Single user** - All jobs treated equally (no per-user isolation)
5. **No retry** - Failed executions are logged, not retried

---

## Future Enhancements (Out of Scope)

- Recurring schedules (daily, weekly)
- Temperature-based scheduling ("heat to 102°F by 6pm")
- Job history and execution logs in UI
- Multiple timezone support
- Notification on job completion/failure
- Log file management: when a cron finishes, it should kick off a "clean / zip /rotate logs job" that is a convenient way to make sure that logs stay small and rotate out after an expiration period.