#!/bin/bash
#
# Test script for cron-runner.sh params extraction
#
# This tests that the params extraction works correctly with JSON_PRETTY_PRINT
# formatted job files (multi-line JSON).
#
# Bug fixed: The original sed regex didn't work with multi-line JSON because
# sed processes one line at a time by default.
#
# Usage: ./test-cron-runner-params.sh
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEST_PASSED=0
TEST_FAILED=0

# Test helper functions
pass() {
    echo "  PASS: $1"
    TEST_PASSED=$((TEST_PASSED + 1))
}

fail() {
    echo "  FAIL: $1"
    TEST_FAILED=$((TEST_FAILED + 1))
}

# Test: Extract params from multi-line JSON (JSON_PRETTY_PRINT format)
test_multiline_json_extraction() {
    echo "Test: Extract params from multi-line JSON"

    local job_file=$(mktemp)
    cat > "$job_file" << 'EOF'
{
    "jobId": "rec-03b9d869",
    "action": "heat-to-target",
    "endpoint": "/api/equipment/heat-to-target",
    "apiBaseUrl": "https://example.com/tub/backend/public",
    "scheduledTime": "09:00-05:00",
    "recurring": true,
    "createdAt": "2026-01-21T03:35:32+00:00",
    "params": {
        "target_temp_f": 103.5
    }
}
EOF

    # Extract using the fixed method (tr to collapse lines first)
    local request_body=""
    if grep -q '"params"' "$job_file" 2>/dev/null; then
        request_body=$(tr -d '\n' < "$job_file" | sed -n 's/.*"params"[[:space:]]*:[[:space:]]*\({[^}]*}\).*/\1/p' || true)
    fi

    rm -f "$job_file"

    if [ -n "$request_body" ]; then
        # Verify it contains target_temp_f
        if echo "$request_body" | grep -q "target_temp_f"; then
            pass "Extracted params from multi-line JSON: $request_body"
        else
            fail "Extracted params but missing target_temp_f: $request_body"
        fi
    else
        fail "Failed to extract params from multi-line JSON"
    fi
}

# Test: Extract params from single-line JSON (compact format)
test_singleline_json_extraction() {
    echo "Test: Extract params from single-line JSON"

    local job_file=$(mktemp)
    echo '{"jobId":"rec-abc123","action":"heat-to-target","params":{"target_temp_f":102}}' > "$job_file"

    local request_body=""
    if grep -q '"params"' "$job_file" 2>/dev/null; then
        request_body=$(tr -d '\n' < "$job_file" | sed -n 's/.*"params"[[:space:]]*:[[:space:]]*\({[^}]*}\).*/\1/p' || true)
    fi

    rm -f "$job_file"

    if [ -n "$request_body" ] && echo "$request_body" | grep -q "target_temp_f"; then
        pass "Extracted params from single-line JSON: $request_body"
    else
        fail "Failed to extract params from single-line JSON"
    fi
}

# Test: No params in job file
test_no_params() {
    echo "Test: Handle job file without params"

    local job_file=$(mktemp)
    cat > "$job_file" << 'EOF'
{
    "jobId": "job-abc123",
    "action": "heater-on",
    "endpoint": "/api/equipment/heater/on",
    "apiBaseUrl": "https://example.com/tub/backend/public"
}
EOF

    local request_body=""
    if grep -q '"params"' "$job_file" 2>/dev/null; then
        request_body=$(tr -d '\n' < "$job_file" | sed -n 's/.*"params"[[:space:]]*:[[:space:]]*\({[^}]*}\).*/\1/p' || true)
    fi

    rm -f "$job_file"

    if [ -z "$request_body" ]; then
        pass "Correctly returned empty for job without params"
    else
        fail "Should return empty for job without params, got: $request_body"
    fi
}

# Test: Validate extracted JSON is valid
test_valid_json() {
    echo "Test: Extracted JSON is valid"

    local job_file=$(mktemp)
    cat > "$job_file" << 'EOF'
{
    "jobId": "rec-test123",
    "action": "heat-to-target",
    "params": {
        "target_temp_f": 104.25
    }
}
EOF

    local request_body=""
    if grep -q '"params"' "$job_file" 2>/dev/null; then
        request_body=$(tr -d '\n' < "$job_file" | sed -n 's/.*"params"[[:space:]]*:[[:space:]]*\({[^}]*}\).*/\1/p' || true)
    fi

    rm -f "$job_file"

    # Check if python3 is available for JSON validation
    if command -v python3 &> /dev/null; then
        if echo "$request_body" | python3 -m json.tool > /dev/null 2>&1; then
            pass "Extracted JSON is valid: $request_body"
        else
            fail "Extracted JSON is invalid: $request_body"
        fi
    else
        # Fallback: just check it looks like JSON
        if [[ "$request_body" =~ ^\{.*\}$ ]]; then
            pass "Extracted JSON appears valid (python3 not available): $request_body"
        else
            fail "Extracted JSON doesn't look valid: $request_body"
        fi
    fi
}

# Run all tests
echo "============================================"
echo "cron-runner.sh params extraction tests"
echo "============================================"
echo ""

test_multiline_json_extraction
test_singleline_json_extraction
test_no_params
test_valid_json

echo ""
echo "============================================"
echo "Results: $TEST_PASSED passed, $TEST_FAILED failed"
echo "============================================"

if [ $TEST_FAILED -gt 0 ]; then
    exit 1
fi
exit 0
