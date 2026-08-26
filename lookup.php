<?php
require 'db.php';
session_start();
if (empty($_SESSION['staff_id'])) { header('Location: login.php'); exit; }
$pdo = getDb();

$query = trim($_GET['q'] ?? '');
$student = null;
$history = [];

if ($query !== '') {
    $stmt = $pdo->prepare(
        "SELECT * FROM students
         WHERE student_number = ? OR email = ? OR CONCAT(first_name, ' ', last_name) LIKE ?"
    );
    $stmt->execute([$query, $query, "%$query%"]);
    $student = $stmt->fetch();

    if ($student) {
        $stmt = $pdo->prepare(
            "SELECT t.*, i.name AS item_name, pl.due_date
             FROM transactions t
             LEFT JOIN items i ON i.id = t.item_id
             LEFT JOIN payment_links pl ON pl.id = t.payment_link_id
             WHERE t.student_id = ?
             ORDER BY t.created_at DESC"
        );
        $stmt->execute([$student['id']]);
        $history = $stmt->fetchAll();

        // Also pull any still-pending (unpaid) links, not just completed transactions
        $stmt = $pdo->prepare(
            "SELECT pl.*, i.name AS item_name FROM payment_links pl
             JOIN items i ON i.id = pl.item_id
             WHERE pl.student_id = ? AND pl.status IN ('pending','overdue')
             ORDER BY pl.due_date ASC"
        );
        $stmt->execute([$student['id']]);
        $outstanding = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student/Payee Lookup | UniPay</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="index.css">
</head>
<body>
<?php require 'header.php'; ?>
    <h3>Student / Payee lookup</h3>

    <form method="get">
        <input type="text" name="q" placeholder="Student number, name, or email" value="<?= htmlspecialchars($query) ?>">
        <button type="submit">Search</button>
    </form>

    <?php if ($query !== '' && !$student): ?>
        <p>No match found. <a href="add_student.php">Add them manually?</a></p>
    <?php elseif ($student): ?>
        <h3><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h3>
        <p>Student #: <?= htmlspecialchars($student['student_number']) ?> | <?= htmlspecialchars($student['email']) ?></p>

        <h4>Outstanding</h4>
        <table class="staff-table">
            <tr><th>Item</th><th>Amount</th><th>Due</th><th></th></tr>
            <?php foreach ($outstanding as $o): ?>
            <tr>
                <td><?= htmlspecialchars($o['item_name']) ?></td>
                <td>$<?= htmlspecialchars($o['amount']) ?></td>
                <td><?= htmlspecialchars($o['due_date'] ?? '-') ?></td>
                <td><a href="send_reminder.php?link_id=<?= $o['id'] ?>">Send reminder</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($outstanding)): ?>
                <tr><td colspan="4">Nothing outstanding</td></tr>
            <?php endif; ?>
        </table>

        <h4>Payment history</h4>
        <table class="staff-table">
            <tr><th>Date</th><th>Item</th><th>Paid</th><th>Status</th></tr>
            <?php foreach ($history as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['created_at']) ?></td>
                <td><?= htmlspecialchars($h['item_name'] ?? 'Unallocated') ?></td>
                <td><?= htmlspecialchars($h['amount_dest']) ?> <?= htmlspecialchars($h['currency_dest']) ?></td>
                <td><?= htmlspecialchars($h['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($history)): ?>
                <tr><td colspan="4">No payment history yet</td></tr>
            <?php endif; ?>
        </table>
    <?php endif; ?>

    <p><a href="staff_generate.php">Generate new payment link</a> | <a href="reconcile.php">Reconciliation dashboard</a></p>
</body>
</html>
