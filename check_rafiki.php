<?php
/**
 * check_rafiki.php: verifies the Interledger connection end to end.
 *
 * Visit http://localhost/folkTeach/check_rafiki.php
 *
 * Checks config, signature generation, both Rafiki instances, and that the
 * configured wallet addresses actually exist. Add ?pay=1 to run a real
 * end-to-end payment of the configured test amount through the playground.
 *
 * DELETE THIS FILE before deploying anywhere public.
 */

require_once __DIR__ . '/rafiki_config.php';
require_once __DIR__ . '/lib_rafiki.php';

header('Content-Type: text/plain; charset=utf-8');

echo "UniPay Interledger / Rafiki diagnostics\n";
echo str_repeat('=', 72), "\n\n";

echo "CONFIG\n";
printf("  mode              : %s\n", RAFIKI_MODE);
printf("  sender host       : %s\n", RAFIKI_SENDER_HOST);
printf("  sender tenant     : %s\n", RAFIKI_SENDER_TENANT);
printf("  receiver host     : %s\n", RAFIKI_RECEIVER_HOST);
printf("  asset             : %s scale %d\n", RAFIKI_ASSET_CODE, RAFIKI_ASSET_SCALE);
printf("  union wallet      : %s\n", RAFIKI_UNION_WALLET_ADDRESS);
printf("  sender wallet     : %s\n", RAFIKI_DEFAULT_SENDER_WALLET_ADDRESS);
printf("  admin secret      : %s\n", RAFIKI_ADMIN_SECRET === ''
    ? 'NOT SET' : (strlen(RAFIKI_ADMIN_SECRET) . ' chars'
        . (RAFIKI_ADMIN_SECRET === 'iyIgCprjb9uL8wFckR+pLEkJWMB7FJhgkvqhTQR/964='
            ? '  <-- Local Playground default, PUBLIC. Fine on localhost only.' : '')));
echo "\n";

if (RAFIKI_MODE === 'stub') {
    echo "  NOTE: RAFIKI_MODE is 'stub'. Payments are fabricated and nothing\n";
    echo "        moves on the network. Set RAFIKI_MODE=live for real payments.\n\n";
}

// --- Signature self-test --------------------------------------------------
// Fees are AUD; Rafiki settles in RAFIKI_ASSET_CODE. A mismatch is handled by
// converting the fee, but it is worth stating plainly because it is the kind
// of thing that silently collects the wrong amount.
if (RAFIKI_ASSET_CODE !== 'AUD') {
    echo "SETTLEMENT ASSET\n";
    printf("  Fees are AUD but Rafiki settles in %s.\n", RAFIKI_ASSET_CODE);
    echo "  createPayment() converts the fee into the settlement asset first,\n";
    echo "  so an A\$45 fee does NOT collect 45 " . RAFIKI_ASSET_CODE . ".\n";
    echo "  For production, add an AUD asset to Rafiki and set RAFIKI_ASSET_CODE=AUD\n";
    echo "  so no conversion is needed at all.\n\n";
}

echo "SIGNATURE SELF-TEST\n";
$sample = ['query' => 'query Q { a }', 'variables' => ['b' => 2, 'a' => 1]];
$canon  = rafikiCanonicalize($sample);
$expect = '{"query":"query Q { a }","variables":{"a":1,"b":2}}';
printf("  canonical form    : %s\n", $canon);
printf("  %s keys sorted, slashes unescaped\n", $canon === $expect ? '[ OK ]' : '[FAIL]');

$urlCase = rafikiCanonicalize(['u' => 'https://a.example/b/c']);
printf("  %s URLs unescaped (%s)\n",
    $urlCase === '{"u":"https://a.example/b/c"}' ? '[ OK ]' : '[FAIL]', $urlCase);
echo "\n";

// --- Connectivity ---------------------------------------------------------
echo "CONNECTIVITY\n";

function probe(string $label, callable $fn): bool
{
    try {
        printf("  [ OK ] %-22s %s\n", $label, $fn());
        return true;
    } catch (Throwable $e) {
        printf("  [FAIL] %-22s %s\n", $label, $e->getMessage());
        return false;
    }
}

$senderWallets = [];
$okSender = probe('sender instance', function () use (&$senderWallets) {
    $senderWallets = rafikiListWalletAddresses(RAFIKI_SENDER_HOST, RAFIKI_SENDER_TENANT);
    return count($senderWallets) . ' wallet address(es)';
});

$receiverWallets = [];
probe('receiver instance', function () use (&$receiverWallets) {
    $receiverWallets = rafikiListWalletAddresses(RAFIKI_RECEIVER_HOST, RAFIKI_RECEIVER_TENANT);
    return count($receiverWallets) . ' wallet address(es)';
});
echo "\n";

if (!$okSender) {
    echo "Cannot continue without the sender instance.\n\n";
    echo "Common causes:\n";
    echo "  - The Local Playground is not running. From the rafiki checkout:\n";
    echo "      pnpm localenv:compose up\n";
    echo "  - A 401 means the signature was rejected: check RAFIKI_ADMIN_SECRET,\n";
    echo "    the tenant id, and that this machine's clock is correct (the\n";
    echo "    signature carries a timestamp Rafiki will reject if it has drifted).\n";
    exit(1);
}

// --- Wallets --------------------------------------------------------------
echo "WALLET ADDRESSES ON SENDER INSTANCE\n";
foreach ($senderWallets as $w) {
    printf("  %-28s %s  [%s scale %s]\n",
        $w['publicName'] ?? '(no name)', $w['address'] ?? '?',
        $w['asset']['code'] ?? '?', $w['asset']['scale'] ?? '?');
}
echo "\n";

if ($receiverWallets) {
    echo "WALLET ADDRESSES ON RECEIVER INSTANCE\n";
    foreach ($receiverWallets as $w) {
        printf("  %-28s %s  [%s scale %s]\n",
            $w['publicName'] ?? '(no name)', $w['address'] ?? '?',
            $w['asset']['code'] ?? '?', $w['asset']['scale'] ?? '?');
    }
    echo "\n";
}

echo "CONFIGURED WALLETS RESOLVE?\n";
$sender = rafikiFindWalletByAddress(RAFIKI_SENDER_HOST, RAFIKI_SENDER_TENANT, RAFIKI_DEFAULT_SENDER_WALLET_ADDRESS);
if ($sender) {
    printf("  [ OK ] sender resolves to id %s\n", $sender['id']);
    if (($sender['asset']['code'] ?? '') !== RAFIKI_ASSET_CODE) {
        printf("  [WARN] sender wallet asset is %s but RAFIKI_ASSET_CODE is %s.\n",
            $sender['asset']['code'] ?? '?', RAFIKI_ASSET_CODE);
        echo "         Quotes will fail unless the asset exists on this instance.\n";
    }
} else {
    echo "  [FAIL] RAFIKI_DEFAULT_SENDER_WALLET_ADDRESS not found on the sender instance.\n";
    echo "         Pick one of the addresses listed above.\n";
}

$union = null;
foreach ($receiverWallets as $w) {
    if (rtrim((string) $w['address'], '/') === rtrim(RAFIKI_UNION_WALLET_ADDRESS, '/')) {
        $union = $w;
    }
}
printf("  %s union wallet %s\n",
    $union ? '[ OK ]' : '[WARN]',
    $union ? "found ({$union['publicName']})" : 'not found on the receiver instance (may still be reachable remotely)');
echo "\n";

// --- Optional live payment ------------------------------------------------
if (($_GET['pay'] ?? '') === '1' && $sender) {
    $amount = (float) ($_GET['amount'] ?? 1.00);
    echo "LIVE TEST PAYMENT of $amount " . RAFIKI_ASSET_CODE . "\n";
    echo str_repeat('-', 72), "\n";
    try {
        $r = rafikiPay($sender['id'], RAFIKI_UNION_WALLET_ADDRESS, $amount,
            'UniPay diagnostic test payment', 'DIAG-' . date('YmdHis'));

        printf("  1. receiver   %s\n", $r['receiver']['id']);
        printf("     requested  %s\n", formatIlpAmount($r['receiver']['incomingAmount'] ?? null));
        printf("  2. quote      %s\n", $r['quote']['id']);
        printf("     debit      %s\n", formatIlpAmount($r['quote']['debitAmount'] ?? null));
        printf("     receive    %s\n", formatIlpAmount($r['quote']['receiveAmount'] ?? null));
        printf("  3. payment    %s\n", $r['payment']['id']);
        printf("     state      %s\n", $r['state']);
        printf("     sent       %s\n", formatIlpAmount($r['sentAmount'] ?? null));
        echo "\n  ", $r['succeeded']
            ? 'PAYMENT COMPLETED. The Interledger integration works end to end.'
            : "State is {$r['state']}: still settling, or check the Rafiki logs.", "\n";
    } catch (Throwable $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
    }
    echo "\n";
} elseif ($sender) {
    echo "To run a real end-to-end payment through the playground:\n";
    echo "  check_rafiki.php?pay=1&amount=1.00\n\n";
}

echo str_repeat('=', 72), "\n";
echo "Delete this file before deploying publicly.\n";
