<?php
require 'db.php';
require 'lib_session.php';
$staffId = requireStaff();
$pdo = getDb();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfGuard();
    $type = $_POST['type'] ?? 'student';

    if ($type === 'student') {
        $studentNumber = trim($_POST['student_number'] ?? '');
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (!preg_match('/^\d{7}$/', $studentNumber)) {
            $message = "Student number must be exactly 7 digits.";
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO students (student_number, first_name, last_name, email) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$studentNumber, $first, $last, $email]);
            logAction('staff', $_SESSION['staff_id'], 'student_added', 'student', $pdo->lastInsertId());
            $message = "Student added: $first $last ($studentNumber)";
        }
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $stmt = $pdo->prepare("INSERT INTO payees (full_name, email, notes) VALUES (?, ?, ?)");
        $stmt->execute([$fullName, $email, $notes]);
        logAction('staff', $_SESSION['staff_id'], 'payee_added', 'payee', $pdo->lastInsertId());
        $message = "External payee added: $fullName";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Student/Payee | UniPay</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="index.css">
</head>
<body>
<?php require 'header.php'; ?>
    <h3>Add a student or external payee manually</h3>

    <?php if ($message): ?><div class="notice"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <form method="post">
        <?= csrfField() ?>
        <label><input type="radio" name="type" value="student" checked onclick="toggleType()"> Student</label>
        <label><input type="radio" name="type" value="payee" onclick="toggleType()"> External payee</label>

        <div id="studentFields">
            <input type="text" name="student_number" placeholder="7-digit student number">
            <input type="text" name="first_name" placeholder="First name">
            <input type="text" name="last_name" placeholder="Last name">
            <input type="email" name="email" placeholder="Email">
        </div>

        <div id="payeeFields" style="display:none;">
            <input type="text" name="full_name" placeholder="Full name">
            <input type="email" name="email" placeholder="Email">
            <input type="text" name="notes" placeholder="Notes (e.g. parent, alumni, guest)">
        </div>

        <button type="submit">Add</button>
    </form>

    <script>
        function toggleType() {
            const isStudent = document.querySelector('input[name="type"]:checked').value === 'student';
            document.getElementById('studentFields').style.display = isStudent ? 'block' : 'none';
            document.getElementById('payeeFields').style.display = isStudent ? 'none' : 'block';
        }
    </script>
</body>
</html>
