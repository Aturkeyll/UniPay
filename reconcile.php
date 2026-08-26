<?php
require 'db.php';
session_start();
if (empty($_SESSION['staff_id'])) { header('Location: login.php'); exit; }
$staffId = $_SESSION['staff_id'];
$pdo = getDb();

// Staff can manually allocate an unmatched transaction to an item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transaction_id'])) {
    $txId = (int)$_POST['transaction_id'];
    $itemId = (int)$_POST['item_id'];

    $stmt = $pdo->prepare(
        "UPDATE transactions SET item_id = ?, status = 'completed',
         reconciled_by = ?, reconciled_at = NOW() WHERE id = ?"
    );
    $stmt->execute([$itemId, $staffId, $txId]);

    logAction('staff', $staffId, 'reconciled', 'transaction', $txId, "assigned item_id=$itemId");
    header('Location: reconcile.php');
    exit;
}

// Pull everything needing reconciliation: no item_id, or flagged needs_reconciliation
$pending = $pdo->query(
    "SELECT t.*, s.student_number, s.first_name, s.last_name
     FROM transactions t
     LEFT JOIN students s ON s.id = t.student_id
     WHERE t.item_id IS NULL OR t.status = 'needs_reconciliation'
     ORDER BY t.created_at DESC"
)->fetchAll();

// Overdue payment links, for the reminder list
$overdue = $pdo->query(
    "SELECT pl.*, s.student_number, s.first_name, s.last_name, s.email, i.name AS item_name
     FROM payment_links pl
     JOIN students s ON s.id = pl.student_id
     JOIN items i ON i.id = pl.item_id
     WHERE pl.status = 'pending' AND pl.due_date IS NOT NULL AND pl.due_date < CURDATE()
     ORDER BY pl.due_date ASC"
)->fetchAll();

$items = $pdo->query("SELECT id, name FROM items WHERE active = 1")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reconciliation Dashboard — WSU Payments</title>
    <link rel="stylesheet" href="index.css">
</head>
<body>
    <h1>WSU Payments <span class="badge">x Interledger</span></h1>
    <h3>Reconciliation queue</h3>

    <table class="staff-table">
        <tr><th>Date</th><th>Payer</th><th>Amount</th><th>Reference</th><th>Assign to item</th></tr>
        <?php foreach ($pending as $tx): ?>
        <tr>
            <td><?= htmlspecialchars($tx['created_at']) ?></td>
            <td><?= $tx['student_number'] ? htmlspecialchars($tx['first_name'] . ' ' . $tx['last_name'] . ' (' . $tx['student_number'] . ')') : 'Unknown / manual entry' ?></td>
            <td><?= htmlspecialchars($tx['amount_source']) ?> <?= htmlspecialchars($tx['currency_source']) ?></td>
            <td><?= htmlspecialchars($tx['reference_note'] ?? '—') ?></td>
            <td>
                <form method="post" style="display:flex; gap:4px;">
                    <input type="hidden" name="transaction_id" value="<?= $tx['id'] ?>">
                    <select name="item_id" required>
                        <option value="">-- item --</option>
                        <?php foreach ($items as $item): ?>
                            <option value="<?= $item['id'] ?>"><?= htmlspecialchars($item['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Allocate</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($pending)): ?>
            <tr><td colspan="5">Nothing to reconcile 🎉</td></tr>
        <?php endif; ?>
    </table>

    <h3>Overdue payment requests</h3>
    <table class="staff-table">
        <tr><th>Student</th><th>Item</th><th>Amount</th><th>Due</th><th></th></tr>
        <?php foreach ($overdue as $o): ?>
        <tr>
            <td><?= htmlspecialchars($o['first_name'] . ' ' . $o['last_name'] . ' (' . $o['student_number'] . ')') ?></td>
            <td><?= htmlspecialchars($o['item_name']) ?></td>
            <td>$<?= htmlspecialchars($o['amount']) ?></td>
            <td><?= htmlspecialchars($o['due_date']) ?></td>
            <td><a href="send_reminder.php?link_id=<?= $o['id'] ?>">Send reminder</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($overdue)): ?>
            <tr><td colspan="5">No overdue payments</td></tr>
        <?php endif; ?>
    </table>

    <p><a href="staff_generate.php">Generate new payment link</a> | <a href="lookup.php">Look up a student/payee</a></p>
</body>
</html>
