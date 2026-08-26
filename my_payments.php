<?php
require 'db.php';
require 'lib_student_auth.php';
$pdo = getDb();

// This page hands out payment link TOKENS. Previously any 7-digit number was
// enough to retrieve them, so walking the ID space yielded working payment
// links for other students, not just their names. Identity now requires the
// student number AND a matching surname, rate-limited per IP.
$studentNumber = trim($_POST['student_number'] ?? '');
$surname       = trim($_POST['surname'] ?? '');
$student       = null;
$outstanding   = [];
$error         = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $studentNumber !== '') {
    try {
        $student = verifyStudent($studentNumber, $surname);

        if (!$student) {
            $error = STUDENT_AUTH_GENERIC_ERROR;
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
    } catch (StudentAuthRateLimited $e) {
        $error = $e->getMessage();
    }
}
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
    <h3>Check what you owe</h3>

    <!-- POST, not GET: a GET form put the student number in the URL, where it
         landed in browser history, server access logs and any Referer header
         sent to an external site. -->
    <form method="post">
        <div class="field-row">
            <label for="student_number">Student number</label>
            <input type="text" id="student_number" name="student_number"
                   placeholder="7-digit student number"
                   value="<?= htmlspecialchars($studentNumber) ?>" required>
        </div>
        <div class="field-row">
            <label for="surname">Surname</label>
            <input type="text" id="surname" name="surname"
                   value="<?= htmlspecialchars($surname) ?>" required>
            <button type="submit">Look up</button>
        </div>
    </form>

    <?php if ($error): ?>
        <div class="notice overdue"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($student): ?>
        <h3>Hi <?= htmlspecialchars($student['first_name']) ?>!</h3>

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
                <tr><td colspan="4">You have no outstanding payments. 🎉</td></tr>
            <?php endif; ?>
        </table>

        <p>Don't see what you're looking for, or paying for something without a listed item?
           <a href="manual_payment.php">Make a manual payment instead</a>.</p>
    <?php endif; ?>

    <p class="small">Prefer to ask in plain English? <a href="ask.php">Try the AI assistant</a> instead.</p>
</body>
</html>
