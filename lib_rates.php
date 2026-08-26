<?php
/**
 * lib_rates.php — currency rates for UniPay.
 *
 * Two sources, deliberately handled differently:
 *
 *   FIAT   — Frankfurter (https://frankfurter.dev), ECB reference rates.
 *            No API key, no signup, no quota. Native AUD base.
 *            Fetched by cron, cached, read by the web.
 *
 *   CRYPTO — crypto_rates.php, maintained by hand.
 *            Read straight from disk on every request, so editing that file
 *            takes effect immediately with no cron run.
 *
 * NO SILENT FALLBACK. Nothing here invents a rate. If the fiat cache is missing
 * or stale, or the manual crypto table has expired, the relevant call throws and
 * the checkout refuses to quote.
 *
 * Cron (hourly is plenty — ECB publishes once per working day):
 *   0 * * * * /usr/bin/php /path/to/lib_rates.php >> /var/log/unipay-rates.log 2>&1
 */

const FRANKFURTER_LATEST_URL     = 'https://api.frankfurter.app/latest';
const FRANKFURTER_CURRENCIES_URL = 'https://api.frankfurter.app/currencies';

const BASE_CURRENCY      = 'AUD';
const CRYPTO_CONFIG_FILE = __DIR__ . '/crypto_rates.php';
const RATES_CACHE_FILE   = __DIR__ . '/cache/rates.json';
const SYMBOLS_CACHE_FILE = __DIR__ . '/cache/currencies.json';

// Refuse to quote fiat if cron hasn't succeeded within this many seconds.
// Applies to OUR fetch time, not the ECB publication date — see below.
const RATES_MAX_AGE = 7200;

// The ECB publishes once per working day, so its date is legitimately 1-3 days
// old over weekends and holidays. This is the separate, much looser guard on
// the fix itself: 5 days covers a long weekend without going indefinitely stale.
const ECB_MAX_AGE_DAYS = 5;

// How old the hand-maintained crypto prices in crypto_rates.php may be before
// they are refused. Crypto moves fast; keep this tight. Set to null to disable
// the check entirely (not recommended outside a controlled demo).
const MANUAL_RATES_MAX_AGE_DAYS = 3;

const SYMBOLS_MAX_AGE  = 86400;   // fiat currency names; refreshed daily
const AMOUNT_PRECISION = 12;      // decimal places kept on a converted amount


/** Thrown whenever a trustworthy rate cannot be produced. Never swallow this. */
class RatesUnavailableException extends RuntimeException {}


/**
 * GET + decode JSON. Frankfurter needs no auth, so this stays minimal.
 */
function httpGetJson(string $url, int $timeoutSeconds = 10): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
        CURLOPT_USERAGENT      => 'UniPay/1.0',
    ]);
    $body   = curl_exec($ch);
    $errNo  = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo !== 0 || $body === false) {
        throw new RatesUnavailableException("Transport error ($errNo) for $url");
    }
    if ($status !== 200) {
        throw new RatesUnavailableException("HTTP $status from $url");
    }

    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        throw new RatesUnavailableException("Unparseable JSON from $url");
    }

    return $decoded;
}


function writeCache(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RatesUnavailableException("Cannot create cache dir: $dir");
    }
    // Write-then-rename so a reader never sees a half-written file.
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($payload)) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RatesUnavailableException("Cannot write cache: $path");
    }
}


function readCache(string $path): ?array
{
    if (!is_readable($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}


// ---------------------------------------------------------------------------
// FIAT — Frankfurter, fetched by cron
// ---------------------------------------------------------------------------

/**
 * Fetch ECB fiat rates with AUD as the native base, and cache them.
 * Cron calls this. The web request path must not.
 */
function refreshRates(): array
{
    $data = httpGetJson(FRANKFURTER_LATEST_URL . '?base=' . BASE_CURRENCY);

    if (empty($data['rates']) || !is_array($data['rates'])) {
        throw new RatesUnavailableException('Frankfurter returned no rates.');
    }
    if (strtoupper((string) ($data['base'] ?? '')) !== BASE_CURRENCY) {
        throw new RatesUnavailableException('Frankfurter did not honour the AUD base.');
    }

    $rates = [BASE_CURRENCY => 1.0];
    foreach ($data['rates'] as $code => $rate) {
        $rate = (float) $rate;
        if ($rate > 0 && is_finite($rate)) {
            $rates[strtoupper($code)] = $rate;
        }
    }

    $payload = [
        'base'       => BASE_CURRENCY,
        'rates'      => $rates,
        'ecb_date'   => $data['date'] ?? null,   // ECB publication date, e.g. 2026-08-25
        'fetched_at' => time(),
    ];

    writeCache(RATES_CACHE_FILE, $payload);
    return $payload;
}


/**
 * Fetch ECB currency names and cache them. Cron only.
 */
function refreshCurrencies(): array
{
    $names = httpGetJson(FRANKFURTER_CURRENCIES_URL);

    if ($names === []) {
        throw new RatesUnavailableException('Frankfurter returned no currency names.');
    }

    $currencies = [];
    foreach ($names as $code => $name) {
        $currencies[strtoupper($code)] = (string) $name;
    }

    $payload = ['currencies' => $currencies, 'fetched_at' => time()];
    writeCache(SYMBOLS_CACHE_FILE, $payload);
    return $payload;
}


// ---------------------------------------------------------------------------
// CRYPTO — crypto_rates.php, read from disk every request
// ---------------------------------------------------------------------------

/**
 * Load and validate the hand-maintained crypto table.
 *
 * Returns ['rates' => [CODE => aud_rate], 'names' => [...], 'as_of' => 'Y-m-d'].
 * The config stores the AUD price of one unit; this inverts it into the
 * AUD -> CODE direction the rest of the app multiplies by.
 *
 * @throws RatesUnavailableException on a malformed or expired table.
 */
function getManualCryptoRates(): array
{
    if (!is_readable(CRYPTO_CONFIG_FILE)) {
        throw new RatesUnavailableException('crypto_rates.php is missing or unreadable.');
    }

    $config = require CRYPTO_CONFIG_FILE;

    if (!is_array($config) || empty($config['rates']) || empty($config['as_of'])) {
        throw new RatesUnavailableException('crypto_rates.php is malformed.');
    }

    $asOf = $config['as_of'];
    $ts   = strtotime($asOf);
    if ($ts === false) {
        throw new RatesUnavailableException("crypto_rates.php has an unreadable as_of date: $asOf");
    }

    // Expire loudly rather than quoting a price nobody has checked in weeks.
    if (MANUAL_RATES_MAX_AGE_DAYS !== null) {
        $ageDays = (time() - $ts) / 86400;
        if ($ageDays > MANUAL_RATES_MAX_AGE_DAYS) {
            throw new RatesUnavailableException(sprintf(
                'Manual crypto rates are %.1f days old (max %d). Update crypto_rates.php.',
                $ageDays, MANUAL_RATES_MAX_AGE_DAYS
            ));
        }
    }

    $rates = [];
    $names = [];

    foreach ($config['rates'] as $code => $entry) {
        $code  = strtoupper((string) $code);
        $price = (float) ($entry['aud_price'] ?? 0);

        if ($price <= 0 || !is_finite($price)) {
            throw new RatesUnavailableException("crypto_rates.php: bad aud_price for $code.");
        }
        if ($code === BASE_CURRENCY) {
            throw new RatesUnavailableException('crypto_rates.php cannot redefine ' . BASE_CURRENCY . '.');
        }

        $rates[$code] = 1 / $price;                    // AUD -> coin
        $names[$code] = (string) ($entry['name'] ?? $code);
    }

    return ['rates' => $rates, 'names' => $names, 'as_of' => $asOf];
}


// ---------------------------------------------------------------------------
// Combined accessors — what the rest of the app calls
// ---------------------------------------------------------------------------

/**
 * The only rate accessor the app should use.
 * Fiat comes from cache (never the network); crypto from disk.
 *
 * @throws RatesUnavailableException if either side is unavailable or stale.
 * @return array{base:string, rates:array<string,float>, sources:array<string,string>,
 *               ecb_date:?string, crypto_as_of:string, fetched_at:int}
 */
function getRates(): array
{
    $cached = readCache(RATES_CACHE_FILE);

    if (!$cached || empty($cached['rates']) || empty($cached['fetched_at'])) {
        throw new RatesUnavailableException('No fiat rate data available. Has the cron run?');
    }

    // Guard 1: how long since OUR fetch succeeded.
    $age = time() - (int) $cached['fetched_at'];
    if ($age > RATES_MAX_AGE) {
        throw new RatesUnavailableException(
            "Fiat rates are {$age}s old (max " . RATES_MAX_AGE . 's). Refusing to quote.'
        );
    }

    // Guard 2: how old the ECB fix itself is. Separate and much looser, because
    // a Sunday quote legitimately uses Friday's published rate.
    if (!empty($cached['ecb_date'])) {
        $ecbTs = strtotime($cached['ecb_date']);
        if ($ecbTs !== false && (time() - $ecbTs) / 86400 > ECB_MAX_AGE_DAYS) {
            throw new RatesUnavailableException(
                'ECB fix is from ' . $cached['ecb_date'] . ', older than ' . ECB_MAX_AGE_DAYS . ' days.'
            );
        }
    }

    $rates   = $cached['rates'];
    $sources = array_fill_keys(array_keys($rates), 'ecb');

    // Merge the manual table. A crypto code must never shadow a real currency.
    $crypto = getManualCryptoRates();
    foreach ($crypto['rates'] as $code => $rate) {
        if (isset($rates[$code])) {
            throw new RatesUnavailableException(
                "crypto_rates.php defines $code, which is already an ECB currency. Remove it."
            );
        }
        $rates[$code]   = $rate;
        $sources[$code] = 'manual';
    }

    return [
        'base'         => BASE_CURRENCY,
        'rates'        => $rates,
        'sources'      => $sources,
        'ecb_date'     => $cached['ecb_date'] ?? null,
        'crypto_as_of' => $crypto['as_of'],
        'fetched_at'   => (int) $cached['fetched_at'],
    ];
}


/**
 * Convert an AUD amount. Returns the rate and its provenance alongside the
 * amount so the caller can persist exactly what was struck, and so the
 * checkout can tell the student a crypto price is indicative.
 *
 * @return array{amount:float, rate:float, source:string, as_of:?string}
 */
function convertFromBase(float $amountBase, string $targetCurrency): array
{
    $feed = getRates();
    $code = strtoupper($targetCurrency);

    if (empty($feed['rates'][$code])) {
        throw new RatesUnavailableException("No rate available for $code.");
    }

    $rate   = (float) $feed['rates'][$code];
    $source = $feed['sources'][$code] ?? 'unknown';

    return [
        'amount' => round($amountBase * $rate, AMOUNT_PRECISION),
        'rate'   => $rate,
        'source' => $source,                                  // 'ecb' | 'manual'
        'as_of'  => $source === 'manual' ? $feed['crypto_as_of'] : $feed['ecb_date'],
    ];
}


/**
 * Currency metadata for the picker: code => ['name' => ..., 'type' => ...].
 * Reads cache and disk only — pay.php renders this on every page load and must
 * never trigger an outbound call to do it.
 */
function getSupportedCurrencies(): array
{
    $cached = readCache(SYMBOLS_CACHE_FILE);

    if (!$cached || empty($cached['currencies']) || empty($cached['fetched_at'])) {
        throw new RatesUnavailableException('No currency metadata available. Has the cron run?');
    }
    if ((time() - (int) $cached['fetched_at']) > SYMBOLS_MAX_AGE * 2) {
        throw new RatesUnavailableException('Currency metadata is stale.');
    }

    $feed   = getRates();
    $crypto = getManualCryptoRates();

    $available = [];
    foreach ($feed['rates'] as $code => $_) {
        if ($feed['sources'][$code] === 'manual') {
            $available[$code] = ['name' => $crypto['names'][$code] ?? $code, 'type' => 'crypto'];
        } else {
            // A currency we hold no name for is still payable; fall back to the code.
            $available[$code] = ['name' => $cached['currencies'][$code] ?? $code, 'type' => 'fiat'];
        }
    }

    uasort($available, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $available;
}


// --- CLI entry point for the cron job -------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $exit = 0;

    try {
        $rates = refreshRates();

        $meta    = readCache(SYMBOLS_CACHE_FILE);
        $metaAge = $meta ? time() - (int) ($meta['fetched_at'] ?? 0) : PHP_INT_MAX;
        if ($metaAge > SYMBOLS_MAX_AGE) {
            refreshCurrencies();
        }

        fwrite(STDOUT, sprintf(
            "[%s] OK — %d fiat rates, ECB fix %s\n",
            date('c'), count($rates['rates']), $rates['ecb_date'] ?? 'unknown'
        ));
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("[%s] FIAT FAIL — %s\n", date('c'), $e->getMessage()));
        $exit = 1;
    }

    // Check the manual table separately so the cron log warns you BEFORE the
    // rates expire and students start hitting a refusal at checkout.
    try {
        $crypto = getManualCryptoRates();
        $ageDays = (time() - strtotime($crypto['as_of'])) / 86400;
        $warn = (MANUAL_RATES_MAX_AGE_DAYS !== null && $ageDays > MANUAL_RATES_MAX_AGE_DAYS - 1)
            ? '  <-- EXPIRING SOON, update crypto_rates.php' : '';
        fwrite(STDOUT, sprintf(
            "[%s] OK — %d manual crypto rates, as of %s (%.1f days old)%s\n",
            date('c'), count($crypto['rates']), $crypto['as_of'], $ageDays, $warn
        ));
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("[%s] CRYPTO FAIL — %s\n", date('c'), $e->getMessage()));
        $exit = 1;
    }

    exit($exit);
}
