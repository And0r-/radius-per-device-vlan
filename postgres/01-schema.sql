-- FreeRADIUS Per-Device VLAN Schema
-- No secrets in this file — seed data is in 02-seed.sh

-- ============================================================
-- VLAN Pool (100 VLANs: 100-199)
-- ============================================================

CREATE TABLE vlan_pool (
    vlan_id      INT PRIMARY KEY,
    pool         VARCHAR(10) NOT NULL,  -- 'offline' or 'online'
    in_use       BOOLEAN NOT NULL DEFAULT false,
    assigned_mac VARCHAR(12),
    assigned_at  TIMESTAMP WITH TIME ZONE
);

CREATE INDEX idx_vlan_pool_free ON vlan_pool(pool, in_use) WHERE in_use = false;

-- ============================================================
-- Devices (per-device VLAN assignment)
-- ============================================================

CREATE TABLE devices (
    mac           VARCHAR(12) PRIMARY KEY,  -- lowercase, no separators
    username      VARCHAR(64),              -- nullable, for Enterprise auth
    password      VARCHAR(128),             -- for Enterprise auth
    vlan_id       INT REFERENCES vlan_pool(vlan_id),
    device_name   VARCHAR(200),
    ssid_category VARCHAR(20),             -- secure, guest, iot-offline, etc.
    created_at    TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_devices_username ON devices(username) WHERE username IS NOT NULL;
CREATE INDEX idx_devices_vlan ON devices(vlan_id);

-- ============================================================
-- Auth Log
-- ============================================================

CREATE TABLE auth_log (
    id        BIGSERIAL PRIMARY KEY,
    ts        TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    mac       VARCHAR(12),
    username  VARCHAR(64),
    result    VARCHAR(10) NOT NULL,  -- 'accept' or 'reject'
    vlan_id   INT,
    ssid      VARCHAR(64)
);

CREATE INDEX idx_auth_log_ts ON auth_log(ts DESC);
CREATE INDEX idx_auth_log_mac ON auth_log(mac);

-- ============================================================
-- Accounting (standard FreeRADIUS schema for session tracking)
-- ============================================================

CREATE TABLE radacct (
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

CREATE UNIQUE INDEX idx_radacct_uniqueid ON radacct(acctuniqueid);
CREATE INDEX idx_radacct_active ON radacct(acctstoptime, nasipaddress, acctstarttime);
CREATE INDEX idx_radacct_calling ON radacct(callingstationid);

-- Post-auth log (for SQL module compatibility)
CREATE TABLE radpostauth (
    id       BIGSERIAL PRIMARY KEY,
    username VARCHAR(253) NOT NULL DEFAULT '',
    pass     VARCHAR(128),
    reply    VARCHAR(32),
    authdate TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    class    VARCHAR(64)
);

-- Dummy tables required by SQL module queries.conf
CREATE TABLE radcheck (
    id SERIAL PRIMARY KEY, username VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '', op CHAR(2) NOT NULL DEFAULT ':=',
    value VARCHAR(253) NOT NULL DEFAULT ''
);
CREATE TABLE radreply (
    id SERIAL PRIMARY KEY, username VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '', op CHAR(2) NOT NULL DEFAULT '=',
    value VARCHAR(253) NOT NULL DEFAULT ''
);
CREATE TABLE radgroupcheck (
    id SERIAL PRIMARY KEY, groupname VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '', op CHAR(2) NOT NULL DEFAULT ':=',
    value VARCHAR(253) NOT NULL DEFAULT ''
);
CREATE TABLE radgroupreply (
    id SERIAL PRIMARY KEY, groupname VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '', op CHAR(2) NOT NULL DEFAULT '=',
    value VARCHAR(253) NOT NULL DEFAULT ''
);
CREATE TABLE radusergroup (
    id SERIAL PRIMARY KEY, username VARCHAR(64) NOT NULL DEFAULT '',
    groupname VARCHAR(64) NOT NULL DEFAULT '', priority INT NOT NULL DEFAULT 0
);
CREATE TABLE nas (
    id SERIAL PRIMARY KEY, nasname VARCHAR(128) NOT NULL,
    shortname VARCHAR(32), type VARCHAR(30) DEFAULT 'other', ports INT,
    secret VARCHAR(60) DEFAULT 'secret', server VARCHAR(64),
    community VARCHAR(50), description VARCHAR(200) DEFAULT 'RADIUS Client'
);

-- ============================================================
-- POPULATE VLAN POOL (100-199)
-- ============================================================

INSERT INTO vlan_pool (vlan_id, pool)
SELECT v, CASE WHEN v BETWEEN 100 AND 119 THEN 'offline' ELSE 'online' END
FROM generate_series(100, 199) AS v;
