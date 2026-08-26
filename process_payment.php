<?php
require 'db.php';
require 'lib_openpayments.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? '';
$quote = $input['quote'] ?? null;

if (!$quote) {
    echo json_encode(['success' => false, 'error' => 'Missing quote']);
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
    // In the real integration, this is where the student authorizes the outgoing
    // payment grant on their own wallet, then we create the payment.
    $studentWalletPointer = "$" . "wallet.example/student-demo"; // placeholder
    $payment = createPayment($quote, $studentWalletPointer);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO transactions
            (payment_link_id, student_id, payee_id, item_id, amount_source, currency_source,
             amount_dest, currency_dest, ilp_payment_pointer, ilp_quote_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')"
    );
    $stmt->execute([
        $link['id'], $link['student_id'], $link['payee_id'], $link['item_id'],
        $quote['target_amount'], $quote['target_currency'],
        $quote['source_amount'], $quote['source_currency'],
        $studentWalletPointer, $quote['quote_id'],
    ]);
    $transactionId = $pdo->lastInsertId();

    $stmt = $pdo->prepare("UPDATE payment_links SET status = 'paid' WHERE id = ?");
    $stmt->execute([$link['id']]);

    $pdo->commit();

    $actorType = $link['student_id'] ? 'student' : 'payee';
    $actorId = $link['student_id'] ?: $link['payee_id'];
    logAction($actorType, $actorId, 'payment_completed', 'transaction', $transactionId,
        "via {$quote['target_currency']}, amount {$quote['target_amount']}");

    echo json_encode(['success' => true, 'transaction_id' => $transactionId]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
