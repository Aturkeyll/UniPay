<?php
/**
 * rafiki_config.php: Interledger / Rafiki connection settings.
 *
 * Defaults match the Rafiki Local Playground, so with the playground running
 * these values work unchanged. Every setting can be overridden by an
 * environment variable of the same name, which is how you point at a real
 * instance without editing this file.
 *
 * SECURITY: RAFIKI_ADMIN_SECRET grants full control of the Rafiki instance.
 * The default below is the Local Playground's published test secret and is
 * therefore public knowledge. Never reuse it outside localhost, and set the
 * real one via the environment rather than committing it.
 */

function rafikiEnv(string $name, string $default): string
{
    $v = getenv($name);
    return ($v === false || $v === '') ? $default : $v;
}

// --- Master switch --------------------------------------------------------
// 'live'  : talk to Rafiki for real.
// 'stub'  : keep the old fabricated payments (useful if the playground is down
//           mid-demo, but nothing actually moves).
define('RAFIKI_MODE', rafikiEnv('RAFIKI_MODE', 'live'));

// --- Sender side (UniPay operates this wallet) ---------------------------
define('RAFIKI_SENDER_HOST',   rafikiEnv('RAFIKI_SENDER_HOST',   'http://localhost:3001'));
define('RAFIKI_SENDER_TENANT', rafikiEnv('RAFIKI_SENDER_TENANT', '438fa74a-fa7d-4317-9ced-dde32ece1787'));

// --- Receiver side (the student union's bank) ----------------------------
define('RAFIKI_RECEIVER_HOST',   rafikiEnv('RAFIKI_RECEIVER_HOST',   'http://localhost:4001'));
define('RAFIKI_RECEIVER_TENANT', rafikiEnv('RAFIKI_RECEIVER_TENANT', 'cf5fd7d3-1eb1-4041-8e43-ba45747e9e5d'));

// --- Admin API signing ----------------------------------------------------
// Local Playground default. PUBLIC TEST VALUE, replace for anything real.
define('RAFIKI_ADMIN_SECRET',      rafikiEnv('RAFIKI_ADMIN_SECRET', 'iyIgCprjb9uL8wFckR+pLEkJWMB7FJhgkvqhTQR/964='));
define('RAFIKI_SIGNATURE_VERSION', (int) rafikiEnv('RAFIKI_SIGNATURE_VERSION', '1'));

// --- Settlement asset -----------------------------------------------------
// The asset the union settles in. The Local Playground seeds USD accounts, so
// the default is USD; switch to AUD once your Rafiki instance has an AUD asset
// and AUD wallet addresses, or quotes will fail with an unknown asset.
define('RAFIKI_ASSET_CODE',  rafikiEnv('RAFIKI_ASSET_CODE', 'USD'));
define('RAFIKI_ASSET_SCALE', (int) rafikiEnv('RAFIKI_ASSET_SCALE', '2'));

// --- Wallet addresses -----------------------------------------------------
// The union's receiving wallet address (public URL form).
define('RAFIKI_UNION_WALLET_ADDRESS',
    rafikiEnv('RAFIKI_UNION_WALLET_ADDRESS', 'https://happy-life-bank-backend/accounts/asmith'));

// The wallet UniPay debits when a student pays. In the playground this stands
// in for the student's own wallet. With real third-party wallets this is
// replaced by the student's wallet plus an interactive GNAP grant: see
// "Moving to true Open Payments" in the README.
define('RAFIKI_DEFAULT_SENDER_WALLET_ADDRESS',
    rafikiEnv('RAFIKI_DEFAULT_SENDER_WALLET_ADDRESS', 'https://cloud-nine-wallet-backend/accounts/gfranklin'));

// --- Webhooks -------------------------------------------------------------
// Shared secret for verifying inbound webhooks in rafiki_webhook.php. Leave
// empty to skip verification (localhost demos only).
define('RAFIKI_WEBHOOK_SECRET', rafikiEnv('RAFIKI_WEBHOOK_SECRET', ''));

// --- Misc -----------------------------------------------------------------
define('RAFIKI_TIMEOUT', (int) rafikiEnv('RAFIKI_TIMEOUT', '15'));
