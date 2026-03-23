-- FreeRADIUS Per-Device VLAN Schema v2
-- Single table for all VLAN assignments (MAC + Enterprise)

-- ============================================================
-- VLAN Pool (dynamic, no fixed pool types)
-- ============================================================

CREATE TABLE IF NOT EXISTS vlan_pool (
    vlan_id      INT PRIMARY KEY,
    in_use       BOOLEAN NOT NULL DEFAULT false,
    vlan_type    CHAR(1),             -- i/o/g/e or NULL if free
    assigned_to  TEXT,                -- identity (MAC or username)
    assigned_ssid TEXT,               -- SSID at assignment time
    assigned_at  TIMESTAMP WITH TIME ZONE
);

CREATE INDEX IF NOT EXISTS idx_vlan_pool_free ON vlan_pool(in_use) WHERE in_use = false;

-- ============================================================
-- VLAN Assignments (one table for MAC-auth + Enterprise)
-- ============================================================

CREATE TABLE IF NOT EXISTS vlan_assignments (
    identity    TEXT NOT NULL,         -- MAC (aabbccddeeff) or username
    ssid        TEXT NOT NULL,         -- SSID name
    auth_type   TEXT NOT NULL,         -- 'mac' or 'enterprise'
    password    TEXT,                  -- only for enterprise, NULL for MAC
    vlan_id     INT REFERENCES vlan_pool(vlan_id),
    vlan_type   CHAR(1) NOT NULL,     -- i/o/g/e (derived from SSID)
    name        TEXT,                  -- device/user description
    created_at  TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    PRIMARY KEY (identity, ssid)
);

CREATE INDEX IF NOT EXISTS idx_va_vlan ON vlan_assignments(vlan_id);

-- ============================================================
-- Auth Log
-- ============================================================

CREATE TABLE IF NOT EXISTS auth_log (
    id        BIGSERIAL PRIMARY KEY,
    ts        TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    mac       VARCHAR(12),
    username  VARCHAR(64),
    ssid      VARCHAR(64),
    result    VARCHAR(10) NOT NULL,
    vlan_id   INT,
    vlan_type CHAR(1)
);

CREATE INDEX IF NOT EXISTS idx_auth_log_ts ON auth_log(ts DESC);
CREATE INDEX IF NOT EXISTS idx_auth_log_mac ON auth_log(mac);

-- ============================================================
-- Accounting (standard FreeRADIUS schema)
-- ============================================================

CREATE TABLE IF NOT EXISTS radacct (
    radacctid          BIGSERIAL PRIMARY KEY,
    acctsessionid      VARCHAR(64) NOT NULL DEFAULT '',
    acctuniqueid       VARCHAR(32) NOT NULL DEFAULT '',
    username           VARCHAR(253),
    groupname          VARCHAR(253),
    realm              VARCHAR(64),
    nasipaddress       VARCHAR(15) NOT NULL DEFAULT '',
    nasportid          VARCHAR(32),
    nasporttype        VARCHAR(32),
    acctstarttime      TIMESTAMP WITH TIME ZONE,
    acctupdatetime     TIMESTAMP WITH TIME ZONE,
    acctstoptime       TIMESTAMP WITH TIME ZONE,
    acctinterval       BIGINT,
    acctsessiontime    BIGINT,
    acctauthentic      VARCHAR(32),
    connectinfo_start  VARCHAR(128),
    connectinfo_stop   VARCHAR(128),
    acctinputoctets    BIGINT,
    acctoutputoctets   BIGINT,
    calledstationid    VARCHAR(50),
    callingstationid   VARCHAR(50),
    acctterminatecause VARCHAR(32),
    servicetype        VARCHAR(32),
    framedprotocol     VARCHAR(32),
    framedipaddress    VARCHAR(15),
    framedipv6address  VARCHAR(45),
    framedipv6prefix   VARCHAR(45),
    framedinterfaceid  VARCHAR(44),
    delegatedipv6prefix VARCHAR(45),
    class              VARCHAR(64)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_radacct_uniqueid ON radacct(acctuniqueid);
CREATE INDEX IF NOT EXISTS idx_radacct_active ON radacct(acctstoptime, nasipaddress, acctstarttime);
CREATE INDEX IF NOT EXISTS idx_radacct_calling ON radacct(callingstationid);

-- Post-auth log (SQL module compatibility)
CREATE TABLE IF NOT EXISTS radpostauth (
    id       BIGSERIAL PRIMARY KEY,
    username VARCHAR(253) NOT NULL DEFAULT '',
    pass     VARCHAR(128),
    reply    VARCHAR(32),
    authdate TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    class    VARCHAR(64)
);

-- Dummy tables for SQL module queries.conf compatibility
CREATE TABLE IF NOT EXISTS radcheck (
    id SERIAL PRIMARY KEY, username VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '', op CHAR(2) NOT NULL DEFAULT ':=',
    value VARCHAR(253) NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS radreply (
    id SERIAL PRIMARY KEY, username VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '', op CHAR(2) NOT NULL DEFAULT '=',
    value VARCHAR(253) NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS radgroupcheck (
    id SERIAL PRIMARY KEY, groupname VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '', op CHAR(2) NOT NULL DEFAULT ':=',
    value VARCHAR(253) NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS radgroupreply (
    id SERIAL PRIMARY KEY, groupname VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '', op CHAR(2) NOT NULL DEFAULT '=',
    value VARCHAR(253) NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS radusergroup (
    id SERIAL PRIMARY KEY, username VARCHAR(64) NOT NULL DEFAULT '',
    groupname VARCHAR(64) NOT NULL DEFAULT '', priority INT NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS nas (
    id SERIAL PRIMARY KEY, nasname VARCHAR(128) NOT NULL,
    shortname VARCHAR(32), type VARCHAR(30) DEFAULT 'other', ports INT,
    secret VARCHAR(60) DEFAULT 'secret', server VARCHAR(64),
    community VARCHAR(50), description VARCHAR(200) DEFAULT 'RADIUS Client'
);

-- ============================================================
-- POPULATE VLAN POOL (configurable range, default 100-199)
-- ============================================================

INSERT INTO vlan_pool (vlan_id)
SELECT v FROM generate_series(100, 199) AS v
ON CONFLICT DO NOTHING;
