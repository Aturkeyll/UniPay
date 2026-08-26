<?php
require 'db.php';
require 'lib_openpayments.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$token           = $input['token'] ?? '';
$currency        = strtoupper($input['currency'] ?? '');
$displayedAmount = isset($input['displayed_amount']) ? (float)$input['displayed_amount'] : null;

if ($currency === '') {
    echo json_encode(['success' => false, 'error' => 'Missing currency']);
    exit;
}

$pdo = getDb();

// Re-validate the link server-side — never trust the client's copy of the quote/amount
$stmt = $pdo->prepare(
    "SELECT * FROM payment_links WHERE token = ? AND status = 'pending'
     AND (expires_at IS NULL OR expires_at > NOW())"
);
$stmt->execute([$token]);
$link = $stmt->fetch();

if (!$link) {
    echo json_encode(['success' => false, 'error' => 'Invalid or expired link']);
    exit;
}

try {
    // Rebuild the quote from the DB amount rather than accepting the client's.
    // The browser previously posted the whole quote object back, which meant
    // anyone could edit target_amount in devtools and pay an arbitrary sum.
    $quote = getQuote((float)$link['amount'], $currency);

    // Rates refresh hourly and the displayed quote lives 5 minutes, so the
    // recomputed figure should match what the student saw. If a refresh landed
    // in between, make them re-quote rather than silently charging a different
    // amount. 1% tolerance absorbs float/rounding noise only.
    if ($displayedAmount !== null && $displayedAmount > 0) {
        $drift = abs($quote['target_amount'] - $displayedAmount) / $displayedAmount;
        if ($drift > 0.01) {
            echo json_encode([
                'success' => false,
                'error'   => 'The exchange rate moved while you were paying. Please get a new quote.',
            ]);
            exit;
        }
    }

    // In the real integration, this is where the student authorizes the outgoing
    // payment grant on their own wallet, then we create the payment.
    $studentWalletPointer = "$" . "wallet.example/student-demo"; // placeholder

    $payment = createPayment($quote, $studentWalletPointer);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO transactions
         (payment_link_id, student_id, payee_id, item_id, amount_source, currency_source,
          amount_dest, currency_dest, fx_rate, rate_as_of, ilp_payment_pointer, ilp_quote_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')"
    );
    $stmt->execute([
        $link['id'], $link['student_id'], $link['payee_id'], $link['item_id'],
        $quote['target_amount'], $quote['target_currency'],   // what the payer sent
        $quote['source_amount'], $quote['source_currency'],   // what the union receives (AUD)
        $quote['rate'], $quote['rate_as_of'],
        $studentWalletPointer, $quote['quote_id'],
    ]);
    $transactionId = $pdo->lastInsertId();

    $stmt = $pdo->prepare("UPDATE payment_links SET status = 'paid' WHERE id = ?");
    $stmt->execute([$link['id']]);

    $pdo->commit();

    $actorType = $link['student_id'] ? 'student' : 'payee';
    $actorId   = $link['student_id'] ?: $link['payee_id'];
    logAction($actorType, $actorId, 'payment_completed', 'transaction', $transactionId,
        "via {$quote['target_currency']}, amount {$quote['target_amount']}, rate {$quote['rate']}");

    echo json_encode(['success' => true, 'transaction_id' => $transactionId]);

} catch (RatesUnavailableException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[unipay/rates] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error'   => 'Live exchange rates are temporarily unavailable. Your card/wallet was not charged.',
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[unipay/payment] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Payment could not be completed.']);
}
