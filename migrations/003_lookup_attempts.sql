-- Migration 003: student lookup rate limiting
-- Run after 002:
--   mysql -u root -p wsu_payments < migrations/003_lookup_attempts.sql

USE wsu_payments;

-- Backs the rate limiter in lib_student_auth.php. Student numbers are 7
-- sequential digits, so without this an attacker can walk the ID space and
-- harvest names, emails and payment histories at a few hundred requests/minute.
CREATE TABLE IF NOT EXISTS lookup_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,                 -- 45 chars fits IPv6
    student_number VARCHAR(7) NULL,          -- what was attempted, not what exists
    success TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- The rate check filters on ip + created_at on every lookup; without this
    -- index it degrades into a full scan as the table grows.
    INDEX idx_ip_time (ip, created_at),
    INDEX idx_number_time (student_number, created_at)
);

-- Spot enumeration: one IP trying many different student numbers.
--   SELECT ip, COUNT(DISTINCT student_number) tried, COUNT(*) attempts
--   FROM lookup_attempts
--   WHERE success = 0 AND created_at > (NOW() - INTERVAL 1 DAY)
--   GROUP BY ip HAVING tried > 5 ORDER BY tried DESC;
