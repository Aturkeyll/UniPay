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

    // Only allocate rows that are genuinely awaiting reconciliation. Without
    // the status guard, a replayed or edited form could flip a 'failed' row to
    // 'completed'.
    $stmt = $pdo->prepare(
        "UPDATE transactions SET item_id = ?, status = 'completed',
         reconciled_by = ?, reconciled_at = NOW()
         WHERE id = ? AND (item_id IS NULL OR status = 'needs_reconciliation')"
    );
    $stmt->execute([$itemId, $staffId, $txId]);

    if ($stmt->rowCount() === 0) {
        logAction('staff', $staffId, 'reconcile_rejected', 'transaction', $txId,
            'not awaiting reconciliation');
        header('Location: reconcile.php');
        exit;
    }

    logAction('staff', $staffId, 'reconciled', 'transaction', $txId, "assigned item_id=$itemId");
    header('Location: reconcile.php');
    exit;
}

// Pull everything needing reconciliation: no item_id, or flagged needs_reconciliation
$pending = $pdo->query(
    "SELECT t.*, s.student_number, s.first_name, s.last_name
     FROM transactions t
     LEFT JOIN students s ON s.id = t.student_id
     WHERE t.item_id IS NULL OR t.status IN ('needs_reconciliation', 'pending')
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

// Totals use amount_dest (what the union received), never amount_source, which
// mixes the payers' currencies. amount_dest is the Interledger SETTLEMENT
// asset, which is only AUD when RAFIKI_ASSET_CODE is AUD, so total per currency
// rather than assuming one.
$pendingTotals = [];
foreach ($pending as $tx) {
    $cur = $tx['currency_dest'] ?: '?';
    $pendingTotals[$cur] = ($pendingTotals[$cur] ?? 0.0) + (float) $tx['amount_dest'];
}

/** Trim trailing zeros so 0.000456852792 doesn't render as 0.000456852792000. */
function fmtPaid($n): string
{
    return rtrim(rtrim(number_format((float) $n, 12, '.', ''), '0'), '.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reconciliation Dashboard | UniPay</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="index.css">
</head>
<body>
<?php require 'header.php'; ?>
    <h3>Reconciliation queue</h3>

    <table class="staff-table">
        <tr><th>Date</th><th>Payer</th><th>Received</th><th>Paid as</th><th>Status</th><th>Reference</th><th>Assign to item</th></tr>
        <?php foreach ($pending as $tx): ?>
        <tr>
            <td><?= htmlspecialchars($tx['created_at']) ?></td>
            <td><?= $tx['student_number'] ? htmlspecialchars($tx['first_name'] . ' ' . $tx['last_name'] . ' (' . $tx['student_number'] . ')') : 'Unknown / manual entry' ?></td>
            <td><strong><?= htmlspecialchars(number_format((float) $tx['amount_dest'], 2)) ?>
                <?= htmlspecialchars($tx['currency_dest'] ?? '') ?></strong></td>
            <td class="small">
                <?= htmlspecialchars(fmtPaid($tx['amount_source'])) ?>
                <?= htmlspecialchars($tx['currency_source']) ?>
                <?php if (($tx['rate_source'] ?? '') === 'manual'): ?>
                    <br><span title="Priced from a hand-maintained rate, not a live feed">
                        indicative rate <?= htmlspecialchars($tx['rate_as_of'] ?? '') ?>
                    </span>
                <?php endif; ?>
            </td>
            <td class="small">
                <?php $st = $tx['ilp_state'] ?? null; ?>
                <?php if ($tx['status'] === 'pending' && $st): ?>
                    <span title="Still settling on the Interledger network">settling (<?= htmlspecialchars($st) ?>)</span>
                <?php elseif ($tx['status'] === 'failed'): ?>
                    failed<?= $st ? ' (' . htmlspecialchars($st) . ')' : '' ?>
                <?php else: ?>
                    <?= htmlspecialchars($tx['status']) ?>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($tx['reference_note'] ?? '-') ?></td>
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
            <tr><td colspan="7">Nothing to reconcile 🎉</td></tr>
        <?php else: ?>
            <?php foreach ($pendingTotals as $cur => $total): ?>
            <tr><td colspan="2"><strong>Total awaiting allocation</strong></td>
                <td><strong><?= number_format($total, 2) ?> <?= htmlspecialchars($cur) ?></strong></td>
                <td colspan="4"></td></tr>
            <?php endforeach; ?>
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
