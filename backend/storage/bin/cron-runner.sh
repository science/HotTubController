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
#   "recurring": false or true
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
# STEP 1: REMOVE SELF FROM CRONTAB (one-off jobs only)
# This MUST happen first to prevent orphaned crons if script crashes later
# ============================================================
if [ "$IS_RECURRING" != "true" ]; then
    log "Removing from crontab"
    (crontab -l 2>/dev/null | grep -v "HOTTUB:$JOB_ID" | crontab -) || true
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

# ============================================================
# STEP 4: Call API endpoint with Bearer token
# ============================================================
log "Calling API: POST $FULL_URL"

HTTP_RESPONSE=$(mktemp)
HTTP_CODE=$(curl -s -w "%{http_code}" -o "$HTTP_RESPONSE" \
    -X POST "$FULL_URL" \
    -H "Authorization: Bearer $CRON_JWT" \
    -H "Content-Type: application/json" \
    -d "" \
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
# STEP 5: Delete job file (one-off jobs only)
# Recurring jobs keep their job file for the next execution
# ============================================================
if [ "$IS_RECURRING" != "true" ]; then
    rm -f "$JOB_FILE"
    log "Cleaned up job file"
fi

# ============================================================
# STEP 6: Log completion
# ============================================================
log "Execution complete (HTTP $HTTP_CODE)"

# Exit with appropriate code
if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "201" ]; then
    exit 0
else
    exit 1
fi
