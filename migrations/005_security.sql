-- Migration 005: authentication, CSRF and quote records
-- Applied automatically by repair_db.php.

USE wsu_payments;

-- One-time sign-in links. Replaces the student-number-plus-surname check,
-- which could not withstand anyone who knew a student's name.
--
-- Only the SHA-256 hash is stored, so a database read yields no usable links.
CREATE TABLE IF NOT EXISTS student_login_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL REFERENCES students(id),
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    ip VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Redemption looks up by hash on every click.
    UNIQUE KEY uq_token_hash (token_hash),
    INDEX idx_student_active (student_id, used_at, expires_at)
);

-- Quotes shown to students, including ones never paid. Recomputing at pay time
-- already prevented tampering, but without this there is no record of the
-- figure a student saw, which is what you need when they dispute it.
CREATE TABLE IF NOT EXISTS quotes (
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

-- lookup_attempts now also throttles staff logins, where student_number is
-- NULL. Widen it so a failed staff login cannot be mistaken for a student one.
ALTER TABLE lookup_attempts MODIFY COLUMN student_number VARCHAR(20) NULL;

-- Quotes shown but never paid, over the last day:
--   SELECT target_currency, COUNT(*) FROM quotes
--   WHERE used_at IS NULL AND created_at > (NOW() - INTERVAL 1 DAY)
--   GROUP BY target_currency;
