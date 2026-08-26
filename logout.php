<?php
session_start();
if (!empty($_SESSION['staff_id'])) {
    require 'db.php';
    logAction('staff', $_SESSION['staff_id'], 'logged_out');
}
session_destroy();
header('Location: login.php');
exit;
