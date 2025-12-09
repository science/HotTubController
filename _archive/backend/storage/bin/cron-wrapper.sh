#!/bin/bash
#
# Self-Deleting Cron Wrapper Script
# Hot Tub Controller - Cron Management System
#
# This script executes a curl command via config file and then removes
# itself from the crontab to ensure "one-shot" cron behavior.
#
# Usage: cron-wrapper.sh <cron-id> <curl-config-file>
#
# Arguments:
#   cron-id: Unique identifier for this cron job (used in comment)
#   curl-config-file: Path to curl config file with API call details
#
# Safety Features:
#   - Validates arguments before execution
#   - Logs all operations for audit trail
#   - Atomic crontab updates to prevent corruption
#   - Error handling and cleanup

set -euo pipefail

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"
LOG_FILE="$PROJECT_ROOT/storage/logs/cron-wrapper.log"
TEMP_CRONTAB="/tmp/crontab.$$"

# Ensure log directory exists
mkdir -p "$(dirname "$LOG_FILE")"

# Logging function
log_message() {
    local level="$1"
    shift
    local message="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] [$level] [$$] $message" >> "$LOG_FILE"
}

# Error handler
error_exit() {
    local message="$1"
    local exit_code="${2:-1}"
    log_message "ERROR" "$message"
    echo "ERROR: $message" >&2
    exit "$exit_code"
}

# Cleanup function
cleanup() {
    rm -f "$TEMP_CRONTAB" 2>/dev/null || true
}

# Set trap for cleanup
trap cleanup EXIT

# Validate arguments
if [ "$#" -ne 2 ]; then
    error_exit "Usage: $0 <cron-id> <curl-config-file>" 2
fi

CRON_ID="$1"
CONFIG_FILE="$2"

log_message "INFO" "Starting cron wrapper for ID: $CRON_ID"

# Validate cron ID format (should be HOT_TUB_START:xxx or HOT_TUB_MONITOR:xxx)
if [[ ! "$CRON_ID" =~ ^HOT_TUB_(START|MONITOR):[a-zA-Z0-9_-]+$ ]]; then
    error_exit "Invalid cron ID format: $CRON_ID"
fi

# Validate config file exists and is readable
if [ ! -f "$CONFIG_FILE" ]; then
    error_exit "Curl config file not found: $CONFIG_FILE"
fi

if [ ! -r "$CONFIG_FILE" ]; then
    error_exit "Curl config file not readable: $CONFIG_FILE"
fi

log_message "INFO" "Executing API call with config: $CONFIG_FILE"

# Execute the curl command with timeout and error handling
if curl --config "$CONFIG_FILE" --max-time 30 --retry 2 --retry-delay 5; then
    log_message "INFO" "API call succeeded for cron ID: $CRON_ID"
    API_SUCCESS=true
else
    log_message "ERROR" "API call failed for cron ID: $CRON_ID (exit code: $?)"
    API_SUCCESS=false
fi

# Self-delete from crontab (regardless of API success/failure)
log_message "INFO" "Removing cron entry: $CRON_ID"

# Get current crontab, remove this entry, install new crontab
if crontab -l 2>/dev/null > "$TEMP_CRONTAB"; then
    # Remove the line with our cron ID (match the comment)
    if grep -v "# $CRON_ID\$" "$TEMP_CRONTAB" > "${TEMP_CRONTAB}.new"; then
        mv "${TEMP_CRONTAB}.new" "$TEMP_CRONTAB"
        
        # Install the updated crontab
        if crontab "$TEMP_CRONTAB"; then
            log_message "INFO" "Successfully removed cron entry: $CRON_ID"
        else
            log_message "ERROR" "Failed to update crontab after removing: $CRON_ID"
        fi
    else
        log_message "ERROR" "Failed to filter crontab for cron ID: $CRON_ID"
    fi
else
    log_message "WARNING" "No existing crontab found when trying to remove: $CRON_ID"
fi

# Clean up the curl config file if it exists
if [ -f "$CONFIG_FILE" ]; then
    if rm -f "$CONFIG_FILE"; then
        log_message "INFO" "Cleaned up curl config file: $CONFIG_FILE"
    else
        log_message "WARNING" "Failed to clean up curl config file: $CONFIG_FILE"
    fi
fi

# Final status
if [ "$API_SUCCESS" = true ]; then
    log_message "INFO" "Cron wrapper completed successfully for: $CRON_ID"
    exit 0
else
    log_message "ERROR" "Cron wrapper completed with API failure for: $CRON_ID"
    exit 1
fi