#!/usr/bin/env bash
#
# Dev environment setup script.
#
# Seeds backend state files with fixture data so that dev/UAT features
# like temperature display, ETA calculation, and dynamic heat-to-target
# work without a real ESP32 or historical heating data.
#
# Usage:
#   ./scripts/dev-setup.sh                  # Copy fixtures + set up .env
#   ./scripts/dev-setup.sh fixtures          # Copy fixtures only
#   ./scripts/dev-setup.sh env               # Set up .env only
#   ./scripts/dev-setup.sh --force           # Overwrite existing state files
#   ./scripts/dev-setup.sh fixtures --force  # Overwrite fixtures only

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

FIXTURES_DIR="$PROJECT_ROOT/backend/config/dev-fixtures"
STATE_DIR="$PROJECT_ROOT/backend/storage/state"
ENV_FILE="$PROJECT_ROOT/backend/.env"
ENV_DEV="$PROJECT_ROOT/backend/config/env.development"

FORCE=false
for arg in "$@"; do
    [[ "$arg" == "--force" ]] && FORCE=true
done

green() { echo -e "\033[0;32m[OK]\033[0m $1"; }
info()  { echo -e "\033[0;34m[INFO]\033[0m $1"; }

copy_fixtures() {
    mkdir -p "$STATE_DIR"

    for fixture in "$FIXTURES_DIR"/*.json; do
        name="$(basename "$fixture")"
        if [[ "$FORCE" == true || ! -f "$STATE_DIR/$name" ]]; then
            cp "$fixture" "$STATE_DIR/$name"
            green "Seeded $name"
        else
            info "$name already exists, skipping (use --force to overwrite)"
        fi
    done
}

setup_env() {
    if [[ ! -f "$ENV_FILE" ]]; then
        if [[ -f "$ENV_DEV" ]]; then
            cp "$ENV_DEV" "$ENV_FILE"
            green "Created .env from env.development"
        else
            info "No env.development found — create backend/.env manually"
        fi
    else
        info ".env already exists, skipping"
    fi
}

# First non-flag argument is the command
CMD="all"
for arg in "$@"; do
    [[ "$arg" != "--force" ]] && CMD="$arg" && break
done

case "$CMD" in
    fixtures) copy_fixtures ;;
    env)      setup_env ;;
    all)
        setup_env
        copy_fixtures
        green "Dev environment ready"
        ;;
    *)
        echo "Usage: $0 [fixtures|env|all] [--force]"
        exit 1
        ;;
esac
