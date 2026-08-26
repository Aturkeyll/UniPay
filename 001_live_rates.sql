-- Migration 001: live exchange rates
-- Run once against an existing database:
--   mysql -u root -p wsu_payments < migrations/001_live_rates.sql
-- (schema.sql already includes these changes for a fresh install.)

USE wsu_payments;

-- amount_source holds the amount the PAYER sent, in their chosen currency.
-- DECIMAL(14,2) silently rounded crypto to zero: a $45 fee paid in BTC is
-- ~0.00047 BTC, which stored as 0.00. 12 decimal places covers satoshi-scale
-- assets with room to spare.
ALTER TABLE transactions
    MODIFY COLUMN amount_source DECIMAL(30,12) NOT NULL
        COMMENT 'amount actually paid, in source currency (12dp for crypto)';

-- amount_dest is what the union receives in AUD, so 2dp is correct there.

-- Persist the rate a payment was struck at. Without this, reconciling a
-- 0.00047 BTC transaction back to a $45 fee is guesswork.
ALTER TABLE transactions
    ADD COLUMN fx_rate DECIMAL(30,12) NULL
        COMMENT '1 AUD = fx_rate * currency_source, at time of payment'
        AFTER currency_dest,
    ADD COLUMN rate_as_of VARCHAR(40) NULL
        COMMENT 'CurrencyFreaks rate timestamp the quote was based on'
        AFTER fx_rate;

-- Currency codes: CurrencyFreaks ships codes longer than 10 chars for some
-- crypto tickers. VARCHAR(10) would truncate them into a different currency.
ALTER TABLE transactions
    MODIFY COLUMN currency_source VARCHAR(20) NOT NULL,
    MODIFY COLUMN currency_dest   VARCHAR(20) NULL;
