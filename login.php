<?php
require 'db.php';
require 'lib_session.php';
unipaySession();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfGuard();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $pdo = getDb();
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Throttle by IP so the login form is not a free password oracle.
    // Staff attempts are the rows with a NULL student_number.
    $recent = $pdo->prepare(
        "SELECT COUNT(*) FROM lookup_attempts
         WHERE ip = ? AND success = 0 AND student_number IS NULL
           AND created_at > (NOW() - INTERVAL 900 SECOND)"
    );
    $recent->execute([$ip]);

    if ((int) $recent->fetchColumn() >= 10) {
        $error = 'Too many failed attempts. Please wait 15 minutes.';
        logAction('system', null, 'staff_login_rate_limited', null, null, "ip=$ip");

    } else {
        $stmt = $pdo->prepare("SELECT * FROM staff WHERE username = ?");
        $stmt->execute([$username]);
        $staff = $stmt->fetch();

        if ($staff && password_verify($password, $staff['password_hash'])) {
            // New session id on privilege change, so a fixed id cannot be inherited.
            session_regenerate_id(true);
            $_SESSION['staff_id']   = $staff['id'];
            $_SESSION['staff_name'] = $staff['full_name'];
            $_SESSION['_created']   = time();
            logAction('staff', $staff['id'], 'logged_in', null, null, "ip=$ip");
            header('Location: index.php');
            exit;
        }

        // Same message either way: naming the wrong field confirms usernames.
        $pdo->prepare("INSERT INTO lookup_attempts (ip, student_number, success) VALUES (?, NULL, 0)")
            ->execute([$ip]);
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Login | UniPay</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="index.css">
</head>
<body>
<?php require 'header.php'; ?>
    <h3>Staff login</h3>

    <?php if ($error): ?><div class="notice overdue"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post">
        <?= csrfField() ?>
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
