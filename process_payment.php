<?php
require 'db.php';
require 'lib_openpayments.php';

header('Content-Type: application/json');

/**
 * Show the real exception to localhost, a generic message to everyone else.
 * Exception text leaks table names and file paths, so it must never reach a
 * remote visitor, but hiding it during local development just means guessing.
 */
if (!function_exists('errorDetail')) {
    function errorDetail(Throwable $e, string $publicMessage): string
    {
        $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        return $local ? get_class($e) . ': ' . $e->getMessage() : $publicMessage;
    }
}


$input = json_decode(file_get_contents('php://input'), true);

$token           = $input['token'] ?? '';
$quoteId         = $input['quote_id'] ?? '';
$currency        = strtoupper($input['currency'] ?? '');
$displayedAmount = isset($input['displayed_amount']) ? (float)$input['displayed_amount'] : null;

if ($currency === '') {
    echo json_encode(['success' => false, 'error' => 'Missing currency']);
    exit;
}

$pdo = getDb();

// Re-validate the link server-side, never trust the client's copy of the quote/amount
// Join items so the ILP metadata can carry a human description of the fee.
$stmt = $pdo->prepare(
    "SELECT pl.*, i.name AS item_name
     FROM payment_links pl
     LEFT JOIN items i ON i.id = pl.item_id
     WHERE pl.token = ? AND pl.status = 'pending'
     AND (pl.expires_at IS NULL OR pl.expires_at > NOW())"
);
$stmt->execute([$token]);
$link = $stmt->fetch();

if (!$link) {
    echo json_encode(['success' => false, 'error' => 'Invalid or expired link']);
    exit;
}

try {
    // The stored quote is the record of what the student was actually shown.
    // Reject an expired or already-used one rather than silently re-quoting at
    // a rate they never saw.
    if ($quoteId !== '') {
        $q = $pdo->prepare("SELECT * FROM quotes WHERE quote_id = ?");
        $q->execute([$quoteId]);
        $stored = $q->fetch();

        if ($stored && $stored['used_at'] !== null) {
            echo json_encode(['success' => false, 'error' => 'That quote has already been used. Please get a new one.']);
            exit;
        }
        if ($stored && strtotime($stored['expires_at']) < time()) {
            echo json_encode(['success' => false, 'error' => 'That quote expired. Please get a new one.']);
            exit;
        }
    }

    // Rebuild the quote from the DB amount rather than accepting the client's.
    // The browser previously posted the whole quote object back, which meant
    // anyone could edit target_amount in devtools and pay an arbitrary sum.
    $quote = getQuote((float)$link['amount'], $currency);

    // Rates refresh hourly and the displayed quote lives 5 minutes, so the
    // recomputed figure should match what the student saw. If a refresh landed
    // in between, or someone edited crypto_rates.php mid-checkout, make them
    // re-quote rather than silently charging a different amount. The 1%
    // tolerance absorbs float/rounding noise only.
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

    // Real Interledger payment via Rafiki. $quote is the display estimate; the
    // amounts recorded below come from Rafiki's own quote, which is
    // authoritative. Do not apply the estimate's FX rate on top of them.
    $payment = createPayment(
        $quote,
        RAFIKI_DEFAULT_SENDER_WALLET_ADDRESS,
        'UniPay: ' . ($link['item_name'] ?? 'student fee'),
        'LINK-' . $link['id']
    );
    $studentWalletPointer = $payment['wallet_pointer'];

    // Prefer Rafiki's figures; fall back to the estimate only in stub mode,
    // where no real amounts exist.
    $paidValue    = $payment['debit_value']    ?? $quote['target_amount'];
    $paidCurrency = $payment['debit_currency'] ?? $quote['target_currency'];
    $recvValue    = $payment['recv_value']     ?? $quote['source_amount'];
    $recvCurrency = $payment['recv_currency']  ?? $quote['source_currency'];
    $txStatus     = $payment['status'] === 'completed' ? 'completed' : 'pending';

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO transactions
         (payment_link_id, student_id, payee_id, item_id, amount_source, currency_source,
          amount_dest, currency_dest, fx_rate, rate_source, rate_as_of,
          ilp_payment_pointer, ilp_quote_id, ilp_outgoing_payment_id,
          ilp_incoming_payment_id, ilp_state, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $link['id'], $link['student_id'], $link['payee_id'], $link['item_id'],
        $paidValue, $paidCurrency,     // debited from the payer, per Rafiki
        $recvValue, $recvCurrency,     // received by the union, per Rafiki
        // The FX estimate is kept for audit: it is what the student was shown,
        // which matters if they later query the amount.
        $quote['rate'], $quote['rate_source'], $quote['rate_as_of'],
        $studentWalletPointer, $payment['quote_id'],
        $payment['payment_id'], $payment['receiver_id'] ?? null,
        $payment['state'], $txStatus,
    ]);
    $transactionId = $pdo->lastInsertId();

    // Only close the link once the payment actually completed. A payment still
    // settling stays 'pending' until the webhook confirms it, so a link is
    // never marked paid for money that never arrived.
    if ($txStatus === 'completed') {
        $stmt = $pdo->prepare("UPDATE payment_links SET status = 'paid' WHERE id = ?");
        $stmt->execute([$link['id']]);
    }

    if ($quoteId !== '') {
        $pdo->prepare("UPDATE quotes SET used_at = NOW(), transaction_id = ? WHERE quote_id = ?")
            ->execute([$transactionId, $quoteId]);
    }

    $pdo->commit();

    $actorType = $link['student_id'] ? 'student' : 'payee';
    $actorId   = $link['student_id'] ?: $link['payee_id'];
    logAction($actorType, $actorId, 'payment_completed', 'transaction', $transactionId,
        "ILP {$payment['state']} | debit {$paidValue} {$paidCurrency} | "
        . "received {$recvValue} {$recvCurrency} | payment {$payment['payment_id']} | "
        . "estimate was {$quote['target_amount']} {$quote['target_currency']} "
        . "@ {$quote['rate']} ({$quote['rate_source']})");

    echo json_encode([
        'success'        => true,
        'transaction_id' => $transactionId,
        'ilp_state'      => $payment['state'],
        'settled'        => $txStatus === 'completed',
        'debit'          => $paidValue !== null ? "$paidValue $paidCurrency" : null,
        'received'       => $recvValue !== null ? "$recvValue $recvCurrency" : null,
        'payment_id'     => $payment['payment_id'],
    ]);

} catch (RafikiException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[unipay/ilp] ' . $e->getMessage());
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error'   => errorDetail($e, 'The payment network is unavailable. You have not been charged.'),
    ]);

} catch (RatesUnavailableException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[unipay/rates] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error'   => errorDetail($e,
            'Live exchange rates are temporarily unavailable. Your card/wallet was not charged.'),
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[unipay/payment] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => errorDetail($e, 'Payment could not be completed.'),
    ]);
}
