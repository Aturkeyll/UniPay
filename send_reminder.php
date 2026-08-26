<?php
require 'db.php';
session_start();
if (empty($_SESSION['staff_id'])) { header('Location: login.php'); exit; }
$pdo = getDb();

$linkId = (int)($_GET['link_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT pl.*, s.email, s.first_name, i.name AS item_name
     FROM payment_links pl
     JOIN students s ON s.id = pl.student_id
     JOIN items i ON i.id = pl.item_id
     WHERE pl.id = ?"
);
$stmt->execute([$linkId]);
$link = $stmt->fetch();

if (!$link) {
    die("Payment link not found.");
}

$paymentUrl = "https://yourdomain.com/pay.php?token=" . $link['token'];

// --- Swap for a real mail service (SendGrid/Postmark/etc.) in production ---
$subject = "Reminder: {$link['item_name']} payment outstanding";
$body = "Hi {$link['first_name']},\n\n"
    . "This is a reminder that you have an outstanding payment for {$link['item_name']} "
    . "of \${$link['amount']} AUD, due {$link['due_date']}.\n\n"
    . "Pay here: $paymentUrl\n\n"
    . "Thanks,\nWSU Student Union";

mail($link['email'], $subject, $body);

logAction('staff', $_SESSION['staff_id'], 'reminder_sent', 'payment_link', $linkId);

header('Location: reconcile.php');
exit;
