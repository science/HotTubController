# Scheduler Implementation TODO

Simple one-off job scheduler using cron + JWT authentication.

## Architecture Summary

- **Auth**: Reuse existing JWT system. Generate `CRON_JWT` at deploy time, store in `.env`
- **Execution**: Cron calls existing equipment endpoints with Bearer token
- **Self-cleaning**: Cron removes itself from crontab FIRST, before any other work
- **No new auth code**: Existing `AuthMiddleware` validates cron requests

---

## Implementation Phases

### Phase 1: JWT Generation Script

Create `bin/generate-cron-jwt.php` - runs during deployment.

**Behavior:**
1. Load `.env` to get `JWT_SECRET`
2. Use existing `AuthService::createToken()` to generate JWT
   - Subject: `cron-system` (or admin username)
   - Role: `admin`
   - Expiry: 30 years (will be regenerated on each deploy)
3. Write/update `CRON_JWT=xxx` line in `.env`
4. Print confirmation message

**Tests (TDD):**
- [ ] TEST: Script creates valid JWT that passes AuthMiddleware validation
- [ ] TEST: Script updates existing CRON_JWT if present
- [ ] TEST: Script appends CRON_JWT if not present
- [ ] TEST: Generated JWT has admin role claim

**Implementation:**
- [ ] IMPL: Create `backend/bin/generate-cron-jwt.php`
- [ ] IMPL: Add `CRON_JWT` to `.env.example` with placeholder comment

---

### Phase 2: Cron Runner Script

Create `storage/bin/cron-runner.sh` - executed by cron at scheduled time.

**Execution order (CRITICAL - remove cron FIRST):**
1. **REMOVE SELF FROM CRONTAB** - Before anything else
2. Read `CRON_JWT` from `.env`
3. Read job file to get endpoint
4. Call API endpoint with Bearer token
5. Delete job file
6. Log result

**Why remove cron first:** If script crashes after step 1, we have an orphaned job file (harmless, small JSON). If script crashes before removing cron, we have a cron that keeps firing (dangerous, could spam IFTTT).

**Script outline:**
```bash
#!/bin/bash
JOB_ID="$1"

# STEP 1: Remove self from crontab IMMEDIATELY
crontab -l 2>/dev/null | grep -v "HOTTUB:$JOB_ID" | crontab -

# STEP 2-6: Everything else...
```

**Tests:**
- [ ] TEST: Script removes crontab entry matching job ID
- [ ] TEST: Script reads CRON_JWT from .env correctly
- [ ] TEST: Script calls correct endpoint from job file
- [ ] TEST: Script deletes job file after execution
- [ ] TEST: Script logs success/failure with timestamp

**Implementation:**
- [ ] IMPL: Create `backend/storage/bin/cron-runner.sh`
- [ ] IMPL: Make executable (`chmod +x`)
- [ ] IMPL: Create `backend/storage/logs/` directory
- [ ] IMPL: Add `.gitkeep` files for empty directories

---

### Phase 3: Scheduler Service

Create `src/Services/SchedulerService.php` - manages job lifecycle.

#### 3.1 scheduleJob()

**Behavior:**
1. Validate action is one of: `heater-on`, `heater-off`, `pump-run`
2. Validate scheduledTime is in the future
3. Generate unique job ID (e.g., `job-` + 8 random hex chars)
4. Map action to endpoint:
   - `heater-on` → `/api/equipment/heater/on`
   - `heater-off` → `/api/equipment/heater/off`
   - `pump-run` → `/api/equipment/pump/run`
5. Write job file to `storage/scheduled-jobs/{jobId}.json`
6. Parse scheduledTime into cron fields (minute, hour, day, month)
7. Add crontab entry via exec()
8. Return job details

**Job file format:**
```json
{
  "jobId": "job-a1b2c3d4",
  "action": "heater-on",
  "endpoint": "/api/equipment/heater/on",
  "scheduledTime": "2024-12-11T06:30:00-05:00",
  "createdAt": "2024-12-10T20:00:00-05:00"
}
```

**Crontab entry format:**
```
30 6 11 12 * /path/to/storage/bin/cron-runner.sh job-a1b2c3d4 # HOTTUB:job-a1b2c3d4
```

**Tests (TDD):**
- [ ] TEST: scheduleJob creates job file with correct structure
- [ ] TEST: scheduleJob adds crontab entry with correct time fields
- [ ] TEST: scheduleJob returns job details
- [ ] TEST: scheduleJob rejects invalid action with exception
- [ ] TEST: scheduleJob rejects past scheduledTime with exception
- [ ] TEST: scheduleJob generates unique job IDs

**Implementation:**
- [ ] IMPL: Create `backend/src/Services/SchedulerService.php`
- [ ] IMPL: Create `backend/storage/scheduled-jobs/` directory

#### 3.2 listJobs()

**Behavior:**
1. Scan `storage/scheduled-jobs/` for `job-*.json` files
2. Parse each file and return array of job details
3. Sort by scheduledTime ascending

**Tests:**
- [ ] TEST: listJobs returns empty array when no jobs
- [ ] TEST: listJobs returns all pending jobs
- [ ] TEST: listJobs sorts by scheduledTime

**Implementation:**
- [ ] IMPL: Add `listJobs()` method to SchedulerService

#### 3.3 cancelJob()

**Behavior:**
1. Verify job file exists (throw if not)
2. Remove crontab entry matching job ID
3. Delete job file
4. Return success

**Tests:**
- [ ] TEST: cancelJob removes crontab entry
- [ ] TEST: cancelJob deletes job file
- [ ] TEST: cancelJob throws NotFoundException for unknown job

**Implementation:**
- [ ] IMPL: Add `cancelJob()` method to SchedulerService

---

### Phase 4: Schedule Controller & Routes

Create `src/Controllers/ScheduleController.php` and add routes.

#### 4.1 Endpoints

| Method | Path | Auth | Handler |
|--------|------|------|---------|
| POST | `/api/schedule` | JWT | `ScheduleController::create()` |
| GET | `/api/schedule` | JWT | `ScheduleController::list()` |
| DELETE | `/api/schedule/{id}` | JWT | `ScheduleController::cancel()` |

#### 4.2 Request/Response Formats

**POST /api/schedule**
```
Request:  {"action": "heater-on", "scheduledTime": "2024-12-11T06:30:00"}
Response: {"jobId": "job-a1b2c3d4", "action": "heater-on", "scheduledTime": "...", "createdAt": "..."}
```

**GET /api/schedule**
```
Response: {"jobs": [{...}, {...}]}
```

**DELETE /api/schedule/{id}**
```
Response: {"success": true}
```

**Tests (TDD):**
- [ ] TEST: POST /api/schedule returns 401 without auth
- [ ] TEST: POST /api/schedule creates job and returns 201
- [ ] TEST: POST /api/schedule returns 400 for invalid action
- [ ] TEST: POST /api/schedule returns 400 for past time
- [ ] TEST: GET /api/schedule returns job list
- [ ] TEST: DELETE /api/schedule/{id} cancels job
- [ ] TEST: DELETE /api/schedule/{id} returns 404 for unknown job

**Implementation:**
- [ ] IMPL: Create `backend/src/Controllers/ScheduleController.php`
- [ ] IMPL: Add routes to `backend/public/index.php`

---

### Phase 5: Frontend

#### 5.1 API Client Methods

Add to `frontend/src/lib/api.ts`:

```typescript
export async function scheduleJob(action: string, scheduledTime: string) { ... }
export async function listScheduledJobs() { ... }
export async function cancelScheduledJob(jobId: string) { ... }
```

**Implementation:**
- [ ] IMPL: Add schedule methods to `api.ts`
- [ ] IMPL: Add TypeScript types for schedule responses

#### 5.2 Schedule UI Component

Create `frontend/src/lib/components/SchedulePanel.svelte`:

- Action dropdown (Heater On, Heater Off, Pump Run)
- Date/time picker for scheduled time
- "Schedule" button
- List of pending jobs with cancel buttons

**Implementation:**
- [ ] IMPL: Create `SchedulePanel.svelte` component
- [ ] IMPL: Integrate into main dashboard/page

---

### Phase 6: Production Setup

#### 6.1 Directory Structure

Ensure these exist with correct permissions:
```
backend/
├── storage/
│   ├── bin/
│   │   └── cron-runner.sh    (0755)
│   ├── scheduled-jobs/        (0755 dir, 0644 files)
│   └── logs/
│       └── cron.log          (0644)
```

**Implementation:**
- [ ] IMPL: Create directory structure
- [ ] IMPL: Add `.gitkeep` files
- [ ] IMPL: Document permission requirements in README or deploy script

#### 6.2 GitHub Actions Integration

The JWT must be generated during the GitHub Actions deploy workflow.

**Current workflow** (`.github/workflows/deploy.yml`):
1. Checkout code
2. Install PHP/composer dependencies
3. Build frontend
4. Create backend `.env` from secrets ← JWT_SECRET available here
5. Deploy backend via FTP
6. Deploy frontend via FTP

**Required change:** Add step between #4 and #5:
```yaml
# Generate CRON_JWT and append to .env
- name: Generate CRON JWT
  run: |
    cd backend
    php bin/generate-cron-jwt.php
```

This runs AFTER `.env` is created (so JWT_SECRET exists) and BEFORE FTP deploy (so CRON_JWT is included in uploaded `.env`).

**Implementation:**
- [ ] IMPL: Add "Generate CRON JWT" step to `.github/workflows/deploy.yml`
- [ ] IMPL: Ensure `bin/generate-cron-jwt.php` works without autoload (or runs after composer install)
- [ ] IMPL: Verify CRON_JWT appears in deployed `.env` after first deploy

#### 6.3 Environment Config

Add to `.env.example` and `config/env.*` files:

```
# Scheduler (CRON_JWT is auto-generated by bin/generate-cron-jwt.php)
# CRON_JWT=<generated-at-deploy-time>
```

**Implementation:**
- [ ] IMPL: Update `.env.example`
- [ ] IMPL: Update `config/env.development`
- [ ] IMPL: Update `config/env.testing`

---

## Implementation Order

Recommended sequence for incremental, testable progress:

1. `bin/generate-cron-jwt.php` - Can test locally immediately
2. **Update `.github/workflows/deploy.yml`** - Add JWT generation step (critical for production)
3. `storage/bin/cron-runner.sh` - Can test with mock job file
4. `SchedulerService::scheduleJob()` - Core scheduling logic
5. `SchedulerService::listJobs()` - Simple file scan
6. `SchedulerService::cancelJob()` - Cleanup logic
7. `ScheduleController` + routes - Wire up API
8. Frontend `api.ts` methods - Client calls
9. `SchedulePanel.svelte` - User interface
10. Integration test on staging - Real cron execution

---

## Testing Notes

### Crontab Testing Strategy

For unit tests, mock the `exec()` calls that manipulate crontab:
```php
// Inject a CrontabAdapter interface
interface CrontabAdapterInterface {
    public function addEntry(string $entry): void;
    public function removeEntry(string $pattern): void;
    public function listEntries(): array;
}
```

For integration tests on staging:
1. Schedule a job 2 minutes in the future
2. Verify crontab entry exists
3. Wait for execution
4. Verify equipment endpoint was called (check logs)
5. Verify crontab entry was removed
6. Verify job file was deleted

### Shell Script Testing

Test `cron-runner.sh` manually:
```bash
# Create a test job file
echo '{"jobId":"test-123","endpoint":"/api/health"}' > storage/scheduled-jobs/test-123.json

# Add a fake crontab entry
(crontab -l 2>/dev/null; echo "* * * * * echo test # HOTTUB:test-123") | crontab -

# Run the script (will call /api/health which is safe)
./storage/bin/cron-runner.sh test-123

# Verify cleanup
crontab -l | grep test-123  # Should be gone
ls storage/scheduled-jobs/test-123.json  # Should be gone
cat storage/logs/cron.log  # Should show execution
```

---

## Security Checklist

Before production deployment:

- [ ] `storage/` directory is not web-accessible (verify with browser)
- [ ] `.env` file is not web-accessible (verify with browser)
- [ ] `cron-runner.sh` has execute permission (0755)
- [ ] Job files have restricted permissions (0644)
- [ ] `CRON_JWT` is not logged anywhere
- [ ] Crontab entries contain no secrets (only paths and job IDs)

---

## Out of Scope (Future)

- Recurring schedules (daily, weekly)
- Job editing (cancel and recreate instead)
- Execution history in database
- Email/push notifications on completion
- Timezone selection in UI
- Multiple concurrent jobs for same time
