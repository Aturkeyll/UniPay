<?php
/**
 * header.php - shared UniPay page header.
 *
 * Include at the top of the <body> on every HTML page:
 *     <?php require 'header.php'; ?>
 *
 * Renders the logo (which links home) plus an explicit Home button. The Home
 * button is hidden on index.php itself, since a link to the page you are
 * already on is just noise.
 */

$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isHome      = ($currentPage === 'index.php' || $currentPage === '');

// Student-facing pages never call session_start(), so reading $_SESSION
// unguarded would emit a warning on those pages.
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
