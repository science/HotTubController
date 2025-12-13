# Logging and Crontab Backup Plan

## Current State Analysis

### Existing Logging
- `backend/logs/events.log` - IFTTT actions only (828 bytes, last entry Dec 10)
- `backend/storage/logs/` - Empty (intended for cron logs but never populated)
- `~/logs/` - cPanel manages Apache logs (compressed monthly), acme.sh.log exists
- **No API request logging** (IP, endpoint, method, timestamp, response time)

### Crontab Management
- CrontabAdapter modifies crontab with no backup
- Risk: any bug could wipe or corrupt crontab
- Need: backup before every modification

---

## Task Breakdown

### Phase 1: Crontab Backup System [COMPLETE]
**Goal**: Backup crontab to archive before any modification

- [x] 1.1 Create `CrontabBackupService` class
  - Saves timestamped crontab snapshots to `storage/crontab-backups/`
  - Format: `crontab-YYYY-MM-DD-HHMMSS.txt`

- [x] 1.2 Integrate backup into `CrontabAdapter`
  - Call backup before `addEntry()` and `removeByPattern()`
  - Only backup if crontab is non-empty

- [x] 1.3 Write tests for backup service (TDD) - 15 tests

### Phase 2: Log Rotation System [COMPLETE]
**Goal**: Manage log file lifecycle (zip old, delete ancient)

- [x] 2.1 Create `LogRotationService` class
  - Zip files older than 7 days (*.log → *.log.gz)
  - Delete files older than 90 days
  - Configurable thresholds
  - 16 tests passing

- [x] 2.2 Create `rotate-logs.php` CLI script
  - PHP script at `storage/bin/rotate-logs.php`
  - Supports --dry-run and --verbose flags
  - Rotates both crontab backups and app logs

### Phase 3: API Request Logging [COMPLETE]
**Goal**: Log all API requests for debugging/auditing

- [x] 3.1 Create `RequestLogger` service
  - Log: timestamp, IP, method, URI, response code, response time, username, error
  - Output: JSON lines format to `storage/logs/api.log`
  - 11 tests passing

- [x] 3.2 Integrate into `public/index.php`
  - Log at request end with timing
  - Don't log sensitive data (passwords, tokens, request bodies)

### Phase 4: Cron Job for Rotation [COMPLETE]
**Goal**: Automated log and backup rotation

- [x] 4.1 Create `storage/bin/rotate-logs.php` CLI script
  - Handles both log rotation and crontab backup cleanup
  - Self-contained (no web dependencies)

- [x] 4.2 Document cron setup (see below)

- [x] 4.3 Update deploy.yml for executable permissions

---

## Directory Structure (After Implementation)

```
backend/
├── storage/
│   ├── logs/
│   │   ├── api.log              # API request log (current)
│   │   ├── api.log.gz           # Rotated/compressed
│   │   └── cron.log             # Cron runner log
│   ├── crontab-backups/
│   │   ├── crontab-2025-12-12-081500.txt
│   │   ├── crontab-2025-12-11-143022.txt.gz
│   │   └── ...
│   ├── bin/
│   │   ├── cron-runner.sh       # Existing scheduled job runner
│   │   └── rotate-logs.php      # NEW: Log rotation CLI
│   └── scheduled-jobs/          # Existing
├── logs/
│   └── events.log               # Existing IFTTT log
└── src/Services/
    ├── CrontabBackupService.php # NEW: Crontab backups
    ├── LogRotationService.php   # NEW: Log rotation
    └── RequestLogger.php        # NEW: API logging
```

---

## Rotation Rules

### Crontab Backups
- Keep uncompressed: 7 days
- Compress: files older than 7 days
- Delete: files older than 30 days

### Application Logs
- Keep uncompressed: 7 days
- Compress: files older than 7 days
- Delete: files older than 90 days

---

## Production Cron Setup

After deploying to production, add this cron job via cPanel:

```
# Log rotation - daily at 3:00 AM
0 3 * * * /usr/bin/php /home/USERNAME/public_html/tub/backend/storage/bin/rotate-logs.php >> /home/USERNAME/logs/rotate-logs.log 2>&1
```

Replace `USERNAME` with your cPanel username.

### Testing the rotation script

```bash
# Dry run - see what would happen
php storage/bin/rotate-logs.php --dry-run --verbose

# Actually run rotation
php storage/bin/rotate-logs.php --verbose
```

---

## Implementation Summary

| Phase | Component | Tests | Status |
|-------|-----------|-------|--------|
| 1 | CrontabBackupService | 15 | Complete |
| 2 | LogRotationService | 16 | Complete |
| 3 | RequestLogger | 11 | Complete |
| 4 | Automation | - | Complete |

**Total new tests added: 42**

---

## Progress Tracking

Started: 2025-12-12
Completed: 2025-12-12

All phases complete. Ready for deployment.
