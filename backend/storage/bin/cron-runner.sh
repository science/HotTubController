#!/bin/bash
#
# Cron Runner Script for Hot Tub Scheduler
#
# Executes scheduled jobs by calling the backend API.
# For one-off jobs: Removes itself from crontab FIRST to prevent orphaned crons.
# For recurring jobs: Keeps crontab entry and job file for next execution.
#
# Usage: cron-runner.sh <job-id>
#
# The job file at storage/scheduled-jobs/<job-id>.json contains:
# {
#   "jobId": "job-abc123" or "rec-abc123",
#   "endpoint": "/api/equipment/heater/on",
#   "apiBaseUrl": "https://example.com/tub/backend/public",
#   "recurring": false or true,
#   "healthcheckUuid": "uuid-here" (optional, for monitoring)
# }

set -euo pipefail

JOB_ID="${1:-}"

# Validate job ID argument
if [ -z "$JOB_ID" ]; then
    echo "Error: Job ID required"
    exit 1
fi

# Determine paths relative to this script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STORAGE_DIR="$(dirname "$SCRIPT_DIR")"
BACKEND_DIR="$(dirname "$STORAGE_DIR")"
ENV_FILE="$BACKEND_DIR/.env"
JOBS_DIR="$STORAGE_DIR/scheduled-jobs"
JOB_FILE="$JOBS_DIR/$JOB_ID.json"
LOG_FILE="$STORAGE_DIR/logs/cron.log"

# Logging function
log() {
    local timestamp
    timestamp=$(date -Iseconds 2>/dev/null || date '+%Y-%m-%dT%H:%M:%S')
    echo "[$timestamp] $JOB_ID: $1" >> "$LOG_FILE"
}

# Ensure log directory exists
mkdir -p "$(dirname "$LOG_FILE")"

log "Starting execution"

# ============================================================
# STEP 0: Check if this is a recurring job
# Recurring jobs keep their crontab entry and job file
# ============================================================
IS_RECURRING="false"
if [ -f "$JOB_FILE" ]; then
    # Parse recurring field from JSON (no jq dependency)
    IS_RECURRING=$(grep -o '"recurring"[[:space:]]*:[[:space:]]*\(true\|false\)' "$JOB_FILE" | sed 's/.*:[[:space:]]*//' || echo "false")
fi

if [ "$IS_RECURRING" = "true" ]; then
    log "Recurring job - will keep crontab entry and job file"
else
    log "One-off job - will remove crontab entry and job file after execution"
fi

# ============================================================
# STEP 0.5: CHECK FOR SKIP FILE (recurring jobs only)
# A skip file is a dated token that the API creates when the
# user wants to skip the next occurrence. The file is ALWAYS
# consumed (deleted) to prevent stale files from accumulating.
# ============================================================
if [ "$IS_RECURRING" = "true" ]; then
    SKIP_FILE="$STORAGE_DIR/state/skip-${JOB_ID}.json"
    if [ -f "$SKIP_FILE" ]; then
        # Extract skip_date from the file
        SKIP_DATE=$(grep -o '"skip_date"[[:space:]]*:[[:space:]]*"[^"]*"' "$SKIP_FILE" \
            | sed 's/.*"\([^"]*\)"/\1/' || echo "")
        TODAY=$(date '+%Y-%m-%d')

        # ALWAYS consume the skip file (prevents stale files from accumulating)
        rm -f "$SKIP_FILE"

        if [ "$SKIP_DATE" = "$TODAY" ]; then
            log "SKIPPED: Skip date ($SKIP_DATE) matches today"
            # Still ping healthcheck (cron IS firing, skip was intentional)
            HEALTHCHECK_PING_URL=$(grep -o '"healthcheckPingUrl"[[:space:]]*:[[:space:]]*"[^"]*"' \
                "$JOB_FILE" 2>/dev/null | sed 's/.*:.*"\([^"]*\)"/\1/' || true)
            if [ -n "$HEALTHCHECK_PING_URL" ]; then
                curl -s -o /dev/null "$HEALTHCHECK_PING_URL" --max-time 10 2>/dev/null || true
            fi
            log "Execution complete (SKIPPED)"
            exit 0
        else
            log "SKIP EXPIRED: Skip date ($SKIP_DATE) does not match today ($TODAY), executing normally"
            # Fall through to normal execution
        fi
    fi
fi

# ============================================================
# STEP 1: REMOVE SELF FROM CRONTAB (one-off jobs only)
# This MUST happen first to prevent orphaned crons if script crashes later
#
# IMPORTANT: Uses file-based crontab manipulation instead of pipes.
# This is required for compatibility with CloudLinux CageFS where
# pipe-based crontab commands are unreliable.
# ============================================================
if [ "$IS_RECURRING" != "true" ]; then
    log "Removing from crontab"

    CRON_TEMPFILE=$(mktemp)

    # Read current crontab to temp file (ignore error if no crontab)
    crontab -l > "$CRON_TEMPFILE" 2>/dev/null || true

    # Check if temp file has content and contains our job
    if [ -s "$CRON_TEMPFILE" ] && grep -q "HOTTUB:$JOB_ID" "$CRON_TEMPFILE"; then
        # Filter out our entry
        grep -v "HOTTUB:$JOB_ID" "$CRON_TEMPFILE" > "${CRON_TEMPFILE}.new" || true

        # Install filtered crontab (or remove if empty)
        if [ -s "${CRON_TEMPFILE}.new" ]; then
            crontab "${CRON_TEMPFILE}.new" 2>/dev/null || log "WARNING: Failed to update crontab"
        else
            crontab -r 2>/dev/null || true
        fi

        rm -f "${CRON_TEMPFILE}.new"
    fi

    rm -f "$CRON_TEMPFILE"
fi

# ============================================================
# STEP 2: Read CRON_JWT from .env
# ============================================================
if [ ! -f "$ENV_FILE" ]; then
    log "ERROR: .env file not found at $ENV_FILE"
    exit 1
fi

# Use || true to prevent set -e from exiting if grep finds no match
CRON_JWT=$(grep '^CRON_JWT=' "$ENV_FILE" 2>/dev/null | cut -d'=' -f2- | tr -d '\r\n' || true)
if [ -z "$CRON_JWT" ]; then
    log "ERROR: CRON_JWT not found in .env"
    exit 1
fi

# ============================================================
# STEP 3: Read job file to get endpoint and API base URL
# ============================================================
if [ ! -f "$JOB_FILE" ]; then
    log "ERROR: Job file not found at $JOB_FILE"
    exit 1
fi

# Parse JSON using grep/sed (no jq dependency)
ENDPOINT=$(grep -o '"endpoint"[[:space:]]*:[[:space:]]*"[^"]*"' "$JOB_FILE" | sed 's/.*:.*"\([^"]*\)"/\1/')
API_BASE_URL=$(grep -o '"apiBaseUrl"[[:space:]]*:[[:space:]]*"[^"]*"' "$JOB_FILE" | sed 's/.*:.*"\([^"]*\)"/\1/')

if [ -z "$ENDPOINT" ]; then
    log "ERROR: endpoint not found in job file"
    exit 1
fi

if [ -z "$API_BASE_URL" ]; then
    log "ERROR: apiBaseUrl not found in job file"
    exit 1
fi

FULL_URL="${API_BASE_URL}${ENDPOINT}"

# Extract params object if present (for heat-to-target jobs)
# This extracts the entire params JSON object: {"target_temp_f": 103.5}
REQUEST_BODY=""
if grep -q '"params"' "$JOB_FILE" 2>/dev/null; then
    # Extract the params value - handles nested JSON object
    # Use tr to collapse multi-line JSON to single line first (JSON_PRETTY_PRINT creates multi-line)
    # Then sed extracts everything between "params": and the matching closing brace
    REQUEST_BODY=$(tr -d '\n' < "$JOB_FILE" | sed -n 's/.*"params"[[:space:]]*:[[:space:]]*\({[^}]*}\).*/\1/p' || true)
    if [ -n "$REQUEST_BODY" ]; then
        log "Using request body: $REQUEST_BODY"
    fi
fi

# ============================================================
# STEP 4: Call API endpoint with Bearer token
# ============================================================
log "Calling API: POST $FULL_URL"

# Use params as request body if present, otherwise empty JSON object
if [ -z "$REQUEST_BODY" ]; then
    REQUEST_BODY="{}"
fi

HTTP_RESPONSE=$(mktemp)
HTTP_CODE=$(curl -s -w "%{http_code}" -o "$HTTP_RESPONSE" \
    -X POST "$FULL_URL" \
    -H "Authorization: Bearer $CRON_JWT" \
    -H "Content-Type: application/json" \
    -d "$REQUEST_BODY" \
    --max-time 30 \
    2>&1) || HTTP_CODE="000"

RESPONSE_BODY=$(cat "$HTTP_RESPONSE" 2>/dev/null || echo "")
rm -f "$HTTP_RESPONSE"

if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "201" ]; then
    log "SUCCESS: API returned $HTTP_CODE"
else
    log "FAILED: API returned $HTTP_CODE - $RESPONSE_BODY"
fi

# ============================================================
# STEP 5: Handle health check on SUCCESS (if configured)
# - One-off jobs: DELETE the check (job is done, no more alerts needed)
# - Recurring jobs: PING the check (signals success, resets timer for tomorrow)
# On failure, we intentionally don't touch the check - it will timeout and alert.
# ============================================================
if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "201" ]; then
    # Parse health check data from job file (no jq dependency)
    HEALTHCHECK_UUID=$(grep -o '"healthcheckUuid"[[:space:]]*:[[:space:]]*"[^"]*"' "$JOB_FILE" 2>/dev/null | sed 's/.*:.*"\([^"]*\)"/\1/' || true)
    HEALTHCHECK_PING_URL=$(grep -o '"healthcheckPingUrl"[[:space:]]*:[[:space:]]*"[^"]*"' "$JOB_FILE" 2>/dev/null | sed 's/.*:.*"\([^"]*\)"/\1/' || true)

    # Read Healthchecks.io API key from .env (optional - monitoring may not be configured)
    HEALTHCHECKS_KEY=$(grep '^HEALTHCHECKS_IO_KEY=' "$ENV_FILE" 2>/dev/null | cut -d'=' -f2- | tr -d '\r\n' || true)

    if [ "$IS_RECURRING" = "true" ]; then
        # Recurring job: PING the health check to signal success
        # This resets the timer - check will expect another ping by tomorrow's scheduled time
        if [ -n "$HEALTHCHECK_PING_URL" ]; then
            log "Pinging health check: $HEALTHCHECK_PING_URL"

            HC_PING_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
                "$HEALTHCHECK_PING_URL" \
                --max-time 10 \
                2>&1) || HC_PING_CODE="000"

            if [ "$HC_PING_CODE" = "200" ]; then
                log "Health check pinged successfully"
            else
                log "WARNING: Failed to ping health check (HTTP $HC_PING_CODE)"
            fi
        fi
    else
        # One-off job: DELETE the health check (job is done)
        if [ -n "$HEALTHCHECK_UUID" ] && [ -n "$HEALTHCHECKS_KEY" ]; then
            log "Deleting health check: $HEALTHCHECK_UUID"

            HC_DELETE_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
                -X DELETE "https://healthchecks.io/api/v3/checks/$HEALTHCHECK_UUID" \
                -H "X-Api-Key: $HEALTHCHECKS_KEY" \
                --max-time 10 \
                2>&1) || HC_DELETE_CODE="000"

            if [ "$HC_DELETE_CODE" = "200" ]; then
                log "Health check deleted successfully"
            else
                log "WARNING: Failed to delete health check (HTTP $HC_DELETE_CODE)"
            fi
        fi
    fi
fi

# ============================================================
# STEP 6: Delete job file (one-off jobs only)
# Recurring jobs keep their job file for the next execution
# ============================================================
if [ "$IS_RECURRING" != "true" ]; then
    rm -f "$JOB_FILE"
    log "Cleaned up job file"
fi

# ============================================================
# STEP 7: Log completion
# ============================================================
log "Execution complete (HTTP $HTTP_CODE)"

# Exit with appropriate code
if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "201" ]; then
    exit 0
else
    exit 1
fi
