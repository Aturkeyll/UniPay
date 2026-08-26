<?php
/**
 * check_rates.php: diagnostic page for the rate layer.
 *
 * Visit http://localhost/UniPay/check_rates.php to see exactly why the
 * currency picker is unavailable.
 *
 * DELETE THIS FILE before putting UniPay anywhere public. It reveals file
 * paths and PHP configuration, which is fine on localhost and not fine on
 * a shared host.
 */

require_once __DIR__ . '/lib_rates.php';

header('Content-Type: text/plain; charset=utf-8');

function check(string $label, callable $fn): void
{
    try {
        $result = $fn();
        printf("[ OK ]   %-28s %s\n", $label, $result);
    } catch (Throwable $e) {
        printf("[FAIL]   %-28s %s\n", $label, $e->getMessage());
    }
}

echo "UniPay rate diagnostics\n";
echo str_repeat('=', 72), "\n\n";

echo "ENVIRONMENT\n";
printf("  PHP %s (%s)\n", PHP_VERSION, PHP_SAPI);
printf("  php.ini: %s\n", php_ini_loaded_file() ?: 'none loaded');
printf("  curl extension: %s\n", extension_loaded('curl') ? 'loaded' : 'MISSING (uncomment extension=curl)');
printf("  curl.cainfo: %s\n", ini_get('curl.cainfo') ?: 'NOT SET (HTTPS will fail on Windows)');
printf("  rate endpoint: %s\n", FRANKFURTER_LATEST_URL);
printf("  openssl.cafile: %s\n", ini_get('openssl.cafile') ?: 'not set');
echo "\n";

echo "CACHE DIRECTORY\n";
$cacheDir = dirname(RATES_CACHE_FILE);
printf("  path: %s\n", $cacheDir);
printf("  exists: %s\n", is_dir($cacheDir) ? 'yes' : 'NO');
printf("  writable: %s\n", is_writable($cacheDir) ? 'yes' : 'NO (the scheduled task cannot write here)');
foreach ([RATES_CACHE_FILE, SYMBOLS_CACHE_FILE] as $f) {
    printf("  %-22s %s\n", basename($f),
        is_readable($f)
            ? 'present, ' . (time() - filemtime($f)) . 's old'
            : 'MISSING (run: php lib_rates.php)');
}
echo "\n";

echo "CHECKS\n";

check('Frankfurter reachable', function () {
    $d = httpGetJson(FRANKFURTER_LATEST_URL . '?base=' . BASE_CURRENCY, 10);
    return 'ECB fix ' . ($d['date'] ?? '?') . ', ' . count($d['rates'] ?? []) . ' currencies';
});

check('Fiat cache readable', function () {
    $f = getRates();
    return count($f['rates']) . ' rates, ECB fix ' . ($f['ecb_date'] ?? '?')
         . ', fetched ' . (time() - $f['fetched_at']) . 's ago';
});

check('Manual crypto table', function () {
    $c = getManualCryptoRates();
    $age = (time() - strtotime($c['as_of'])) / 86400;
    return sprintf('%d coins, as of %s (%.1f days old)', count($c['rates']), $c['as_of'], $age);
});

check('Currency picker', function () {
    $list = getSupportedCurrencies();
    $fiat = count(array_filter($list, fn($m) => $m['type'] === 'fiat'));
    $cryp = count($list) - $fiat;
    return count($list) . " selectable ($fiat fiat, $cryp crypto)";
});

check('Sample quote A$45 -> USD', function () {
    $q = getQuoteSafe(45.00, 'USD');
    return $q;
});

check('Sample quote A$45 -> BTC', function () {
    $q = getQuoteSafe(45.00, 'BTC');
    return $q;
});

function getQuoteSafe(float $amount, string $code): string
{
    $c = convertFromBase($amount, $code);
    return sprintf('%s %s  (rate %.12f, %s, as of %s)',
        rtrim(rtrim(number_format($c['amount'], 12, '.', ''), '0'), '.'),
        $code, $c['rate'], $c['source'], $c['as_of']);
}

echo "\n";
echo str_repeat('=', 72), "\n";
echo "Any FAIL above is the reason the checkout shows 'unavailable'.\n";
echo "Delete this file before deploying anywhere public.\n";
