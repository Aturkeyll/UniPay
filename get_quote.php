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
        'error'   => errorDetail($e,
            'Live exchange rates are temporarily unavailable. Please try again shortly.'),
    ]);

} catch (Exception $e) {
    error_log('[unipay/quote] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error'   => errorDetail($e, 'Could not produce a quote for that currency.'),
    ]);
}
