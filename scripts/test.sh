#!/bin/bash
#
# Test Management Script for Hot Tub Controller
#
# Usage:
#   ./scripts/test.sh [command] [options]
#
# Commands:
#   setup     - Configure environment for testing (copies env.testing to .env)
#   cleanup   - Kill leftover processes on test ports (8080, 5173)
#   backend   - Run backend PHPUnit tests
#   frontend  - Run frontend Vitest unit tests
#   e2e       - Run Playwright E2E tests
#   esp32     - Run ESP32 native unit tests
#   all       - Run all test suites (default)
#   status    - Check if test environment is properly configured
#
# Options:
#   --live    - Include live API tests (backend only)
#   --watch   - Run in watch mode (frontend unit tests only)
#
# Examples:
#   ./scripts/test.sh              # Run all tests
#   ./scripts/test.sh e2e          # Run only E2E tests
#   ./scripts/test.sh backend      # Run backend tests
#   ./scripts/test.sh setup        # Just set up environment
#   ./scripts/test.sh cleanup      # Just clean up ports
#

set -e

# Ensure .env is restored even if the script exits unexpectedly
trap 'restore_env_on_exit' EXIT

restore_env_on_exit() {
    local backup="$(dirname "${BASH_SOURCE[0]}")/../backend/.env.pre-test-backup"
    if [[ -f "$backup" ]]; then
        mv "$backup" "$(dirname "$backup")/.env" 2>/dev/null || true
    fi
}

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Get the project root directory (parent of scripts/)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Test ports
BACKEND_PORT=8080
FRONTEND_PORT=5173

# Print colored status message
info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

success() {
    echo -e "${GREEN}[OK]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Kill processes on specified ports
kill_port() {
    local port=$1
    if fuser -k "$port/tcp" 2>/dev/null; then
        info "Killed process on port $port"
    fi
}

# Paths for .env backup/restore
ENV_FILE="$PROJECT_ROOT/backend/.env"
ENV_BACKUP="$PROJECT_ROOT/backend/.env.pre-test-backup"
ENV_TESTING="$PROJECT_ROOT/backend/config/env.testing"
ENV_DEVELOPMENT="$PROJECT_ROOT/backend/config/env.development"

# Setup test environment (backs up existing .env first)
do_setup() {
    info "Setting up test environment..."

    if [[ ! -f "$ENV_TESTING" ]]; then
        error "Testing config not found: $ENV_TESTING"
        exit 1
    fi

    # Back up existing .env if it exists and isn't already a backup situation
    if [[ -f "$ENV_FILE" && ! -f "$ENV_BACKUP" ]]; then
        cp "$ENV_FILE" "$ENV_BACKUP"
        success "Backed up .env to .env.pre-test-backup"
    fi

    cp "$ENV_TESTING" "$ENV_FILE"
    success "Copied env.testing to .env"

    # Verify JWT_SECRET is set
    if grep -q "JWT_SECRET=test-secret" "$ENV_FILE"; then
        success "JWT_SECRET is configured"
    else
        error "JWT_SECRET not found in .env"
        exit 1
    fi

    # Verify EXTERNAL_API_MODE is stub
    if grep -q "EXTERNAL_API_MODE=stub" "$ENV_FILE"; then
        success "EXTERNAL_API_MODE=stub (safe for testing)"
    else
        warn "EXTERNAL_API_MODE is not set to stub - tests may hit real APIs!"
    fi

    success "Test environment setup complete"
}

# Restore .env to pre-test state
do_restore_env() {
    if [[ -f "$ENV_BACKUP" ]]; then
        mv "$ENV_BACKUP" "$ENV_FILE"
        success "Restored .env from pre-test backup"
    elif [[ -f "$ENV_DEVELOPMENT" ]]; then
        cp "$ENV_DEVELOPMENT" "$ENV_FILE"
        success "Restored .env from env.development"
    else
        warn "No backup or env.development found - .env left as env.testing"
    fi
}

# Cleanup test processes
do_cleanup() {
    info "Cleaning up test processes..."

    kill_port $BACKEND_PORT
    kill_port $FRONTEND_PORT

    # Wait for ports to be released
    sleep 1

    # Verify ports are free
    local still_used=""
    if lsof -i:$BACKEND_PORT >/dev/null 2>&1; then
        still_used="$BACKEND_PORT"
    fi
    if lsof -i:$FRONTEND_PORT >/dev/null 2>&1; then
        still_used="$still_used $FRONTEND_PORT"
    fi

    if [[ -n "$still_used" ]]; then
        warn "Ports still in use:$still_used - trying harder..."
        sleep 2
        kill_port $BACKEND_PORT
        kill_port $FRONTEND_PORT
        sleep 1
    fi

    success "Cleanup complete"
}

# Check test environment status
do_status() {
    info "Checking test environment status..."

    local issues=0

    # Check .env file
    local env_file="$PROJECT_ROOT/backend/.env"
    if [[ -f "$env_file" ]]; then
        success ".env file exists"

        # Check critical settings
        if grep -q "EXTERNAL_API_MODE=stub" "$env_file"; then
            success "EXTERNAL_API_MODE=stub"
        else
            warn "EXTERNAL_API_MODE is not 'stub' - may hit real APIs"
            ((issues++))
        fi

        if grep -q "JWT_SECRET=" "$env_file" && ! grep -q "JWT_SECRET=$" "$env_file"; then
            success "JWT_SECRET is set"
        else
            error "JWT_SECRET is empty or missing"
            ((issues++))
        fi
    else
        error ".env file missing - run './scripts/test.sh setup' first"
        ((issues++))
    fi

    # Check ports
    if lsof -i:$BACKEND_PORT >/dev/null 2>&1; then
        warn "Port $BACKEND_PORT is in use"
        ((issues++))
    else
        success "Port $BACKEND_PORT is free"
    fi

    if lsof -i:$FRONTEND_PORT >/dev/null 2>&1; then
        warn "Port $FRONTEND_PORT is in use"
        ((issues++))
    else
        success "Port $FRONTEND_PORT is free"
    fi

    # Summary
    echo ""
    if [[ $issues -eq 0 ]]; then
        success "Environment is ready for testing"
        return 0
    else
        warn "Found $issues issue(s) - run './scripts/test.sh setup' and './scripts/test.sh cleanup'"
        return 1
    fi
}

# Run backend tests
do_backend() {
    local live_flag=""
    if [[ "$1" == "--live" ]]; then
        live_flag="--group live"
        info "Running backend tests (including live API tests)..."
    else
        info "Running backend tests..."
    fi

    cd "$PROJECT_ROOT/backend"

    if [[ -n "$live_flag" ]]; then
        php vendor/bin/phpunit $live_flag
    else
        php vendor/bin/phpunit
    fi

    success "Backend tests complete"
}

# Run frontend unit tests
do_frontend() {
    info "Running frontend unit tests..."

    cd "$PROJECT_ROOT/frontend"

    if [[ "$1" == "--watch" ]]; then
        npm run test:watch
    else
        npm run test
    fi

    success "Frontend unit tests complete"
}

# Run E2E tests
do_e2e() {
    info "Running E2E tests..."

    # Ensure environment is set up
    do_setup

    # Clean up any leftover processes
    do_cleanup

    cd "$PROJECT_ROOT/frontend"
    local e2e_result=0
    npm run test:e2e || e2e_result=$?

    # Cleanup after E2E tests
    do_cleanup

    if [[ $e2e_result -ne 0 ]]; then
        error "E2E tests failed"
        return $e2e_result
    fi

    success "E2E tests complete"
}

# Run ESP32 tests
do_esp32() {
    info "Running ESP32 unit tests..."

    cd "$PROJECT_ROOT/esp32"
    pio test -e native

    success "ESP32 tests complete"
}

# Run all tests
do_all() {
    info "Running full test suite..."
    echo ""

    # Setup
    do_setup
    echo ""

    # Cleanup before starting
    do_cleanup
    echo ""

    # Track results
    local failed=0

    # Backend
    echo "=========================================="
    echo " Backend PHPUnit Tests"
    echo "=========================================="
    if do_backend; then
        success "Backend: PASSED"
    else
        error "Backend: FAILED"
        ((failed++))
    fi
    echo ""

    # Frontend unit
    echo "=========================================="
    echo " Frontend Unit Tests (Vitest)"
    echo "=========================================="
    if do_frontend; then
        success "Frontend Unit: PASSED"
    else
        error "Frontend Unit: FAILED"
        ((failed++))
    fi
    echo ""

    # ESP32
    echo "=========================================="
    echo " ESP32 Unit Tests (Unity)"
    echo "=========================================="
    if do_esp32; then
        success "ESP32: PASSED"
    else
        error "ESP32: FAILED"
        ((failed++))
    fi
    echo ""

    # E2E (includes its own setup/cleanup)
    echo "=========================================="
    echo " E2E Tests (Playwright)"
    echo "=========================================="
    if do_e2e; then
        success "E2E: PASSED"
    else
        error "E2E: FAILED"
        ((failed++))
    fi
    echo ""

    # Final cleanup
    do_cleanup
    echo ""

    # Restore .env
    do_restore_env
    echo ""

    # Summary
    echo "=========================================="
    echo " Test Summary"
    echo "=========================================="
    if [[ $failed -eq 0 ]]; then
        success "All test suites passed!"
        return 0
    else
        error "$failed test suite(s) failed"
        return 1
    fi
}

# Show usage
show_usage() {
    cat << 'EOF'
Test Management Script for Hot Tub Controller

Usage:
  ./scripts/test.sh [command] [options]

Commands:
  setup     - Configure environment for testing (copies env.testing to .env)
  cleanup   - Kill leftover processes on test ports (8080, 5173)
  backend   - Run backend PHPUnit tests
  frontend  - Run frontend Vitest unit tests
  e2e       - Run Playwright E2E tests
  esp32     - Run ESP32 native unit tests
  all       - Run all test suites (default)
  status    - Check if test environment is properly configured

Options:
  --live    - Include live API tests (backend only)
  --watch   - Run in watch mode (frontend unit tests only)

Examples:
  ./scripts/test.sh              # Run all tests
  ./scripts/test.sh e2e          # Run only E2E tests
  ./scripts/test.sh backend      # Run backend tests
  ./scripts/test.sh setup        # Just set up environment
  ./scripts/test.sh cleanup      # Just clean up ports
EOF
}

# Main entry point
main() {
    local command="${1:-all}"
    local option="${2:-}"

    case "$command" in
        setup)
            do_setup
            ;;
        cleanup)
            do_cleanup
            ;;
        status)
            do_status
            ;;
        backend)
            do_setup
            do_backend "$option"
            do_restore_env
            ;;
        frontend)
            do_frontend "$option"
            ;;
        e2e)
            do_e2e
            do_restore_env
            ;;
        esp32)
            do_esp32
            ;;
        all)
            do_all
            ;;
        help|--help|-h)
            show_usage
            ;;
        *)
            error "Unknown command: $command"
            echo ""
            show_usage
            exit 1
            ;;
    esac
}

main "$@"
