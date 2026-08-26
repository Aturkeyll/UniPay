<?php
require 'db.php';
require 'lib_session.php';
require 'lib_student_auth.php';

// This page hands out payment link TOKENS, so it needs a real session, not a
// guessable identifier. requireStudent() bounces anyone who has not followed a
// one-time link from their own email inbox.
$student = requireStudent();
$pdo = getDb();

$stmt = $pdo->prepare(
    "SELECT pl.token, pl.amount, pl.due_date, pl.status, i.name AS item_name
     FROM payment_links pl
     JOIN items i ON i.id = pl.item_id
     WHERE pl.student_id = ? AND pl.status IN ('pending', 'overdue')
     ORDER BY pl.due_date ASC"
);
$stmt->execute([$student['id']]);
$outstanding = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Payments | UniPay</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="index.css">
</head>
<body>
<?php require 'header.php'; ?>

<h3>Hi <?= htmlspecialchars($student['first_name']) ?></h3>
<p class="small">Signed in as <?= htmlspecialchars($student['student_number']) ?>.
   <a href="student_logout.php">Sign out</a></p>

<table class="staff-table">
    <tr><th>Item</th><th>Amount</th><th>Due</th><th></th></tr>
    <?php foreach ($outstanding as $o): ?>
    <tr>
        <td><?= htmlspecialchars($o['item_name']) ?></td>
        <td>$<?= htmlspecialchars(number_format((float)$o['amount'], 2)) ?> AUD</td>
        <td><?= htmlspecialchars($o['due_date'] ?? '-') ?><?= ($o['status'] === 'overdue') ? ' (overdue)' : '' ?></td>
        <td><a href="pay.php?token=<?= htmlspecialchars($o['token']) ?>"><button type="button">Pay now</button></a></td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($outstanding)): ?>
        <tr><td colspan="4">You have no outstanding payments.</td></tr>
    <?php endif; ?>
</table>

<p>Paying for something that isn't listed?
   <a href="manual_payment.php">Make a manual payment instead</a>.</p>

<p class="small">Prefer to ask in plain English? <a href="ask.php">Try the AI assistant</a>.</p>
</body>
</html>
