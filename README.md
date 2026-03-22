# FreeRADIUS — Per-Device VLAN Architecture

## Konzept

Jedes WiFi-Gerät bekommt sein eigenes VLAN. Kein Gerät sieht ein anderes auf Layer 2.

```
UniFi AP (192.168.5.100)  →  RADIUS (192.168.4.114:1812/1813)
                                  │
                            ┌─────┴─────┐
                            │ FreeRADIUS │──→ PostgreSQL (Devices, Pools)
                            │(rlm_python3)──→ Redis (Sessions)
                            └───────────┘
```

## VLAN Pools

| Pool    | VLAN Range | Subnet                    | pfSense Default                |
|---------|-----------|---------------------------|-------------------------------|
| Offline | 100-119   | 10.110.{vlan}.0/29        | ALLES geblockt                |
| Online  | 120-199   | 10.110.{vlan}.0/29        | Internet ja, intern geblockt  |

## Auth-Flow

1. **Gerät verbindet sich** → AP sendet RADIUS-Request mit MAC + SSID
2. **SSID-Erkennung**: `Called-Station-Id` enthält SSID → Pool-Auswahl
   - SSID = `DaathNet-IoT-Offline` → Offline Pool (100-119)
   - Alle anderen SSIDs → Online Pool (120-199)
3. **Device Lookup**:
   - Bekannt → zugewiesenes VLAN zurückgeben
   - Unbekannt → nächstes freies VLAN aus Pool zuweisen, Device speichern
4. **Simultaneous-Use**: Max 1 Session pro MAC (30s Grace Period für Reconnects)

## SSIDs

| SSID                  | Auth           | Pool   |
|-----------------------|----------------|--------|
| DaathNet-Secure       | WPA3-Enterprise| Online |
| DaathNet-Guest-Secure | WPA3-Enterprise| Online |
| DaathNet-Guest        | Offen          | Online |
| DaathNet-IoT-Secure   | WPA3-Enterprise| Online |
| DaathNet-IoT-Premium  | WPA2-PSK       | Online |
| DaathNet-IoT-Offline  | WPA2-PSK       | Offline|
| DaathNet-IoT          | WPA2-PSK       | Online |

## Start / Stop

```bash
docker compose up -d        # Start
docker compose down          # Stop
docker compose logs -f freeradius  # Logs
```

## Management CLI

```bash
# Enterprise Users
./manage.sh add-user <username> <password> --mac <mac> [--device-name "..."]
./manage.sh remove-user <username>
./manage.sh list-users
./manage.sh change-password <username> <new-password>

# MAC Devices
./manage.sh add-device <mac> [--name "..."] [--pool offline|online]
./manage.sh remove-device <mac>
./manage.sh list-devices
./manage.sh move-device <mac> offline|online

# Pool & Logs
./manage.sh pool-status
./manage.sh auth-log [--last N]

# Testing
./manage.sh test andi-desktop <password>
./manage.sh test 30c9abb0956a              # MAC auth (password = MAC)
```

## Troubleshooting

```bash
# Debug-Modus
docker compose stop freeradius
docker compose run --rm --entrypoint bash freeradius -c \
  "ln -sf /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/sql && \
   ln -sf /etc/freeradius/3.0/mods-available/python3 /etc/freeradius/3.0/mods-enabled/python3 && \
   rm -f /etc/freeradius/3.0/mods-enabled/files && freeradius -X"

# DB prüfen
docker compose exec postgres psql -U radius -d radius -c "SELECT * FROM devices;"
docker compose exec postgres psql -U radius -d radius -c "SELECT * FROM vlan_pool WHERE in_use = true;"

# Aktive Sessions
docker compose exec postgres psql -U radius -d radius -c \
  "SELECT callingstationid, username, acctstarttime FROM radacct WHERE acctstoptime IS NULL;"

# Redis
docker compose exec redis redis-cli KEYS '*'

# Zertifikate
docker compose exec freeradius openssl x509 -in /certs/server.pem -noout -subject -dates

# CA-Cert exportieren (für Clients)
docker compose exec freeradius cat /certs/ca.pem
```

## Dateien

```
~/
├── docker-compose.yml
├── .env                              # Secrets
├── manage.sh                         # CLI Tool
├── freeradius/
│   ├── Dockerfile
│   ├── entrypoint.sh
│   ├── clients.conf                  # UniFi AP (template, envsubst at startup)
│   ├── mods-available/
│   │   ├── sql                       # PostgreSQL (template, envsubst at startup)
│   │   ├── eap                       # EAP-PEAP/MSCHAPv2
│   │   └── python3                   # Python3 module config
│   ├── mods-config/python3/
│   │   └── radius_auth.py            # Core logic (device lookup, VLAN, simul-use)
│   └── sites-available/
│       ├── default                   # Outer server
│       └── inner-tunnel              # PEAP inner tunnel
└── postgres/
    ├── 01-schema.sql                 # DB schema + VLAN pool
    └── 02-seed.sh                    # Initial devices (uses env vars for passwords)
```
