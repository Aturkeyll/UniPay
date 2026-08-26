<?php

function getDb() {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO(
            "mysql:host=127.0.0.1;dbname=wsu_payments;charset=utf8mb4",
            "root",
            ""
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}


function logAction($actorType, $actorId, $action, $targetType = null, $targetId = null, $details = null) {
    $pdo = getDb();
    $stmt = $pdo->prepare(
        "INSERT INTO audit_log (actor_type, actor_id, action, target_type, target_id, details)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$actorType, $actorId, $action, $targetType, $targetId, $details]);
}
