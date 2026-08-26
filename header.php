<?php

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isHome      = ($currentPage === 'index.php' || $currentPage === '');


$headerStaff = (session_status() === PHP_SESSION_ACTIVE) && !empty($_SESSION['staff_id']);
?>
<header class="site-header">
    <a class="brand" href="index.php">
        <img src="LogoWname.png" alt="UniPay" class="brand-logo">
    </a>
    <nav class="site-nav">
        <?php if (!$isHome): ?>
            <a class="btn-home" href="index.php">Home</a>
        <?php endif; ?>
        <?php if ($headerStaff): ?>
            <a class="btn-home btn-secondary" href="logout.php">Log out</a>
        <?php endif; ?>
    </nav>
</header>
