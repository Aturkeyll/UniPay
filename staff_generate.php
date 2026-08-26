<?php
require 'db.php';
session_start();

// --- Simple staff auth guard (assumes login.php sets $_SESSION['staff_id']) ---
if (empty($_SESSION['staff_id'])) {
    header('Location: login.php');
    exit;
}
$staffId = $_SESSION['staff_id'];

$pdo = getDb();
$message = '';

// Handle form submission -> create the payment link
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payerType     = $_POST['payer_type'] ?? 'student';
    $studentNumber = trim($_POST['student_number'] ?? '');
    $payeeId       = (int)($_POST['payee_id'] ?? 0);
    $itemId        = (int)($_POST['item_id'] ?? 0);
    $amount        = (float)($_POST['amount'] ?? 0);
    $dueDate       = $_POST['due_date'] ?? null;

    $lockPayer  = isset($_POST['lock_payer'])  ? 1 : 0;
    $lockItem   = isset($_POST['lock_item'])   ? 1 : 0;
    $lockAmount = isset($_POST['lock_amount']) ? 1 : 0;

    $studentId = null;
    $resolvedPayeeId = null;

    if ($payerType === 'student') {
        $stmt = $pdo->prepare("SELECT id FROM students WHERE student_number = ?");
        $stmt->execute([$studentNumber]);
        $student = $stmt->fetch();
        if (!$student) {
            $message = "No student found with number $studentNumber. <a href='add_student.php'>Add them first</a> if this is a new student.";
        } else {
            $studentId = $student['id'];
        }
    } else {
        $stmt = $pdo->prepare("SELECT id FROM payees WHERE id = ?");
        $stmt->execute([$payeeId]);
        $payee = $stmt->fetch();
        if (!$payee) {
            $message = "No external payee found with that ID. <a href='add_student.php'>Add them first</a>.";
        } else {
            $resolvedPayeeId = $payee['id'];
        }
    }

    if (($studentId || $resolvedPayeeId) && $itemId > 0 && $amount > 0) {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $stmt = $pdo->prepare(
            "INSERT INTO payment_links
                (token, staff_id, student_id, payee_id, item_id, amount, lock_payer, lock_item, lock_amount, due_date, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $token, $staffId, $studentId, $resolvedPayeeId, $itemId, $amount,
            $lockPayer, $lockItem, $lockAmount, $dueDate ?: null, $expiresAt
        ]);

        $linkId = $pdo->lastInsertId();
        logAction('staff', $staffId, 'link_generated', 'payment_link', $linkId,
            "payer_type=$payerType, item_id=$itemId, amount=$amount");

        $generatedUrl = "https://yourdomain.com/pay.php?token=" . $token;
        $message = "Link generated: <a href=\"$generatedUrl\">$generatedUrl</a>";
    } elseif (!$message) {
        $message = "Please choose an item and enter a valid amount.";
    }
}

$payees = $pdo->query("SELECT id, full_name, email FROM payees ORDER BY full_name")->fetchAll();

// Item catalog for the dropdown
$items = $pdo->query("SELECT id, name, default_amount FROM items WHERE active = 1")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Payment Link | UniPay</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="index.css">
</head>
<body>
<?php require 'header.php'; ?>
    <h3>Generate a payment request link</h3>

    <?php if ($message): ?>
        <div class="notice"><?= $message ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="field-row">
            <label><input type="radio" name="payer_type" value="student" checked onclick="togglePayerType()"> Student</label>
            <label><input type="radio" name="payer_type" value="payee" onclick="togglePayerType()"> External payee</label>
        </div>

        <div class="field-row" id="studentPayerRow">
            <label for="student_number">Student number</label>
            <input type="text" id="student_number" name="student_number" placeholder="e.g. 7718607">
            <label><input type="checkbox" name="lock_payer" checked> Lock this field on the payment page</label>
        </div>

        <div class="field-row" id="payeePayerRow" style="display:none;">
            <label for="payee_id">External payee</label>
            <select id="payee_id" name="payee_id">
                <option value="">-- Select payee --</option>
                <?php foreach ($payees as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['full_name'] . ' (' . $p['email'] . ')') ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field-row">
            <label for="item_id">Item</label>
            <select id="item_id" name="item_id" required>
                <option value="">-- Select item --</option>
                <?php foreach ($items as $item): ?>
                    <option value="<?= $item['id'] ?>" data-default="<?= $item['default_amount'] ?>">
                        <?= htmlspecialchars($item['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label><input type="checkbox" name="lock_item" checked> Lock this field on the payment page</label>
        </div>

        <div class="field-row">
            <label for="amount">Amount (AUD)</label>
            <input type="number" id="amount" name="amount" step="0.01" min="0" required>
            <label><input type="checkbox" name="lock_amount" checked> Lock this field on the payment page</label>
        </div>

        <div class="field-row">
            <label for="due_date">Due date</label>
            <input type="date" id="due_date" name="due_date">
        </div>

        <button type="submit">Generate Link</button>
    </form>

    <p><a href="reconcile.php">Go to reconciliation dashboard</a> | <a href="lookup.php">Look up a student/payee</a></p>

    <script>
        // Auto-fill amount with the item's default when selected, but let staff override it
        document.getElementById('item_id').addEventListener('change', function () {
            const opt = this.options[this.selectedIndex];
            const def = opt.getAttribute('data-default');
            if (def && def !== '') {
                document.getElementById('amount').value = def;
            }
        });

        function togglePayerType() {
            const isStudent = document.querySelector('input[name="payer_type"]:checked').value === 'student';
            document.getElementById('studentPayerRow').style.display = isStudent ? 'flex' : 'none';
            document.getElementById('payeePayerRow').style.display = isStudent ? 'none' : 'flex';
        }
    </script>
</body>
</html>
