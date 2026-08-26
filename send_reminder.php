<?php
require 'db.php';
require 'lib_session.php';
$staffId = requireStaff();
$pdo = getDb();

// Sending mail is a state change, so it must not happen on a GET: an <img>
// tag or a prefetched link would fire it. POST with a CSRF token instead.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Reminders must be sent from the reconciliation dashboard.');
}
csrfGuard();

$linkId = (int)($_POST['link_id'] ?? 0);

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
