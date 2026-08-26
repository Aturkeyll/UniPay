<?php
/**
 * lib_rates.php — live currency rates for UniPay via CurrencyFreaks.
 *
 * Replaces the hardcoded SUPPORTED_CURRENCIES table in lib_openpayments.php.
 * Covers all ~1,025 CurrencyFreaks currencies: fiat, metals and crypto.
 *
 * NO FALLBACK. If rates are unavailable or too old, getRates() throws and no
 * quote is issued. A refused quote is correct; an invented rate is not.
 *
 * FREE PLAN CONSTRAINTS this file is built around:
 *   - `base` is a paid feature, so every response is USD-based. We derive
 *     AUD rates ourselves: AUD -> X  ==  rates[X] / rates[AUD].
 *   - /convert/latest and /rates/historical are paid. Not used.
 *   - 1,000 calls/month, no hourly throttle. One call returns every currency,
 *     so an hourly cron costs ~730/month.
 *
 * ARCHITECTURE:
 *   - refreshRates()  writes the cache. Called by cron ONLY.
 *   - getRates()      reads the cache. Never makes a network call.
 *   This keeps CurrencyFreaks entirely off the student's checkout path.
 *
 * Cron (hourly):
 *   0 * * * * /usr/bin/php /path/to/lib_rates.php >> /var/log/unipay-rates.log 2>&1
 */

const CF_RATES_URL       = 'https://api.currencyfreaks.com/v2.0/rates/latest';
const CF_CURRENCIES_URL  = 'https://api.currencyfreaks.com/v2.0/supported-currencies';

const BASE_CURRENCY      = 'AUD';
const RATES_CACHE_FILE   = __DIR__ . '/cache/rates.json';
const SYMBOLS_CACHE_FILE = __DIR__ . '/cache/currencies.json';

// Refuse to quote on rates older than this. Cron runs hourly, so 2h tolerates
// one missed run and then stops trading. Tighten this for real money.
const RATES_MAX_AGE      = 7200;
// Currency metadata barely changes; refresh daily.
const SYMBOLS_MAX_AGE    = 86400;

// Decimal places kept on a converted amount. 12 is enough for BTC-scale assets
// without silently rounding a small fee to zero.
const AMOUNT_PRECISION   = 12;


/** Thrown whenever a trustworthy rate cannot be produced. Never swallow this. */
class RatesUnavailableException extends RuntimeException {}


function cfApiKey(): string
{
    $key = getenv('CURRENCYFREAKS_API_KEY') ?: '';
    if ($key === '') {
        throw new RatesUnavailableException('CURRENCYFREAKS_API_KEY is not set.');
    }
    return $key;
}


/**
 * GET + decode JSON. Throws with the API's own message on 4xx so quota
 * exhaustion (429) and a dead key (401) are distinguishable in the logs.
 */
function cfGet(string $url, int $timeoutSeconds = 10): array
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
        throw new RatesUnavailableException("CurrencyFreaks transport error ($errNo).");
    }

    $decoded = json_decode((string) $body, true);

    if ($status !== 200) {
        $message = $decoded['error']['message'] ?? $decoded['message'] ?? 'no message';
        // 429 = monthly quota gone. 401 = bad/inactive key. 402 = paid feature.
        throw new RatesUnavailableException("CurrencyFreaks HTTP $status: $message");
    }

    if (!is_array($decoded)) {
        throw new RatesUnavailableException('CurrencyFreaks returned unparseable JSON.');
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


/**
 * Fetch every rate from CurrencyFreaks, rebase USD -> AUD, and cache.
 * Cron calls this. The web request path must not.
 */
function refreshRates(): array
{
    $data = cfGet(CF_RATES_URL . '?apikey=' . urlencode(cfApiKey()));

    $usdRates = $data['rates'] ?? [];
    if (!is_array($usdRates) || $usdRates === []) {
        throw new RatesUnavailableException('CurrencyFreaks returned no rates.');
    }

    // Free plan is always USD-based; assert it rather than assume it, so an
    // upgrade to a paid plan that starts honouring `base` can't skew the maths.
    $apiBase = strtoupper((string) ($data['base'] ?? ''));
    if ($apiBase !== 'USD') {
        throw new RatesUnavailableException("Expected a USD base, got '$apiBase'.");
    }

    // rates[X] means "1 USD = X". So 1 AUD = rates[X] / rates[AUD] of X.
    $usdToBase = (float) ($usdRates[BASE_CURRENCY] ?? 0);
    if ($usdToBase <= 0) {
        throw new RatesUnavailableException(BASE_CURRENCY . ' missing from CurrencyFreaks response.');
    }

    $rates = [];
    foreach ($usdRates as $code => $rate) {
        $rate = (float) $rate;            // API sends rates as strings
        if ($rate > 0 && is_finite($rate)) {
            $rates[strtoupper($code)] = $rate / $usdToBase;
        }
    }
    $rates[BASE_CURRENCY] = 1.0;

    $payload = [
        'base'       => BASE_CURRENCY,
        'rates'      => $rates,
        'as_of'      => $data['date'] ?? null,   // CurrencyFreaks' own timestamp
        'fetched_at' => time(),
    ];

    writeCache(RATES_CACHE_FILE, $payload);
    return $payload;
}


/**
 * The only rate accessor the app should use. Reads cache, never the network.
 *
 * @throws RatesUnavailableException if the cache is missing or stale.
 * @return array{base:string, rates:array<string,float>, as_of:?string, fetched_at:int}
 */
function getRates(): array
{
    $cached = readCache(RATES_CACHE_FILE);

    if (!$cached || empty($cached['rates']) || empty($cached['fetched_at'])) {
        throw new RatesUnavailableException('No rate data available.');
    }

    $age = time() - (int) $cached['fetched_at'];
    if ($age > RATES_MAX_AGE) {
        throw new RatesUnavailableException(
            'Rate data is ' . $age . 's old (max ' . RATES_MAX_AGE . 's). Refusing to quote.'
        );
    }

    return $cached;
}


/**
 * Convert an AUD amount. Returns the rate alongside the amount so the caller
 * can persist exactly what was struck.
 *
 * @return array{amount:float, rate:float, as_of:?string}
 */
function convertFromBase(float $amountBase, string $targetCurrency): array
{
    $feed = getRates();
    $code = strtoupper($targetCurrency);

    if (empty($feed['rates'][$code])) {
        throw new RatesUnavailableException("No rate available for $code.");
    }

    $rate = (float) $feed['rates'][$code];

    return [
        'amount' => round($amountBase * $rate, AMOUNT_PRECISION),
        'rate'   => $rate,
        'as_of'  => $feed['as_of'],
    ];
}


/**
 * Fetch currency metadata (names + fiat/crypto/metal) and cache it.
 * /supported-currencies needs no API key, so this costs no quota — but it is
 * still a network call, so like refreshRates() it belongs to cron only.
 */
function refreshCurrencies(): array
{
    $data = cfGet(CF_CURRENCIES_URL);
    $map  = $data['supportedCurrenciesMap'] ?? [];

    if (!is_array($map) || $map === []) {
        throw new RatesUnavailableException('CurrencyFreaks returned no currency metadata.');
    }

    $currencies = [];
    foreach ($map as $code => $meta) {
        if (($meta['status'] ?? '') !== 'AVAILABLE') {
            continue;   // skips DEPRECIATED entries like GBX
        }
        $country = $meta['countryCode'] ?? '';
        $currencies[strtoupper($code)] = [
            'name' => $meta['currencyName'] ?? $code,
            'type' => $country === 'Crypto' ? 'crypto'
                    : ($country === 'Metal' ? 'metal' : 'fiat'),
        ];
    }

    $payload = ['currencies' => $currencies, 'fetched_at' => time()];
    writeCache(SYMBOLS_CACHE_FILE, $payload);
    return $payload;
}


/**
 * Currency metadata for the picker: code => ['name' => ..., 'type' => ...].
 * Reads cache only — pay.php renders the picker on every page load and must
 * never trigger an outbound call to do it.
 *
 * Only currencies that are AVAILABLE *and* present in the rate feed are
 * returned: a currency we hold no rate for is not payable, so don't offer it.
 */
function getSupportedCurrencies(): array
{
    $cached = readCache(SYMBOLS_CACHE_FILE);

    if (!$cached || empty($cached['currencies']) || empty($cached['fetched_at'])) {
        throw new RatesUnavailableException('No currency metadata available.');
    }

    if ((time() - (int) $cached['fetched_at']) > SYMBOLS_MAX_AGE) {
        throw new RatesUnavailableException('Currency metadata is stale.');
    }

    $rates     = getRates()['rates'];
    $available = array_intersect_key($cached['currencies'], $rates);
    uasort($available, fn($a, $b) => strcmp($a['name'], $b['name']));

    return $available;
}


// --- CLI entry point for the cron job -------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    try {
        $rates = refreshRates();

        // Metadata changes rarely and costs no quota; refresh it only when the
        // cache is nearly expired so an outage on this endpoint can't fail the
        // hourly run that actually matters.
        $meta    = readCache(SYMBOLS_CACHE_FILE);
        $metaAge = $meta ? time() - (int) ($meta['fetched_at'] ?? 0) : PHP_INT_MAX;
        if ($metaAge > SYMBOLS_MAX_AGE / 2) {
            refreshCurrencies();
        }

        fwrite(STDOUT, sprintf(
            "[%s] OK — %d rates, as_of %s\n",
            date('c'),
            count($rates['rates']),
            $rates['as_of'] ?? 'unknown'
        ));
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("[%s] FAIL — %s\n", date('c'), $e->getMessage()));
        exit(1);
    }
}
