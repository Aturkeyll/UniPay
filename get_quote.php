<?php
require 'db.php';
require 'lib_openpayments.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? '';
$currency = $input['currency'] ?? 'AUD';

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
    echo json_encode(['success' => true, 'quote' => $quote]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
