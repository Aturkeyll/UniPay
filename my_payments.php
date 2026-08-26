<?php
require 'db.php';
$pdo = getDb();

$studentNumber = trim($_GET['student_number'] ?? '');
$student = null;
$outstanding = [];

if ($studentNumber !== '') {
    if (!preg_match('/^\d{7}$/', $studentNumber)) {
        $error = "Student number must be exactly 7 digits.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE student_number = ?");
        $stmt->execute([$studentNumber]);
        $student = $stmt->fetch();

        if (!$student) {
            $error = "Student number not recognized. If you're new, ask a staff member to add you.";
        } else {
            $stmt = $pdo->prepare(
                "SELECT pl.token, pl.amount, pl.due_date, pl.status, i.name AS item_name
                 FROM payment_links pl
                 JOIN items i ON i.id = pl.item_id
                 WHERE pl.student_id = ? AND pl.status IN ('pending', 'overdue')
                 ORDER BY pl.due_date ASC"
            );
            $stmt->execute([$student['id']]);
            $outstanding = $stmt->fetchAll();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Payments — WSU Payments</title>
    <link rel="stylesheet" href="index.css">
</head>
<body>
    <h1>WSU Payments <span class="badge">x Interledger</span></h1>
    <h3>Check what you owe</h3>

    <form method="get">
        <div class="field-row">
            <label for="student_number">Student number</label>
            <input type="text" id="student_number" name="student_number" placeholder="7-digit student number"
                value="<?= htmlspecialchars($studentNumber) ?>" required>
            <button type="submit">Look up</button>
        </div>
    </form>

    <?php if (isset($error)): ?>
        <div class="notice overdue"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($student): ?>
        <h3>Hi <?= htmlspecialchars($student['first_name']) ?>!</h3>

        <table class="staff-table">
            <tr><th>Item</th><th>Amount</th><th>Due</th><th></th></tr>
            <?php foreach ($outstanding as $o): ?>
            <tr>
                <td><?= htmlspecialchars($o['item_name']) ?></td>
                <td>$<?= htmlspecialchars($o['amount']) ?> AUD</td>
                <td><?= htmlspecialchars($o['due_date'] ?? '—') ?><?= ($o['status'] === 'overdue') ? ' (overdue)' : '' ?></td>
                <td><a href="pay.php?token=<?= htmlspecialchars($o['token']) ?>"><button type="button">Pay now</button></a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($outstanding)): ?>
                <tr><td colspan="4">You have no outstanding payments. 🎉</td></tr>
            <?php endif; ?>
        </table>

        <p>Don't see what you're looking for, or paying for something without a listed item?
           <a href="manual_payment.php?student_number=<?= urlencode($studentNumber) ?>">Make a manual payment instead</a>.</p>
    <?php endif; ?>

    <p class="small">Prefer to ask in plain English? <a href="ask.php">Try the AI assistant</a> instead.</p>
</body>
</html>
