<?php
require 'db.php';
require 'lib_session.php';
require 'lib_student_auth.php';

$notice = null;
$devLink = null;
$masked = null;
$error = null;

// Following a link from the email.
if (isset($_GET['token'])) {
    $student = redeemStudentLoginToken((string) $_GET['token']);
    if ($student) {
        header('Location: my_payments.php');
        exit;
    }
    $error = STUDENT_AUTH_BAD_LINK;
}

// Requesting a link.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfGuard();
    try {
        $result  = requestStudentLoginLink($_POST['student_number'] ?? '');
        $notice  = $result['notice'];
        $devLink = $result['dev_link'];
        $masked  = $result['masked'];
    } catch (StudentAuthRateLimited $e) {
        $error = $e->getMessage();
    }
}

if (currentStudent() && !$error) {
    header('Location: my_payments.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in | UniPay</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="index.css">
</head>
<body>
<?php require 'header.php'; ?>

<h3>Sign in</h3>
<p class="small">Enter your student number and we'll email you a sign-in link. It works once
   and expires in 15 minutes.</p>

<?php if ($error): ?>
    <div class="notice overdue"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($notice): ?>
    <div class="notice"><?= htmlspecialchars($notice) ?></div>
    <?php if ($masked): ?>
        <p class="small">Sent to <?= htmlspecialchars($masked) ?></p>
    <?php endif; ?>
    <?php if ($devLink): ?>
        <div class="notice overdue">
            <strong>Local development only.</strong> No mail transport is configured on this
            machine, so here is the link directly. This is never shown to a remote visitor.
            <br><br><a href="<?= htmlspecialchars($devLink) ?>">Sign in now</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

<form method="post">
    <?= csrfField() ?>
    <div class="field-row">
        <label for="student_number">Student number</label>
        <input type="text" id="student_number" name="student_number"
               inputmode="numeric" pattern="[0-9]{7}" placeholder="7-digit student number" required>
        <button type="submit">Email me a link</button>
    </div>
</form>

<p class="small">Paying something that has no link yet?
   <a href="manual_payment.php">Make a manual payment</a>.</p>
</body>
</html>
