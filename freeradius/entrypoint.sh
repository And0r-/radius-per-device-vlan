#!/bin/bash
set -e

RADDB="/etc/freeradius/3.0"
CERT_DIR="/certs"

# Generate self-signed certificates if not present
if [ ! -f "$CERT_DIR/server.pem" ]; then
    echo "==> Generating TLS certificates (10 year validity)..."

    openssl req -new -x509 -nodes \
        -keyout "$CERT_DIR/ca.key" \
        -out "$CERT_DIR/ca.pem" \
        -days 3650 -sha256 \
        -subj "/C=CH/O=FDH/CN=FreeRADIUS CA"

    openssl req -new -nodes \
        -keyout "$CERT_DIR/server.key" \
        -out "$CERT_DIR/server.csr" \
        -subj "/C=CH/O=FDH/CN=freeradius.fdh.li"

    openssl x509 -req \
        -in "$CERT_DIR/server.csr" \
        -CA "$CERT_DIR/ca.pem" \
        -CAkey "$CERT_DIR/ca.key" \
        -CAcreateserial \
        -out "$CERT_DIR/server.pem" \
        -days 3650 -sha256

    echo "==> Generating DH parameters..."
    openssl dhparam -out "$CERT_DIR/dh" 2048

    rm -f "$CERT_DIR/server.csr" "$CERT_DIR/ca.srl"
    echo "==> Certificates generated."
fi

chown -R freerad:freerad "$CERT_DIR"
chmod 640 "$CERT_DIR"/*.key 2>/dev/null || true
chmod 644 "$CERT_DIR"/*.pem "$CERT_DIR"/dh 2>/dev/null || true

# Render config templates (substitute secrets from environment)
echo "==> Rendering config templates..."
envsubst '${RADIUS_SECRET}' < /templates/clients.conf > "$RADDB/clients.conf"
envsubst '${POSTGRES_PASSWORD}' < /templates/mods-available-sql > "$RADDB/mods-available/sql"
chown freerad:freerad "$RADDB/clients.conf" "$RADDB/mods-available/sql"

# Enable required modules
ln -sf "$RADDB/mods-available/sql" "$RADDB/mods-enabled/sql"
rm -f "$RADDB/mods-enabled/python3" 2>/dev/null || true
ln -sf "$RADDB/mods-available/python3" "$RADDB/mods-enabled/python3"

# Disable file-based modules that conflict
rm -f "$RADDB/mods-enabled/files" 2>/dev/null || true

echo "==> Starting FreeRADIUS..."
exec freeradius -f
