<?php
/**
 * Database connection.
 *
 * Credentials are resolved in this order:
 *   1. UNIPAY_DB_* environment variables (what run.sh exports for the server)
 *   2. The .db_password file next to this script (written by run.sh)
 *   3. root with no password (the old XAMPP default)
 *
 * Step 2 matters on Linux: MariaDB's root account uses unix_socket auth, so
 * "root with an empty password over TCP" fails with error 1698. Without the
 * file fallback, every CLI script (create_staff.php, cron jobs) would need the
 * environment variables exported by hand first.
 */

function unipayDbCredentials(): array
{
    $host = getenv('UNIPAY_DB_HOST') ?: '127.0.0.1';
    $name = getenv('UNIPAY_DB_NAME') ?: 'wsu_payments';
    $user = getenv('UNIPAY_DB_USER') ?: '';
    $pass = getenv('UNIPAY_DB_PASS');

    if ($user !== '' && $pass !== false) {
        return [$host, $name, $user, $pass];
    }

    // Fall back to the password file run.sh generated.
    $file = __DIR__ . '/.db_password';
    if (is_readable($file)) {
        $filePass = trim((string) file_get_contents($file));
        if ($filePass !== '') {
            return [$host, $name, $user !== '' ? $user : 'unipay', $filePass];
        }
    }

    // Last resort: the original XAMPP-style connection.
    return [$host, $name, $user !== '' ? $user : 'root', $pass === false ? '' : $pass];
}

function getDb()
{
    static $pdo;
    if (!$pdo) {
        [$host, $name, $user, $pass] = unipayDbCredentials();

        try {
            $pdo = new PDO(
                "mysql:host=$host;dbname=$name;charset=utf8mb4",
                $user,
                $pass
            );
        } catch (PDOException $e) {
            // 1698 and 1045 are auth failures. The raw message sends people
            // hunting for a bug in their code rather than their credentials.
            // strpos rather than str_contains: that function is PHP 8.0+ and
            // run.sh only requires 7.3.
            $msg = $e->getMessage();
            if (strpos($msg, '1698') !== false || strpos($msg, 'Access denied') !== false) {
                throw new PDOException(
                    "Database access denied for user '$user'.\n"
                    . "On Linux, MariaDB's root account uses unix_socket auth and cannot\n"
                    . "connect over TCP. Run this once to create a usable user:\n"
                    . "    bash " . __DIR__ . "/run.sh\n"
                    . "Or set UNIPAY_DB_USER and UNIPAY_DB_PASS in the environment.\n"
                    . "Original: " . $msg
                );
            }
            throw $e;
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

// Simple audit logger used across all the pages below
function logAction($actorType, $actorId, $action, $targetType = null, $targetId = null, $details = null)
{
    $pdo = getDb();
    $stmt = $pdo->prepare(
        "INSERT INTO audit_log (actor_type, actor_id, action, target_type, target_id, details)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$actorType, $actorId, $action, $targetType, $targetId, $details]);
}
