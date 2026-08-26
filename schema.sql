-- WSU x Interledger Hackathon: Student Payments Platform
-- Run this once to set up the database.

CREATE DATABASE IF NOT EXISTS wsu_payments CHARACTER SET utf8mb4;
USE wsu_payments;

-- Students, loaded from the roster JSON/CSV (validated list)
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_number VARCHAR(7) UNIQUE NOT NULL, -- e.g. 7718607
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- External payees (non-students: parents, alumni, external event guests etc.)
CREATE TABLE payees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(200) NOT NULL,
    email VARCHAR(255) NOT NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Catalog of chargeable items (fee types)
CREATE TABLE items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,        -- e.g. 'Student Union Fees'
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

    item_id INT NULL REFERENCES items(id), -- NULL until staff reconcile it
    reference_note VARCHAR(255) NULL,      -- student-entered reference for manual payments

    amount_source DECIMAL(14,2) NOT NULL,   -- amount actually paid, in source currency
    currency_source VARCHAR(10) NOT NULL,   -- e.g. 'USD', 'AUD', 'USDC'
    amount_dest DECIMAL(14,2) NULL,         -- converted amount received (fiat settlement asset)
    currency_dest VARCHAR(10) NULL,

    ilp_payment_pointer VARCHAR(255) NULL,
    ilp_quote_id VARCHAR(255) NULL,
    ilp_incoming_payment_id VARCHAR(255) NULL,

    status ENUM('pending','completed','failed','needs_reconciliation') DEFAULT 'pending',
    reconciled_by INT NULL REFERENCES staff(id),
    reconciled_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
