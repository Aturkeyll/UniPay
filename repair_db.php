<?php
/**
 * repair_db.php: brings the database up to date with the current code.
 *
 * Visit http://localhost/folkTeach/repair_db.php
 *
 * Applies every schema change the rate/crypto work needs. Safe to run
 * repeatedly: it checks before it changes anything and skips what's already
 * correct. Existing data is preserved: the ALTERs widen columns and add
 * nullable ones, so nothing is dropped or truncated.
 *
 * This replaces running migrations/001, 002 and 003 by hand.
 *
 * DELETE THIS FILE before deploying anywhere public.
 */

require_once __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "UniPay database repair\n";
echo str_repeat('=', 72), "\n\n";

try {
    $pdo = getDb();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    echo "FAILED to connect: " . $e->getMessage() . "\n\n";
    echo "Check the credentials in db.php.\n";
    exit(1);
}

$dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
if (!$dbName) {
    echo "Connected, but db.php specifies no database name.\n";
    echo "Add the dbname to the DSN in db.php, e.g.\n";
    echo "  mysql:host=localhost;dbname=wsu_payments;charset=utf8mb4\n";
    exit(1);
}
echo "Database: $dbName\n\n";

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Tables found: " . (count($tables) ? implode(', ', $tables) : '(none)') . "\n\n";

if (!in_array('transactions', $tables, true)) {
    echo "The 'transactions' table does not exist, so the schema was never\n";
    echo "created in this database. Import schema.sql first:\n\n";
    echo "  C:\\xampp\\mysql\\bin\\mysql.exe -u root < C:\\xampp\\htdocs\\folkTeach\\schema.sql\n\n";
    echo "then re-run this page.\n";
    exit(1);
}

$changes = 0;
$already = 0;

function columns(PDO $pdo, string $table): array
{
    $out = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `$table`") as $c) {
        $out[$c['Field']] = strtolower($c['Type']);
    }
    return $out;
}

function apply(PDO $pdo, string $label, string $sql): void
{
    global $changes;
    try {
        $pdo->exec($sql);
        echo "  [APPLIED] $label\n";
        $changes++;
    } catch (Throwable $e) {
        echo "  [ FAILED] $label\n";
        echo "            " . $e->getMessage() . "\n";
    }
}

// --- transactions ---------------------------------------------------------
echo "TRANSACTIONS\n";
$cols = columns($pdo, 'transactions');

// 1. amount_source must hold crypto. DECIMAL(14,2) stores 0.00047 BTC as 0.00.
if (($cols['amount_source'] ?? '') !== 'decimal(30,12)') {
    apply($pdo, 'widen amount_source to DECIMAL(30,12)',
        "ALTER TABLE transactions MODIFY COLUMN amount_source DECIMAL(30,12) NOT NULL");
} else {
    echo "  [  OK   ] amount_source is DECIMAL(30,12)\n"; $already++;
}

// 2. Currency codes longer than 10 chars exist; VARCHAR(10) truncates them.
foreach (['currency_source' => 'NOT NULL', 'currency_dest' => 'NULL'] as $col => $nullity) {
    if (($cols[$col] ?? '') !== 'varchar(20)') {
        apply($pdo, "widen $col to VARCHAR(20)",
            "ALTER TABLE transactions MODIFY COLUMN `$col` VARCHAR(20) $nullity");
    } else {
        echo "  [  OK   ] $col is VARCHAR(20)\n"; $already++;
    }
}

// 3. The rate columns process_payment.php writes to. Missing these is the
//    single most likely reason a payment fails after a successful quote.
$newCols = [
    'fx_rate'     => "ADD COLUMN fx_rate DECIMAL(30,12) NULL AFTER currency_dest",
    'rate_source' => "ADD COLUMN rate_source VARCHAR(16) NULL AFTER fx_rate",
    'rate_as_of'  => "ADD COLUMN rate_as_of VARCHAR(40) NULL AFTER rate_source",
];
foreach ($newCols as $col => $clause) {
    if (!isset($cols[$col])) {
        apply($pdo, "add $col", "ALTER TABLE transactions $clause");
    } else {
        echo "  [  OK   ] $col exists\n"; $already++;
    }
}
echo "\n";

// --- Interledger columns (migration 004) ---------------------------------
echo "INTERLEDGER / RAFIKI\n";
$cols = columns($pdo, 'transactions');

$ilpCols = [
    'ilp_outgoing_payment_id' => "ADD COLUMN ilp_outgoing_payment_id VARCHAR(255) NULL AFTER ilp_quote_id",
    'ilp_state'               => "ADD COLUMN ilp_state VARCHAR(24) NULL AFTER ilp_outgoing_payment_id",
];
foreach ($ilpCols as $col => $clause) {
    if (!isset($cols[$col])) {
        apply($pdo, "add $col", "ALTER TABLE transactions $clause");
    } else {
        echo "  [  OK   ] $col exists\n"; $already++;
    }
}

// The webhook looks up by this column on every event.
$idx = $pdo->query("SHOW INDEX FROM transactions WHERE Key_name = 'idx_ilp_outgoing'")->fetchAll();
if (!$idx) {
    apply($pdo, 'index idx_ilp_outgoing', "CREATE INDEX idx_ilp_outgoing ON transactions (ilp_outgoing_payment_id)");
} else {
    echo "  [  OK   ] idx_ilp_outgoing exists\n"; $already++;
}

foreach (['students', 'payees'] as $tbl) {
    $tcols = columns($pdo, $tbl);
    if (!isset($tcols['wallet_address'])) {
        apply($pdo, "add $tbl.wallet_address", "ALTER TABLE `$tbl` ADD COLUMN wallet_address VARCHAR(255) NULL");
    } else {
        echo "  [  OK   ] $tbl.wallet_address exists\n"; $already++;
    }
}

if (!in_array('ilp_webhook_events', $tables, true)) {
    apply($pdo, 'create ilp_webhook_events', "
        CREATE TABLE ilp_webhook_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(64) NULL,
            event_type VARCHAR(64) NOT NULL,
            resource_id VARCHAR(255) NULL,
            payload TEXT NULL,
            received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_type_time (event_type, received_at),
            INDEX idx_resource (resource_id)
        )");
} else {
    echo "  [  OK   ] ilp_webhook_events exists\n"; $already++;
}
echo "\n";

// --- lookup_attempts ------------------------------------------------------
echo "LOOKUP_ATTEMPTS (student lookup rate limiting)\n";
if (!in_array('lookup_attempts', $tables, true)) {
    apply($pdo, 'create lookup_attempts', "
        CREATE TABLE lookup_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            student_number VARCHAR(7) NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip, created_at),
            INDEX idx_number_time (student_number, created_at)
        )");
} else {
    echo "  [  OK   ] table exists\n"; $already++;
}
echo "\n";

// --- verify ---------------------------------------------------------------
echo "VERIFICATION\n";
$cols = columns($pdo, 'transactions');
$ok = true;
foreach (['amount_source' => 'decimal(30,12)', 'currency_source' => 'varchar(20)',
          'fx_rate' => 'decimal(30,12)', 'rate_source' => 'varchar(16)',
          'rate_as_of' => 'varchar(40)', 'ilp_outgoing_payment_id' => 'varchar(255)',
          'ilp_state' => 'varchar(24)'] as $col => $type) {
    $good = ($cols[$col] ?? null) === $type;
    printf("  %s %-16s %s\n", $good ? '[ OK ]' : '[FAIL]', $col, $cols[$col] ?? 'MISSING');
    $ok = $ok && $good;
}

echo "\n", str_repeat('=', 72), "\n";
printf("%d change(s) applied, %d already correct.\n", $changes, $already);

if ($ok) {
    echo "\nSchema is correct. Try the payment again.\n";
    echo "If it still fails, process_payment.php now shows the real error in the\n";
    echo "red box when accessed from localhost; send me that message.\n";
} else {
    echo "\nSomething above failed. Send me the [FAILED] lines.\n";
}
echo "\nDelete this file before deploying publicly.\n";
