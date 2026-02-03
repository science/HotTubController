#!/bin/bash
#
# Log Rotation Cron Script for Hot Tub Controller
#
# Called by cron monthly to trigger log rotation via the API.
# Reads CRON_JWT from .env file for authentication.
#
# Usage: Called by crontab (set up via setup-maintenance-cron.php)
#   0 3 1 * * /path/to/log-rotation-cron.sh # HOTTUB:log-rotation
#

set -euo pipefail

# Determine paths relative to this script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STORAGE_DIR="$(dirname "$SCRIPT_DIR")"
BACKEND_DIR="$(dirname "$STORAGE_DIR")"
ENV_FILE="$BACKEND_DIR/.env"
LOG_FILE="$STORAGE_DIR/logs/cron.log"

# Logging function
log() {
    local timestamp
    timestamp=$(date -Iseconds 2>/dev/null || date '+%Y-%m-%dT%H:%M:%S')
    echo "[$timestamp] log-rotation: $1" >> "$LOG_FILE"
}

# Ensure log directory exists
mkdir -p "$(dirname "$LOG_FILE")"

log "Starting log rotation"

# ============================================================
# STEP 1: Read CRON_JWT and API_BASE_URL from .env
# ============================================================
if [ ! -f "$ENV_FILE" ]; then
    log "ERROR: .env file not found at $ENV_FILE"
    exit 1
fi

# Read CRON_JWT
CRON_JWT=$(grep '^CRON_JWT=' "$ENV_FILE" 2>/dev/null | cut -d'=' -f2- | tr -d '\r\n' || true)
if [ -z "$CRON_JWT" ]; then
    log "ERROR: CRON_JWT not found in .env"
    exit 1
fi

# Read API_BASE_URL
API_BASE_URL=$(grep '^API_BASE_URL=' "$ENV_FILE" 2>/dev/null | cut -d'=' -f2- | tr -d '\r\n' || true)
if [ -z "$API_BASE_URL" ]; then
    log "ERROR: API_BASE_URL not found in .env"
    exit 1
fi

FULL_URL="${API_BASE_URL}/api/maintenance/logs/rotate"

# ============================================================
# STEP 2: Call API endpoint with Bearer token
# ============================================================
log "Calling API: POST $FULL_URL"

HTTP_RESPONSE=$(mktemp)
# Note: -d '{}' is required - LiteSpeed/ModSecurity blocks empty POST requests
HTTP_CODE=$(curl -s -w "%{http_code}" -o "$HTTP_RESPONSE" \
    -X POST "$FULL_URL" \
    -H "Authorization: Bearer $CRON_JWT" \
    -H "Content-Type: application/json" \
    -d '{}' \
    --max-time 60 \
    2>&1) || HTTP_CODE="000"

RESPONSE_BODY=$(cat "$HTTP_RESPONSE" 2>/dev/null || echo "")
rm -f "$HTTP_RESPONSE"

if [ "$HTTP_CODE" = "200" ]; then
    log "SUCCESS: Log rotation completed (HTTP $HTTP_CODE)"
    log "Response: $RESPONSE_BODY"
    exit 0
else
    log "FAILED: API returned HTTP $HTTP_CODE - $RESPONSE_BODY"
    exit 1
fi
