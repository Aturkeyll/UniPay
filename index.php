<?php
session_start();
$isStaff = !empty($_SESSION['staff_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniPay | Interledger Hackathon</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="index.css">
</head>
<body>
<?php require 'header.php'; ?>
    <h1>Interoperable payments for student fees</h1>
    <p class="small">Built for the WSU x Interledger Hackathon, Track 1: Student and Education Payments.
        Fiat and crypto payments for student union, society, club, and event fees, with an AI agent
        to help students understand what they owe.</p>

    <?php if ($isStaff): ?>
        <p class="small">Signed in as <strong><?= htmlspecialchars($_SESSION['staff_name'] ?? 'staff') ?></strong></p>

        <h3>Staff tools</h3>
        <ul>
            <li><a href="staff_generate.php">Generate a payment link</a></li>
            <li><a href="reconcile.php">Reconciliation dashboard</a></li>
            <li><a href="lookup.php">Look up a student/payee</a></li>
            <li><a href="add_student.php">Add a student or external payee</a></li>
        </ul>
    <?php else: ?>
        <p><a href="login.php">Staff login</a></p>
    <?php endif; ?>

    <h3>Student tools</h3>
    <ul>
        <li><a href="my_payments.php">Check what you owe / pay now</a></li>
        <li><a href="manual_payment.php">Make a manual payment</a> (no item link needed; staff will reconcile it)</li>
        <li><a href="ask.php">Ask what you owe (AI assistant)</a></li>
        <li class="small">Staff can also send you a direct "pay.php?token=..." link for a specific item.</li>
    </ul>
</body>
</html>
