<?php
/**
 * check_payment.php: finds out why "Payment could not be completed" happens.
 *
 * Visit http://localhost/folkTeach/check_payment.php
 *
 * Replays the exact INSERT process_payment.php performs, inside a transaction
 * that is ALWAYS rolled back. Nothing is written to your database.
 *
 * DELETE THIS FILE before deploying anywhere public.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib_openpayments.php';

header('Content-Type: text/plain; charset=utf-8');

echo "UniPay payment diagnostics\n";
echo str_repeat('=', 72), "\n\n";

$pdo = getDb();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// --- 1. Schema ------------------------------------------------------------
echo "1. TRANSACTIONS SCHEMA\n";

$required = [
    'amount_source'   => 'decimal(30,12)',
    'currency_source' => 'varchar(20)',
    'amount_dest'     => 'decimal(14,2)',
    'currency_dest'   => 'varchar(20)',
    'fx_rate'         => 'decimal(30,12)',
    'rate_source'     => 'varchar(16)',
    'rate_as_of'      => 'varchar(40)',
];

$actual = [];
foreach ($pdo->query("SHOW COLUMNS FROM transactions") as $col) {
    $actual[$col['Field']] = strtolower($col['Type']);
}

$schemaOk = true;
foreach ($required as $name => $expectedType) {
    if (!isset($actual[$name])) {
        printf("   [MISSING] %-16s expected %s\n", $name, $expectedType);
        $schemaOk = false;
    } elseif ($actual[$name] !== $expectedType) {
        printf("   [ WRONG ] %-16s is %s, expected %s\n", $name, $actual[$name], $expectedType);
        $schemaOk = false;
    } else {
        printf("   [  OK  ] %-16s %s\n", $name, $actual[$name]);
    }
}

if (!$schemaOk) {
    echo "\n   >>> Run the migrations, in order:\n";
    echo "       migrations\\001_live_rates.sql\n";
    echo "       migrations\\002_rate_source.sql\n";
}
echo "\n";

// --- 2. A payable link ----------------------------------------------------
echo "2. PAYABLE LINKS\n";

$link = $pdo->query(
    "SELECT * FROM payment_links
     WHERE status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())
     ORDER BY id DESC LIMIT 1"
)->fetch();

if (!$link) {
    echo "   [FAIL] No pending, unexpired payment_links row exists.\n";
    echo "          Every link is already 'paid', cancelled, or expired.\n";
    echo "          Tokens are single-use; generate a fresh link in staff_generate.php.\n";
    $counts = $pdo->query("SELECT status, COUNT(*) c FROM payment_links GROUP BY status")->fetchAll();
    foreach ($counts as $row) printf("          status=%-10s %d\n", $row['status'], $row['c']);
    echo "\n   Cannot continue without a payable link.\n";
    exit;
}

printf("   [ OK ] link id=%d token=%s amount=%s\n", $link['id'], $link['token'], $link['amount']);
echo "\n";

// --- 3. Quote -------------------------------------------------------------
echo "3. QUOTE\n";

$testCurrency = $_GET['currency'] ?? 'USD';
try {
    $quote = getQuote((float) $link['amount'], $testCurrency);
    printf("   [ OK ] A$%s -> %s %s (rate %.12f, %s)\n",
        $link['amount'],
        rtrim(rtrim(number_format($quote['target_amount'], 12, '.', ''), '0'), '.'),
        $quote['target_currency'], $quote['rate'], $quote['rate_source']);
} catch (Throwable $e) {
    echo "   [FAIL] " . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit;
}
echo "\n";

// --- 4. The INSERT, rolled back ------------------------------------------
echo "4. TRANSACTION INSERT (rolled back, nothing is saved)\n";

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO transactions
         (payment_link_id, student_id, payee_id, item_id, amount_source, currency_source,
          amount_dest, currency_dest, fx_rate, rate_source, rate_as_of,
          ilp_payment_pointer, ilp_quote_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')"
    );
    $stmt->execute([
        $link['id'], $link['student_id'], $link['payee_id'], $link['item_id'],
        $quote['target_amount'], $quote['target_currency'],
        $quote['source_amount'], $quote['source_currency'],
        $quote['rate'], $quote['rate_source'], $quote['rate_as_of'],
        '$wallet.example/student-demo', $quote['quote_id'],
    ]);
    $newId = $pdo->lastInsertId();

    // Read it back to confirm nothing was truncated on the way in.
    $saved = $pdo->query("SELECT amount_source, currency_source, amount_dest, fx_rate
                          FROM transactions WHERE id = $newId")->fetch();

    printf("   [ OK ] insert accepted (id %d, rolled back)\n", $newId);
    printf("          stored amount_source: %s %s\n", $saved['amount_source'], $saved['currency_source']);
    printf("          stored amount_dest  : %s AUD\n", $saved['amount_dest']);

    if ((float) $saved['amount_source'] == 0.0 && $quote['target_amount'] > 0) {
        echo "   [WARN] amount_source rounded to ZERO. amount_source is still\n";
        echo "          DECIMAL(14,2). Run migrations\\001_live_rates.sql.\n";
    }

    $pdo->rollBack();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "   [FAIL] " . get_class($e) . "\n";
    echo "          " . $e->getMessage() . "\n\n";
    echo "   >>> THIS IS THE REASON THE PAYMENT FAILS.\n";
    if (str_contains($e->getMessage(), '1054') || str_contains($e->getMessage(), 'Unknown column')) {
        echo "       A column is missing. Run both files in migrations\\ in order.\n";
    }
    exit;
}
echo "\n";

// --- 5. Audit helper ------------------------------------------------------
echo "5. AUDIT LOG\n";
try {
    $pdo->beginTransaction();
    logAction('student', $link['student_id'] ?: null, 'diagnostic_test', 'transaction', 0, 'check_payment.php');
    $pdo->rollBack();
    echo "   [ OK ] logAction() works\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "   [FAIL] logAction() throws AFTER the payment commits: " . $e->getMessage() . "\n";
    echo "   >>> This would show as a failed payment that actually succeeded.\n";
}

echo "\n", str_repeat('=', 72), "\n";
echo "All OK above means the failure is elsewhere; check\n";
echo "C:\\xampp\\apache\\logs\\error.log for [unipay/payment].\n";
echo "Delete this file before deploying publicly.\n";
