#!/bin/bash
set -euo pipefail

APP_DIR="/var/www/html/app"
QUEUE_SERVICE="kawakib-queue-worker"

# Function to display an error message and exit
error_exit() {
  echo "Error: $1" >&2
  exit 1
}

# Function to check if a command is available
check_command() {
  command -v "$1" >/dev/null 2>&1 || error_exit "$1 is not installed or not available in PATH."
}

# Function to perform migrations based on the argument
run_migration() {
  if [[ $1 == "fresh" ]]; then
    echo "Running migrate:fresh..."
    php artisan migrate:fresh --seed
  else
    echo "Running migrate..."
    php artisan migrate
  fi
}

# Generate the expected systemd unit file content
generate_service_unit() {
  local PHP_BIN
  PHP_BIN=$(command -v php)

  cat <<EOF
[Unit]
Description=Kawakib Laravel Queue Worker
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5
StartLimitBurst=5
StartLimitIntervalSec=60
SyslogIdentifier=${QUEUE_SERVICE}
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF
}

# Function to ensure the queue worker service is installed, up-to-date, and running
ensure_queue_worker() {
  local SERVICE_FILE="/etc/systemd/system/${QUEUE_SERVICE}.service"
  local DESIRED
  DESIRED=$(generate_service_unit)

  local NEEDS_RELOAD=false

  # Always reconcile: create or update the unit file if it differs
  if [[ ! -f "$SERVICE_FILE" ]] || [[ "$(cat "$SERVICE_FILE")" != "$DESIRED" ]]; then
    echo "Installing/updating systemd service: ${QUEUE_SERVICE}..."
    echo "$DESIRED" > "$SERVICE_FILE"
    NEEDS_RELOAD=true
  fi

  if [[ "$NEEDS_RELOAD" == true ]]; then
    systemctl daemon-reload
    systemctl enable "${QUEUE_SERVICE}"
  fi

  # Gracefully signal Laravel to finish current job, then restart the service
  echo "Restarting ${QUEUE_SERVICE}..."
  php artisan queue:restart 2>/dev/null || true
  systemctl restart "${QUEUE_SERVICE}"

  # Verify it actually came up
  sleep 2
  if systemctl is-active --quiet "${QUEUE_SERVICE}"; then
    echo "Queue worker is running."
  else
    echo "WARNING: ${QUEUE_SERVICE} failed to start. Check logs: journalctl -u ${QUEUE_SERVICE} -n 50" >&2
  fi
}

# Function to display script usage
usage() {
  echo "Usage: $0 [fresh|migrate]"
  echo " - fresh: Run migrate:fresh with seeding."
  echo " - migrate: Run ordinary migrate."
  echo "If no argument is supplied, no migration will be performed."
  exit 1
}

# Validate arguments early before doing any work
if [[ $# -gt 1 ]]; then
  echo "Error: Too many arguments provided."
  usage
elif [[ $# -eq 1 && "$1" != "fresh" && "$1" != "migrate" ]]; then
  echo "Invalid argument: $1"
  usage
fi

# Check required commands
check_command git
check_command composer
check_command php
check_command bun
check_command systemctl

cd "$APP_DIR" || error_exit "Failed to change to $APP_DIR"

# Store current lock file hashes to detect changes after sync
OLD_COMPOSER_HASH=$(md5sum composer.lock 2>/dev/null || echo "none")
OLD_BUN_HASH=$(md5sum bun.lockb 2>/dev/null || echo "none")

# Sync local code to match origin/main
echo "Fetching latest from origin..."
git fetch origin

echo "Resetting to origin/main..."
git reset --hard origin/main

# Reinstall dependencies only if lock files changed
NEW_COMPOSER_HASH=$(md5sum composer.lock 2>/dev/null || echo "none")
NEW_BUN_HASH=$(md5sum bun.lockb 2>/dev/null || echo "none")

if [[ "$OLD_COMPOSER_HASH" != "$NEW_COMPOSER_HASH" ]]; then
  echo "composer.lock changed. Installing Composer dependencies..."
  composer install --prefer-dist --no-interaction
else
  echo "composer.lock unchanged. Skipping composer install."
fi

if [[ "$OLD_BUN_HASH" != "$NEW_BUN_HASH" ]]; then
  echo "bun.lockb changed. Running bun install..."
  bun install
else
  echo "bun.lockb unchanged. Skipping bun install."
fi

# Build
echo "Running 'bun run build'..."
bun run build

# Run migration if requested
if [[ $# -eq 1 ]]; then
  run_migration "$1"
else
  echo "No migration option provided. Skipping migrations..."
fi

# Ensure queue worker is set up and running
ensure_queue_worker

echo "Script executed successfully."
