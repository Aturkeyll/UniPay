-- Migration 004: real Interledger payments via Rafiki
-- Run after 003, or just visit repair_db.php which applies it automatically.

USE wsu_payments;

-- Rafiki's outgoing payment id. This is the join key the webhook uses to
-- resolve a payment that was still settling when checkout finished, so it
-- needs an index: the webhook looks up by this column on every event.
ALTER TABLE transactions
    ADD COLUMN ilp_outgoing_payment_id VARCHAR(255) NULL AFTER ilp_quote_id;

-- Rafiki payment state: FUNDING, SENDING, COMPLETED, FAILED, or UNKNOWN when
-- our poll budget expired before it settled. Distinct from `status`, which is
-- UniPay's own view of the transaction.
ALTER TABLE transactions
    ADD COLUMN ilp_state VARCHAR(24) NULL AFTER ilp_outgoing_payment_id;

CREATE INDEX idx_ilp_outgoing ON transactions (ilp_outgoing_payment_id);

-- Wallet addresses, so a student can be paid FROM their own wallet once you
-- move to true third-party Open Payments rather than the playground stand-in.
ALTER TABLE students ADD COLUMN wallet_address VARCHAR(255) NULL;
ALTER TABLE payees   ADD COLUMN wallet_address VARCHAR(255) NULL;

-- Raw webhook events. Rafiki retries on non-2xx, so events can arrive more
-- than once; storing them gives you an audit trail and a way to spot
-- duplicates during reconciliation.
CREATE TABLE IF NOT EXISTS ilp_webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(64) NULL,
    event_type VARCHAR(64) NOT NULL,
    resource_id VARCHAR(255) NULL,
    payload TEXT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type_time (event_type, received_at),
    INDEX idx_resource (resource_id)
);

-- Payments still in flight, for the reconciliation dashboard:
--   SELECT id, ilp_state, ilp_outgoing_payment_id, created_at
--   FROM transactions WHERE status = 'pending' AND ilp_outgoing_payment_id IS NOT NULL;
