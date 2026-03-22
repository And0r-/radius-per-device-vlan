#!/bin/sh
# Start the DaathNet VLAN API server on pfSense
# Usage: PFSENSE_API_KEY=<secret> PFSENSE_PARENT_IF=ix2 sh start.sh
#
# For dev:  PFSENSE_API_KEY=devkey PFSENSE_PARENT_IF=vtnet0 php -S 0.0.0.0:9443 api.php
# For prod: PFSENSE_API_KEY=<secret> PFSENSE_PARENT_IF=ix2 php -S 192.168.4.1:9443 api.php

set -e

: "${PFSENSE_API_KEY:=CHANGE_ME}"
: "${PFSENSE_PARENT_IF:=ix2}"
: "${LISTEN:=0.0.0.0:9443}"

export PFSENSE_API_KEY PFSENSE_PARENT_IF

echo "==> Starting DaathNet VLAN API on ${LISTEN}"
echo "    Parent interface: ${PFSENSE_PARENT_IF}"
echo "    API key: ${PFSENSE_API_KEY:0:4}..."

cd /usr/local/www/api
exec php -S "${LISTEN}" api.php
