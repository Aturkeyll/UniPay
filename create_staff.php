<?php

require 'db.php';

if ($argc < 4) {
    die("Usage: php create_staff.php <username> <full_name> <password>\n");
}

[$_, $username, $fullName, $password] = $argv;

$pdo = getDb();
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO staff (username, full_name, password_hash) VALUES (?, ?, ?)");
$stmt->execute([$username, $fullName, $hash]);

echo "Created staff account '$username' (id " . $pdo->lastInsertId() . ")\n";
