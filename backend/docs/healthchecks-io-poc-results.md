# Healthchecks.io Proof of Concept Results

**Date:** 2025-12-14
**Status:** VERIFIED - Ready for Implementation

## Key Findings

### 1. State Machine Confirmed

```
new ──(first ping)──> up ──(timeout)──> grace ──(grace period)──> down
                       ↑                                           │
                       └───────────────(ping)──────────────────────┘
```

### 2. Never-Pinged Checks Do NOT Alert

**Verified empirically:** A check created with 60s timeout + 60s grace, waited 150 seconds (2.5 minutes), remained in `new` state.

This confirms the source code analysis - `alert_after` requires `last_ping` to exist.

### 3. Timing Accuracy

| Transition | Expected | Observed |
|------------|----------|----------|
| up → grace | 60s | 63s |
| up → down | 120s | 126s |

The timing is accurate within a few seconds of polling interval.

### 4. API Response Times

- Check creation: ~1-2s
- Ping: <1s
- Status poll: <1s

## Required Architecture for Hot Tub Controller

### For One-Off Jobs (user schedules "heat at 3pm")

```
┌─────────────────────────────────────────────────────────────────┐
│  WHEN USER SCHEDULES JOB (in PHP SchedulerService)              │
├─────────────────────────────────────────────────────────────────┤
│  1. Create cron entry                                            │
│  2. Create Healthchecks.io check:                                │
│     - timeout = (scheduled_time - now) + grace_buffer           │
│     - grace = 30 minutes                                         │
│  3. PING immediately (transitions new → up, ARMS the check)     │
│  4. Store check UUID in job file                                │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  WHEN CRON EXECUTES (in cron-runner.sh)                         │
├─────────────────────────────────────────────────────────────────┤
│  SUCCESS PATH:                                                   │
│    1. Execute API call                                           │
│    2. If HTTP 200: DELETE the Healthchecks.io check             │
│    3. Clean up job file                                          │
│                                                                  │
│  FAILURE PATH:                                                   │
│    1. Execute API call                                           │
│    2. If HTTP error: do NOT delete check                        │
│    3. Check will timeout → grace → down → EMAIL ALERT           │
└─────────────────────────────────────────────────────────────────┘
```

### API Calls Per Job

| Scenario | Calls |
|----------|-------|
| Success | 3 (create, ping, delete) |
| Cron never fires | 2 (create, ping, then timeout → alert) |
| Cron fires but API fails | 2 (create, ping, then timeout → alert) |

### For Recurring Jobs (optional future feature)

```php
// Create once with cron schedule
$check = createCheck([
    'name' => "recurring-heat-6am",
    'schedule' => '0 6 * * *',
    'tz' => 'America/New_York',
    'grace' => 1800  // 30 min
]);

// Ping immediately to arm
ping($check['ping_url']);

// Job pings on each successful execution
// Check alerts if any scheduled run is missed
```

## Free Tier Limits

- 20 checks (concurrent)
- Unlimited pings
- Email, SMS (5 credits), webhooks
- 100 log entries per check

For the hot tub controller with ~5 max concurrent scheduled jobs, this is ample.

## Test Results

```
✔ New check starts in new state
✔ Check transitions to up after first ping
✔ Check goes to grace and down after timeout (waited 2+ min)
✔ Never pinged check does not alert (waited 2.5 min)
```

## Next Steps

1. Create `HealthchecksClient` service class
2. Integrate into `SchedulerService::scheduleJob()`
3. Integrate into `cron-runner.sh` for deletion on success
4. Add integration tests
