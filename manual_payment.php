<?php
require 'db.php';
require 'lib_openpayments.php';
$pdo = getDb();

$studentNumber = trim($_GET['student_number'] ?? $_POST['student_number'] ?? '');
$student = null;
$error = '';
$quote = null;

if ($studentNumber !== '') {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_number = ?");
    $stmt->execute([$studentNumber]);
    $student = $stmt->fetch();
}

// Step 1: get a quote for the amount they say they're paying
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_quote'])) {
    $amount = (float)($_POST['amount'] ?? 0);
    $currency = $_POST['currency'] ?? 'AUD';
    $reference = trim($_POST['reference'] ?? '');

    if (!$student) {
        $error = "Enter a valid, recognized student number first.";
    } elseif ($amount <= 0) {
        $error = "Enter a valid amount.";
    } else {
        $quote = getQuote($amount, $currency);
    }
}

// Step 2: confirm payment against the quote
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $quoteData = json_decode($_POST['quote_json'], true);
    $reference = trim($_POST['reference'] ?? '');

    if ($student && $quoteData) {
        $studentWalletPointer = '$wallet.example/student-demo'; // placeholder
        $payment = createPayment($quoteData, $studentWalletPointer);

        $stmt = $pdo->prepare(
            "INSERT INTO transactions
                (student_id, reference_note, amount_source, currency_source,
                 amount_dest, currency_dest, ilp_payment_pointer, ilp_quote_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'needs_reconciliation')"
        );
        $stmt->execute([
            $student['id'], $reference,
            $quoteData['target_amount'], $quoteData['target_currency'],
            $quoteData['source_amount'], $quoteData['source_currency'],
            $studentWalletPointer, $quoteData['quote_id'],
        ]);
        $txId = $pdo->lastInsertId();

        logAction('student', $student['id'], 'manual_payment_submitted', 'transaction', $txId, $reference);

        $success = true;
    } else {
        $error = "Something went wrong — please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Payment — WSU Payments</title>
    <link rel="stylesheet" href="index.css">
</head>
<body>
    <h1>WSU Payments <span class="badge">x Interledger</span></h1>
    <h3>Make a manual payment</h3>
    <p class="small">Use this if you're paying for something that doesn't have a specific link yet
        (e.g. paying a club directly, or settling a balance staff haven't itemized). Staff will
        match your payment to the right item afterwards.</p>

    <?php if (!empty($success)): ?>
        <div class="notice">
            Payment submitted and recorded for reconciliation. Thank you! Staff will match it to
            the correct item shortly — you can check back via <a href="my_payments.php?student_number=<?= urlencode($studentNumber) ?>">My Payments</a>.
        </div>

    <?php elseif ($quote): ?>
        <div class="notice">
            You will pay: <strong><?= htmlspecialchars($quote['target_amount']) ?> <?= htmlspecialchars($quote['target_currency']) ?></strong>
            (equivalent to $<?= htmlspecialchars($quote['source_amount']) ?> AUD)
        </div>
        <form method="post">
            <input type="hidden" name="student_number" value="<?= htmlspecialchars($studentNumber) ?>">
            <input type="hidden" name="quote_json" value='<?= htmlspecialchars(json_encode($quote)) ?>'>
            <input type="hidden" name="reference" value="<?= htmlspecialchars($_POST['reference'] ?? '') ?>">
            <button type="submit" name="confirm_payment">Confirm and Pay</button>
        </form>

    <?php else: ?>
        <?php if ($error): ?><div class="notice overdue"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="post">
            <div class="field-row">
                <label for="student_number">Student number</label>
                <input type="text" id="student_number" name="student_number" value="<?= htmlspecialchars($studentNumber) ?>" required>
            </div>
            <div class="field-row">
                <label for="reference">Reference/note</label>
                <input type="text" id="reference" name="reference" placeholder="e.g. 'Chess club membership'" style="flex:1;">
            </div>
            <div class="field-row">
                <label for="amount">Amount (AUD)</label>
                <input type="number" id="amount" name="amount" step="0.01" min="0.01" required>
            </div>
            <div class="field-row">
                <label for="currency">Pay with</label>
                <select id="currency" name="currency">
                    <option value="AUD">AUD (bank transfer)</option>
                    <option value="USD">USD</option>
                    <option value="EUR">EUR</option>
                    <option value="USDC">USDC (stablecoin)</option>
                    <option value="BTC">BTC</option>
                </select>
            </div>
            <button type="submit" name="get_quote">Get quote</button>
        </form>
    <?php endif; ?>
</body>
</html>
