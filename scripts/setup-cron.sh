#!/bin/bash
#
# Sets up the Laravel scheduler cron entry.
# Usage: bash scripts/setup-cron.sh
#

set -e

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CRON_CMD="* * * * * cd $PROJECT_DIR && php artisan schedule:run >> /dev/null 2>&1"

# Check if cron entry already exists
if crontab -l 2>/dev/null | grep -qF "schedule:run"; then
    echo "Cron entry already exists. Skipping."
    crontab -l 2>/dev/null | grep "schedule:run"
    exit 0
fi

# Append to existing crontab
(crontab -l 2>/dev/null; echo "$CRON_CMD") | crontab -

echo "Cron entry added:"
echo "  $CRON_CMD"
echo ""
echo "Verify with: crontab -l"
echo "Remove with: crontab -e (and delete the line)"
