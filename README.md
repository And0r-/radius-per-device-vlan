# FreeRADIUS Per-Device VLAN Server

Every WiFi device gets its own VLAN. No device sees another on Layer 2.

![Dashboard](screenshots/dashboard.png)

## How it works

```
Device connects to WiFi
        │
        ▼
   UniFi AP ──RADIUS──▶ FreeRADIUS (rlm_python3)
                              │
                    ┌─────────┴──────────┐
                    │ PostgreSQL         │
                    │ (identity + SSID)  │
                    │  = unique VLAN     │
                    └─────────┬──────────┘
                              │
                    Known? ───┤──── Yes → return assigned VLAN
                              │
                              No → assign next free VLAN
                                   │
                                   ▼
                            pfSense API
                            (auto-create VLAN,
                             interface, DHCP,
                             firewall rules)
```

**Key design:** `(identity, ssid)` is the unique key. Same device on different SSIDs gets different VLANs. An enterprise user on `DaathNet-Secure` gets VLAN 100 with intranet access, but the same device on `DaathNet-Guest` gets VLAN 115 with internet only.

## Stack

| Component | Purpose |
|-----------|---------|
| **FreeRADIUS 3.2.5** | RADIUS auth (EAP-PEAP/MSCHAPv2 + MAC-auth) |
| **PostgreSQL 16** | Device/user database, VLAN pool |
| **Redis 7** | Session cache |
| **Flask WebUI** | Management interface (port 8443) |
| **pfSense PHP API** | Auto-creates VLANs on the firewall |

## VLAN Types

Configured via `SSID_MAP` in `.env`:

```
SSID_MAP=e:DaathNet-Secure,o:DaathNet-IoT-Offline,g:DaathNet-Guest
```

| Type | Meaning | Firewall Rules |
|------|---------|----------------|
| `e` | Enterprise | Custom (extended intranet access) |
| `i` | Internet (default) | Internet yes, internal blocked |
| `o` | Offline | Everything blocked except DNS/DHCP |
| `g` | Guest | Internet only (open network) |

SSIDs not in the map default to `i` (internet).

## Quick Start

### 1. RADIUS Server (Docker Compose)

```bash
# Clone
git clone https://github.com/And0r-/radius-per-device-vlan.git
cd radius-per-device-vlan

# Configure
cp .env.example .env
# Edit .env: set passwords, RADIUS secret, pfSense API key, SSID_MAP

# Start
docker compose up -d
```

### 2. pfSense API

```bash
# Copy API to pfSense
scp pfsense-api/api.php root@<pfsense-ip>:/root/pfsense-api/api.php

# Create symlink for nginx
ssh root@<pfsense-ip> "mkdir -p /usr/local/www/api && \
  ln -sf /root/pfsense-api/api.php /usr/local/www/api/api.php"

# Create .env on pfSense
ssh root@<pfsense-ip> "cat > /root/pfsense-api/.env << EOF
PFSENSE_API_KEY=<same-key-as-in-radius-.env>
PFSENSE_PARENT_IF=<trunk-interface-to-ap>
EOF"

# Initialize floating rules
curl -sk -X POST "https://<pfsense-ip>/api/api.php?endpoint=/floating/init" \
  -H "X-API-Key: <key>"
```

### 3. UniFi Controller

- Create RADIUS profile: IP of RADIUS server, port 1812, shared secret
- Create SSIDs with RADIUS authentication
- For PSK SSIDs: enable "RADIUS MAC Authentication", MAC format: `aabbccddeeff` (lowercase, no separators)

## Web UI

![Users](screenshots/users.png)

Management interface on port 8443 (self-signed TLS).

- **Dashboard** — Pool status, VLAN type distribution, recent auth logs
- **Devices** — Add/remove MAC-auth devices with SSID dropdown
- **Users** — Add/remove enterprise users with SSID dropdown
- **Logs** — Filterable auth log

![Devices](screenshots/devices.png)

SSID dropdowns are auto-populated from `SSID_MAP`.

## CLI Management

```bash
# Enterprise Users
./manage.sh add-user <username> <password> <ssid> [--name "..."]
./manage.sh remove-user <username> <ssid>
./manage.sh list-users
./manage.sh change-password <username> <ssid> <new-password>

# MAC Devices
./manage.sh add-device <mac> <ssid> [--name "..."]
./manage.sh remove-device <mac> <ssid>
./manage.sh list-devices
./manage.sh remove-all-for-mac <mac>

# Status
./manage.sh pool-status
./manage.sh auth-log [--last N]
./manage.sh list-all
```

## pfSense API Endpoints

```
GET    /health          — Health check
POST   /vlan/create     — Create VLAN + interface + DHCP + firewall rules
POST   /vlan/rename     — Rename VLAN interface
POST   /vlan/move       — Move VLAN between online/offline
GET    /vlan/list       — List managed VLANs
DELETE /vlan/{id}       — Remove VLAN completely
POST   /floating/init   — Initialize floating firewall rules
GET    /floating/status — Show floating rule state
```

All endpoints require `X-API-Key` header. The API only modifies resources it created (ownership tracked in SQLite).

## Firewall Rules

**Floating rules** (no quick, applied to all managed VLANs):
1. Block all (first = lowest priority)
2. Allow Internet NOT internal_networks (overrides block)

**Per-interface rules** (created with each VLAN):
1. Allow DNS to own gateway (`10.110.{id}.1:53`)
2. Allow DHCP to own gateway (`10.110.{id}.1:67-68`)
3. [Offline only] Block all (overrides floating allow-internet)

## Security Notes

- **API key:** minimum 32 characters, enforced at startup
- **Ownership guards:** pfSense API can NEVER modify interfaces/rules it didn't create
- **No hyphens** in VLAN names — pfSense pf interprets them as minus operators
- **Rate limiting:** 10 failed auth attempts per minute, tracked in SQLite
- **Request logging:** all API requests logged to syslog
- **File locking:** mutex on all mutation endpoints
- **TLS:** pfSense API runs through nginx (HTTPS), WebUI has self-signed cert
- **VLAN limit:** configurable safety cap (default 100) prevents pool exhaustion attacks

## Troubleshooting

```bash
# FreeRADIUS debug mode
docker compose stop freeradius
docker compose run --rm --entrypoint bash freeradius -c \
  "ln -sf /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/sql && \
   ln -sf /etc/freeradius/3.0/mods-available/python3 /etc/freeradius/3.0/mods-enabled/python3 && \
   rm -f /etc/freeradius/3.0/mods-enabled/files && freeradius -X"

# Check DB
docker compose exec postgres psql -U radius -d radius -c \
  "SELECT * FROM vlan_assignments ORDER BY vlan_id;"

# Check pfSense API
curl -sk "https://<pfsense>/api/api.php?endpoint=/vlan/list" \
  -H "X-API-Key: <key>"

# Check pfSense PHP errors
ssh root@<pfsense> "tail -20 /tmp/PHP_errors.log"

# Listen for RADIUS packets
tcpdump -i any port 1812 -n
```

## File Structure

```
├── docker-compose.yml
├── .env.example                        # Template for secrets + SSID_MAP
├── manage.sh                           # CLI management tool
├── freeradius/
│   ├── Dockerfile
│   ├── entrypoint.sh                   # Cert gen + envsubst + module symlinks
│   ├── clients.conf                    # RADIUS clients (template)
│   ├── mods-available/
│   │   ├── sql                         # PostgreSQL accounting (template)
│   │   ├── eap                         # EAP-PEAP/MSCHAPv2
│   │   └── python3                     # Python3 module config
│   ├── mods-config/python3/
│   │   └── radius_auth.py              # Core auth logic
│   └── sites-available/
│       ├── default                     # Outer server (MAC-auth + EAP)
│       └── inner-tunnel                # PEAP inner tunnel (Enterprise)
├── webui/
│   ├── Dockerfile
│   ├── app.py                          # Flask web management UI
│   ├── requirements.txt
│   └── templates/
├── pfsense-api/
│   ├── api.php                         # pfSense VLAN management API
│   ├── .env.example                    # pfSense API config template
│   ├── start.sh                        # Standalone dev server (not for prod)
│   └── SECURITY-AUDIT.md              # Audit findings + status
└── postgres/
    ├── 01-schema.sql                   # DB schema + VLAN pool
    └── 02-seed.sh                      # Placeholder (add via manage.sh/WebUI)
```

![Auth Log](screenshots/auth-log.png)
