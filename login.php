<?php
require 'db.php';
session_start();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $pdo = getDb();
    $stmt = $pdo->prepare("SELECT * FROM staff WHERE username = ?");
    $stmt->execute([$username]);
    $staff = $stmt->fetch();

    if ($staff && password_verify($password, $staff['password_hash'])) {
        $_SESSION['staff_id'] = $staff['id'];
        $_SESSION['staff_name'] = $staff['full_name'];
        logAction('staff', $staff['id'], 'logged_in');
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Login — WSU Payments</title>
    <link rel="stylesheet" href="index.css">
</head>
<body>
    <h1>WSU Payments <span class="badge">x Interledger</span></h1>
    <h3>Staff login</h3>

    <?php if ($error): ?><div class="notice overdue"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post">
        <div class="field-row">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required>
        </div>
        <div class="field-row">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>
        <button type="submit">Log in</button>
    </form>
</body>
</html>
