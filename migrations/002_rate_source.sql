-- Migration 002: record which source a rate came from
-- Run after 001:
--   mysql -u root -p wsu_payments < migrations/002_rate_source.sql
-- (schema.sql already includes this for a fresh install.)

USE wsu_payments;

-- Rates now come from two places with very different reliability: live ECB
-- fiat via Frankfurter, and hand-maintained crypto prices from crypto_rates.php.
-- Reconciliation needs to know which one priced a given payment: a disputed
-- crypto amount is a different conversation from a disputed EUR one.
ALTER TABLE transactions
    ADD COLUMN rate_source VARCHAR(16) NULL
        COMMENT "'ecb' = live Frankfurter/ECB fix, 'manual' = hand-set in crypto_rates.php"
        AFTER fx_rate;

-- Existing rows predate the split; leave them NULL rather than guessing.

-- Handy for spotting payments taken on a hand-set price:
--   SELECT id, currency_source, amount_source, fx_rate, rate_as_of, created_at
--   FROM transactions WHERE rate_source = 'manual' ORDER BY created_at DESC;
