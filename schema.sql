-- WSU x Interledger Hackathon: Student Payments Platform
-- Run this once to set up the database.

CREATE DATABASE IF NOT EXISTS wsu_payments CHARACTER SET utf8mb4;
USE wsu_payments;

-- Students, loaded from the roster JSON/CSV (validated list)
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_number VARCHAR(7) UNIQUE NOT NULL,   -- e.g. 7718607
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    wallet_address VARCHAR(255) NULL,   -- their own Open Payments wallet, when known
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- External payees (non-students: parents, alumni, external event guests etc.)
CREATE TABLE payees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(200) NOT NULL,
    email VARCHAR(255) NOT NULL,
    notes VARCHAR(255) NULL,
    wallet_address VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Catalog of chargeable items (fee types)
CREATE TABLE items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,                  -- e.g. 'Student Union Fees'
    default_amount DECIMAL(10,2) NULL,
    active TINYINT(1) DEFAULT 1
);

INSERT INTO items (name, default_amount) VALUES
('Student Union Fees', 45.00),
('Trips & Events', NULL),
('Overdue Books', NULL),
('Club Fees', 25.00),
('Admin Fees (Club Owners)', 50.00),
('Misc Dues', NULL);

-- Staff accounts who can generate payment links
CREATE TABLE staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Staff-generated payment links/requests
CREATE TABLE payment_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) UNIQUE NOT NULL,
    staff_id INT NULL REFERENCES staff(id),
    -- payer can be a student OR an external payee
    student_id INT NULL REFERENCES students(id),
    payee_id INT NULL REFERENCES payees(id),
    item_id INT NOT NULL REFERENCES items(id),
    amount DECIMAL(10,2) NOT NULL,
    -- lock flags: if 1, the field is pre-filled and read-only on the checkout page
    lock_payer TINYINT(1) DEFAULT 1,
    lock_item TINYINT(1) DEFAULT 1,
    lock_amount TINYINT(1) DEFAULT 1,
    due_date DATE NULL,
    status ENUM('pending','paid','overdue','cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL
);

-- Every payment attempt/completion, whether from a link or manual/self-service
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_link_id INT NULL REFERENCES payment_links(id), -- NULL if manual/unmatched
    student_id INT NULL REFERENCES students(id),
    payee_id INT NULL REFERENCES payees(id),
    item_id INT NULL REFERENCES items(id),       -- NULL until staff reconcile it
    reference_note VARCHAR(255) NULL,            -- student-entered reference for manual payments

    -- 12dp because crypto amounts are tiny: a $45 fee is ~0.00047 BTC, which
    -- DECIMAL(14,2) would store as 0.00.
    amount_source DECIMAL(30,12) NOT NULL,       -- amount actually paid, in source currency
    currency_source VARCHAR(20) NOT NULL,        -- e.g. 'USD', 'AUD', 'USDC', 'XAU'
    amount_dest DECIMAL(14,2) NULL,              -- converted amount received (AUD settlement)
    currency_dest VARCHAR(20) NULL,

    -- The rate this payment was struck at. Required to reconcile a crypto
    -- amount back to the AUD fee it settled.
    fx_rate DECIMAL(30,12) NULL,                 -- 1 AUD = fx_rate * currency_source
    rate_source VARCHAR(16) NULL,                -- 'ecb' (live) | 'manual' (crypto_rates.php)
    rate_as_of VARCHAR(40) NULL,                 -- ECB fix date, or crypto_rates.php as_of

    ilp_payment_pointer VARCHAR(255) NULL,
    ilp_quote_id VARCHAR(255) NULL,
    ilp_outgoing_payment_id VARCHAR(255) NULL,  -- Rafiki outgoing payment; webhook join key
    ilp_state VARCHAR(24) NULL,                 -- FUNDING/SENDING/COMPLETED/FAILED/UNKNOWN
    ilp_incoming_payment_id VARCHAR(255) NULL,
    status ENUM('pending','completed','failed','needs_reconciliation') DEFAULT 'pending',
    reconciled_by INT NULL REFERENCES staff(id),
    reconciled_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- rafiki_webhook.php looks up by this column on every inbound event.
    INDEX idx_ilp_outgoing (ilp_outgoing_payment_id)
);

-- Raw Rafiki webhook events. Rafiki retries on non-2xx, so an event can arrive
-- more than once; keeping them gives an audit trail and exposes duplicates.
CREATE TABLE ilp_webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(64) NULL,
    event_type VARCHAR(64) NOT NULL,
    resource_id VARCHAR(255) NULL,
    payload TEXT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type_time (event_type, received_at),
    INDEX idx_resource (resource_id)
);

-- Failed/successful student lookups, backing the rate limiter in
-- lib_student_auth.php. Student numbers are 7 sequential digits, so without
-- throttling the ID space can be walked to harvest names and payment data.
CREATE TABLE lookup_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    student_number VARCHAR(20) NULL,   -- NULL for staff login attempts
    success TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip, created_at),
    INDEX idx_number_time (student_number, created_at)
);

-- One-time sign-in links for students. Only the SHA-256 hash is stored, so a
-- database read yields no usable links.
CREATE TABLE student_login_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL REFERENCES students(id),
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    ip VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token_hash (token_hash),
    INDEX idx_student_active (student_id, used_at, expires_at)
);

-- Every quote shown to a student, including ones never paid.
CREATE TABLE quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id VARCHAR(64) NOT NULL,
    payment_link_id INT NULL REFERENCES payment_links(id),
    source_currency VARCHAR(20) NOT NULL,
    source_amount DECIMAL(14,2) NOT NULL,
    target_currency VARCHAR(20) NOT NULL,
    target_amount DECIMAL(30,12) NOT NULL,
    rate DECIMAL(30,12) NULL,
    rate_source VARCHAR(16) NULL,
    rate_as_of VARCHAR(40) NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    transaction_id INT NULL REFERENCES transactions(id),
    ip VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quote_id (quote_id),
    INDEX idx_link_time (payment_link_id, created_at)
);

CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_type ENUM('staff','student','payee','system') NOT NULL,
    actor_id INT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50) NULL,
    target_id INT NULL,
    details TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
