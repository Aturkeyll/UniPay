<?php
require 'db.php';
require 'lib_openpayments.php';

header('Content-Type: application/json');

$input    = json_decode(file_get_contents('php://input'), true);
$token    = $input['token'] ?? '';
$currency = strtoupper($input['currency'] ?? BASE_CURRENCY);

$pdo = getDb();
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
    $quote = getQuote((float)$link['amount'], $currency);

    // The client only needs to display this. process_payment.php recomputes
    // the amount server-side from the token, so a tampered copy buys nothing.
    echo json_encode(['success' => true, 'quote' => $quote]);

} catch (RatesUnavailableException $e) {
    // No fallback by design: refuse to quote rather than invent a rate.
    error_log('[unipay/rates] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error'   => 'Live exchange rates are temporarily unavailable. Please try again shortly.',
    ]);

} catch (Exception $e) {
    error_log('[unipay/quote] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not produce a quote for that currency.']);
}
