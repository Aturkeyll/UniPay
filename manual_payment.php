<?php
require 'db.php';
require 'lib_openpayments.php';
require 'lib_student_auth.php';
$pdo = getDb();

$studentNumber = trim($_GET['student_number'] ?? $_POST['student_number'] ?? '');
$surname       = trim($_POST['surname'] ?? '');
$student       = null;
$error         = '';
$quote         = null;
$success       = false;

// Identity is proven by student number + surname, and re-proven on the confirm
// step. Previously any 7-digit number was accepted, so anyone could walk the ID
// space and file payments against other students' accounts.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $studentNumber !== '') {
    try {
        $student = verifyStudent($studentNumber, $surname);
        if (!$student) {
            $error = STUDENT_AUTH_GENERIC_ERROR;
        }
    } catch (StudentAuthRateLimited $e) {
        $error = $e->getMessage();
    }
}

// --- Step 1: quote the amount they say they're paying ---------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_quote']) && $student) {
    $amount   = (float) ($_POST['amount'] ?? 0);
    $currency = strtoupper($_POST['currency'] ?? BASE_CURRENCY);

    if ($amount <= 0) {
        $error = "Enter a valid amount.";
    } elseif ($amount > 10000) {
        // Sanity ceiling: a manual payment is a club fee, not a wire transfer.
        // Without it, a typo (or a probe) creates a five-figure row for staff to untangle.
        $error = "Amounts over \$10,000 need to be arranged with staff directly.";
    } else {
        try {
            $quote = getQuote($amount, $currency);
        } catch (RatesUnavailableException $e) {
            // Previously uncaught: this produced a fatal error page showing the
            // full server path and a stack trace.
            error_log('[unipay/rates] ' . $e->getMessage());
            $error = "Currency conversion is temporarily unavailable. Please try again shortly.";
        } catch (Exception $e) {
            error_log('[unipay/quote] ' . $e->getMessage());
            $error = "Could not produce a quote for that currency.";
        }
    }
}

// --- Step 2: confirm ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment']) && $student) {
    // The quote is REBUILT from the posted amount and currency rather than
    // trusted from the hidden quote_json field. That field was editable in
    // devtools, so a student could declare a large AUD credit while paying a
    // trivial amount. Only raw inputs cross the wire now.
    $amount    = (float) ($_POST['amount'] ?? 0);
    $currency  = strtoupper($_POST['currency'] ?? BASE_CURRENCY);
    $reference = trim($_POST['reference'] ?? '');

    if ($amount <= 0 || $amount > 10000) {
        $error = "Invalid amount.";
    } else {
        try {
            $quote = getQuote($amount, $currency);

            $studentWalletPointer = '$wallet.example/student-demo'; // placeholder
            $payment = createPayment($quote, $studentWalletPointer);

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO transactions
                    (student_id, reference_note, amount_source, currency_source,
                     amount_dest, currency_dest, fx_rate, rate_source, rate_as_of,
                     ilp_payment_pointer, ilp_quote_id, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'needs_reconciliation')"
            );
            $stmt->execute([
                $student['id'], $reference,
                $quote['target_amount'], $quote['target_currency'],
                $quote['source_amount'], $quote['source_currency'],
                $quote['rate'], $quote['rate_source'], $quote['rate_as_of'],
                $studentWalletPointer, $quote['quote_id'],
            ]);
            $txId = $pdo->lastInsertId();

            logAction('student', $student['id'], 'manual_payment_submitted', 'transaction', $txId,
                "$reference | {$quote['target_amount']} {$quote['target_currency']} "
                . "@ {$quote['rate']} ({$quote['rate_source']})");

            $pdo->commit();
            $success = true;

        } catch (RatesUnavailableException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[unipay/rates] ' . $e->getMessage());
            $error = "Currency conversion is temporarily unavailable. Nothing was recorded.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[unipay/manual] ' . $e->getMessage());
            $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
            $error = $local ? get_class($e) . ': ' . $e->getMessage()
                            : "Something went wrong. Please try again.";
        }
    }
}

// Currency picker, built from the live feed rather than a hardcoded list.
$currencyGroups = ['fiat' => [], 'crypto' => [], 'metal' => []];
$ratesDown = false;
try {
    foreach (getSupportedCurrencies() as $code => $meta) {
        $currencyGroups[$meta['type']][$code] = $meta['name'];
    }
} catch (RatesUnavailableException $e) {
    error_log('[unipay/rates] ' . $e->getMessage());
    $ratesDown = true;
}
$groupLabels = ['fiat' => 'Currencies', 'crypto' => 'Cryptocurrencies', 'metal' => 'Metals'];

function fmtAmount($n): string
{
    return rtrim(rtrim(number_format((float) $n, 12, '.', ''), '0'), '.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Payment | UniPay</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="index.css">
</head>
<body>
<?php require 'header.php'; ?>
    <h3>Make a manual payment</h3>
    <p class="small">Use this if you're paying for something that doesn't have a specific link yet
        (e.g. paying a club directly, or settling a balance staff haven't itemized). Staff will
        match your payment to the right item afterwards.</p>

    <?php if ($success): ?>
        <div class="notice">
            Payment submitted and recorded for reconciliation. Thank you! Staff will match it to
            the correct item shortly. You can check back via
            <a href="my_payments.php">My Payments</a>.
        </div>

    <?php elseif ($quote && $student && !$error): ?>
        <div class="notice">
            You will pay: <strong><?= htmlspecialchars(fmtAmount($quote['target_amount'])) ?>
            <?= htmlspecialchars($quote['target_currency']) ?></strong>
            (equivalent to $<?= htmlspecialchars(number_format($quote['source_amount'], 2)) ?> AUD)
            <?php if ($quote['rate_source'] === 'manual'): ?>
                <br><span class="small">Indicative rate, set manually on
                <?= htmlspecialchars($quote['rate_as_of']) ?>.</span>
            <?php endif; ?>
        </div>
        <form method="post">
            <!-- Only raw inputs are carried forward; the server rebuilds the quote. -->
            <input type="hidden" name="student_number" value="<?= htmlspecialchars($studentNumber) ?>">
            <input type="hidden" name="surname" value="<?= htmlspecialchars($surname) ?>">
            <input type="hidden" name="amount" value="<?= htmlspecialchars((string) $quote['source_amount']) ?>">
            <input type="hidden" name="currency" value="<?= htmlspecialchars($quote['target_currency']) ?>">
            <input type="hidden" name="reference" value="<?= htmlspecialchars($_POST['reference'] ?? '') ?>">
            <button type="submit" name="confirm_payment">Confirm and Pay</button>
        </form>

    <?php else: ?>
        <?php if ($error): ?><div class="notice overdue"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($ratesDown): ?>
            <div class="notice overdue">Currency conversion is temporarily unavailable.
                Please try again shortly.</div>
        <?php else: ?>

        <form method="post">
            <div class="field-row">
                <label for="student_number">Student number</label>
                <input type="text" id="student_number" name="student_number"
                       value="<?= htmlspecialchars($studentNumber) ?>" required>
            </div>
            <div class="field-row">
                <label for="surname">Surname</label>
                <input type="text" id="surname" name="surname"
                       value="<?= htmlspecialchars($surname) ?>" required>
            </div>
            <div class="field-row">
                <label for="reference">Reference/note</label>
                <input type="text" id="reference" name="reference"
                       value="<?= htmlspecialchars($_POST['reference'] ?? '') ?>"
                       placeholder="e.g. 'Chess club membership'" style="flex:1;">
            </div>
            <div class="field-row">
                <label for="amount">Amount (AUD)</label>
                <input type="number" id="amount" name="amount" step="0.01" min="0.01" max="10000"
                       value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" required>
            </div>
            <div class="field-row">
                <label for="currency">Pay with</label>
                <select id="currency" name="currency">
                    <?php foreach ($currencyGroups as $type => $list): ?>
                        <?php if (!$list) continue; ?>
                        <optgroup label="<?= htmlspecialchars($groupLabels[$type]) ?>">
                            <?php foreach ($list as $code => $name): ?>
                                <option value="<?= htmlspecialchars($code) ?>"
                                    <?= ($_POST['currency'] ?? BASE_CURRENCY) === $code ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($code . ' - ' . $name) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="get_quote">Get quote</button>
        </form>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
