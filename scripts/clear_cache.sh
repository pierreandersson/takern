#!/bin/bash
# Clear the stats-api cache on the Websupport server.
# Reads the cron secret from the server config file or prompts for it.

SECRET_FILE="$HOME/.media-snapshot-generator/takern_cron_secret.txt"
BASE_URL="https://pierrea.se/takern/cron-update.php"

if [ -f "$SECRET_FILE" ]; then
    KEY=$(cat "$SECRET_FILE")
elif [ -n "$TAKERN_CRON_SECRET" ]; then
    KEY="$TAKERN_CRON_SECRET"
else
    echo -n "Cron secret key: "
    read -r KEY
fi

echo "Clearing cache..."
RESPONSE=$(curl -s "${BASE_URL}?key=${KEY}&action=clear-cache")
echo "$RESPONSE"
