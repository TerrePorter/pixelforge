#!/usr/bin/env bash
#
# Redeploy Pixelforge on the server after the first setup (see README, "Deploying").
# Run it as the user that owns the code, not root:
#
#   ./deploy/deploy.sh
#
# It pulls the current branch, installs dependencies, builds the frontend, migrates,
# rebuilds Laravel's caches and restarts the queue worker.

set -euo pipefail

if [ "$(id -u)" -eq 0 ]; then
    echo "Don't run this as root: cache files would end up owned by root." >&2
    exit 1
fi

cd "$(dirname "$0")/.."

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

step "Pulling the latest code"
git pull --ff-only

step "Installing PHP dependencies"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

step "Building the frontend"
npm ci --no-audit --no-fund
npm run build

step "Migrating the database"
php artisan migrate --force

step "Rebuilding caches"
php artisan optimize:clear
php artisan optimize

# The worker keeps the old code in memory. This tells it to exit after its current job;
# supervisor or systemd then starts it again on the new code.
step "Restarting the queue worker"
php artisan queue:restart

step "Done"
