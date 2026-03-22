#!/bin/sh
# Start the DaathNet VLAN API server on pfSense
# Reads config from .env file if present, or uses environment variables.

set -e

API_DIR="/usr/local/www/api"

# Load .env if present
if [ -f "$API_DIR/.env" ]; then
    set -a
    . "$API_DIR/.env"
    set +a
fi

: "${PFSENSE_API_KEY:=CHANGE_ME}"
: "${PFSENSE_PARENT_IF:=ix2}"
: "${PFSENSE_LISTEN:=0.0.0.0:9444}"

export PFSENSE_API_KEY PFSENSE_PARENT_IF

echo "==> Starting DaathNet VLAN API on ${PFSENSE_LISTEN}"
echo "    Parent interface: ${PFSENSE_PARENT_IF}"
echo "    API key: ${PFSENSE_API_KEY:0:4}..."

cd "$API_DIR"
exec php -S "${PFSENSE_LISTEN}" api.php
