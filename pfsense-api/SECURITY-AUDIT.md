# Security Audit Status — pfSense VLAN API

Audit: 2026-03-22 by Eshu (Automated Security Review)

## Fixed

- [x] **MEDIUM-01** File locking (`flock`) on all mutation endpoints
- [x] **MEDIUM-02** Strict comparisons for VLAN tag matching
- [x] **MEDIUM-03** Name input validation (max 64 chars, alphanumeric only)
- [x] **MEDIUM-04** Startup refuses default/weak API key (< 32 chars)
- [x] **LOW-01** API key no longer shown in start.sh log (only length)
- [x] **LOW-02** All requests logged to syslog
- [x] **LOW-03** SQLite integrity check on DB open
- [x] **HIGH-03** Rate limiting for auth failures (10/min, tracked in SQLite)

## Deferred (before production)

- [ ] **CRITICAL-01** DB backup strategy + admin docs for recovery (NOT auto-import of orphans)
- [ ] **CRITICAL-02** TLS (stunnel or nginx reverse proxy)
- [ ] **HIGH-01** Default binding to 127.0.0.1 (currently 0.0.0.0 for dev)
- [ ] **HIGH-02** Replace PHP built-in server (lighttpd/nginx + php-fpm)

## Design Decisions

- **CRITICAL-01 recovery**: API will NOT auto-adopt or auto-cleanup orphaned config.
  If DB is lost, admin must manually restore DB backup or clean up pfSense config.
  Reason: auto-cleanup could destroy manually created VLANs (e.g., VLAN 200).
- **Firewall rules**: Rule logic will be validated on production pfSense with real
  traffic before adjusting the script. Current rules are a starting point.
