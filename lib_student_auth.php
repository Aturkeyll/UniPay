<?php
/**
 * lib_student_auth.php: student identity.
 *
 * WHAT CHANGED AND WHY
 * This used to accept a student number plus a matching surname. That stopped
 * someone walking the ID space and scraping every student, but surnames are on
 * class lists and LinkedIn, so it did nothing against anyone targeting a
 * specific person. These pages hand out payment link tokens, so that was not
 * good enough.
 *
 * Identity is now proved by controlling the email address already on file:
 * the student enters their number, we mail a one-time link, and following it
 * creates a short-lived session. Nothing is revealed before that link is used,
 * so the student number alone gets an attacker nothing.
 *
 * Tokens are single-use, expire in 15 minutes, and are stored as a SHA-256
 * hash so a database read does not hand over working links.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib_session.php';

const STUDENT_TOKEN_TTL       = 900;   // 15 minutes
const STUDENT_SESSION_TTL     = 3600;  // 1 hour of access after following a link
const STUDENT_AUTH_MAX_SENDS  = 5;     // per IP...
const STUDENT_AUTH_WINDOW     = 900;   // ...per 15 minutes

// Identical whether the number exists or not. Saying "no such student" is an
// oracle that confirms valid IDs without needing anything else.
const STUDENT_AUTH_GENERIC_NOTICE =
    'If that student number is registered, a sign-in link has been sent to the email address on file. It expires in 15 minutes.';
const STUDENT_AUTH_BAD_LINK =
    'That sign-in link is invalid, expired, or already used. Please request a new one.';
const STUDENT_AUTH_RATE_LIMITED =
    'Too many attempts. Please wait 15 minutes and try again.';


class StudentAuthRateLimited extends RuntimeException {}


function clientIp(): string
{
    // Do NOT trust X-Forwarded-For unless you control a proxy in front of this.
    // It is attacker-supplied otherwise, which makes rate limiting a no-op.
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}


function countRecentFailures(PDO $pdo, string $ip): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM lookup_attempts
         WHERE ip = ? AND created_at > (NOW() - INTERVAL ? SECOND)"
    );
    $stmt->execute([$ip, STUDENT_AUTH_WINDOW]);
    return (int) $stmt->fetchColumn();
}


function recordAttempt(PDO $pdo, string $ip, ?string $studentNumber, bool $success): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO lookup_attempts (ip, student_number, success) VALUES (?, ?, ?)"
    );
    $stmt->execute([$ip, $studentNumber !== '' ? $studentNumber : null, $success ? 1 : 0]);
}


/**
 * Mask an address for display: a.smith@westernsydney.edu.au -> a****h@we****.edu.au
 * Enough for the student to recognise their own address, not enough to harvest.
 */
function maskEmail(string $email): string
{
    [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $maskPart = function (string $s): string {
        $len = mb_strlen($s);
        if ($len <= 2) return str_repeat('*', max($len, 1));
        return mb_substr($s, 0, 1) . str_repeat('*', max($len - 2, 1)) . mb_substr($s, -1);
    };
    $dparts = explode('.', $domain);
    $dparts[0] = $maskPart($dparts[0] ?? '');
    return $maskPart($user) . '@' . implode('.', $dparts);
}


/**
 * Request a sign-in link.
 *
 * Always returns the same notice regardless of whether the student exists, so
 * the caller cannot leak that either. The return value carries a 'dev_link'
 * only on localhost, because a demo box usually has no working mail transport
 * and otherwise nobody could ever sign in.
 *
 * @throws StudentAuthRateLimited
 */
function requestStudentLoginLink(string $studentNumber): array
{
    $pdo = getDb();
    $ip  = clientIp();

    if (countRecentFailures($pdo, $ip) >= STUDENT_AUTH_MAX_SENDS) {
        logAction('system', null, 'login_rate_limited', 'student', null, "ip=$ip");
        throw new StudentAuthRateLimited(STUDENT_AUTH_RATE_LIMITED);
    }

    $studentNumber = trim($studentNumber);
    recordAttempt($pdo, $ip, $studentNumber, false);

    if (!preg_match('/^\d{7}$/', $studentNumber)) {
        return ['notice' => STUDENT_AUTH_GENERIC_NOTICE, 'dev_link' => null, 'masked' => null];
    }

    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_number = ?");
    $stmt->execute([$studentNumber]);
    $student = $stmt->fetch();

    if (!$student || empty($student['email'])) {
        // Same response as success. Do not tell them which it was.
        return ['notice' => STUDENT_AUTH_GENERIC_NOTICE, 'dev_link' => null, 'masked' => null];
    }

    // Invalidate any outstanding links so only the newest works.
    $pdo->prepare("UPDATE student_login_tokens SET used_at = NOW()
                   WHERE student_id = ? AND used_at IS NULL")->execute([$student['id']]);

    $token = bin2hex(random_bytes(32));

    // Store only the hash. A leaked database read then yields no usable links.
    $pdo->prepare(
        "INSERT INTO student_login_tokens (student_id, token_hash, expires_at, ip)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)"
    )->execute([$student['id'], hash('sha256', $token), STUDENT_TOKEN_TTL, $ip]);

    $link = studentLoginUrl($token);

    $sent = @mail(
        $student['email'],
        'Your UniPay sign-in link',
        "Hi " . $student['first_name'] . ",\n\n"
        . "Use this link to see your UniPay payments. It expires in 15 minutes and works once.\n\n"
        . $link . "\n\n"
        . "If you did not request this, you can ignore this email.\n",
        "From: no-reply@unipay.local\r\n"
    );

    logAction('student', $student['id'], 'login_link_requested', 'student', $student['id'],
        "ip=$ip mail=" . ($sent ? 'sent' : 'failed'));

    // On a demo box mail() usually is not configured. Showing the link on
    // screen keeps the app usable, but ONLY from localhost, or this would hand
    // anyone a sign-in link for any student number they typed.
    $isLocal = in_array($ip, ['127.0.0.1', '::1'], true);

    return [
        'notice'   => STUDENT_AUTH_GENERIC_NOTICE,
        'dev_link' => ($isLocal && !$sent) ? $link : null,
        'masked'   => $isLocal ? maskEmail($student['email']) : null,
    ];
}


function studentLoginUrl(string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    return "$scheme://$host$dir/student_login.php?token=" . urlencode($token);
}


/**
 * Redeem a one-time token and start a student session.
 * Returns the student row, or null if the token is bad, expired or used.
 */
function redeemStudentLoginToken(string $token): ?array
{
    $pdo = getDb();

    $stmt = $pdo->prepare(
        "SELECT t.id AS token_id, s.*
         FROM student_login_tokens t
         JOIN students s ON s.id = t.student_id
         WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()"
    );
    $stmt->execute([hash('sha256', $token)]);
    $student = $stmt->fetch();

    if (!$student) {
        logAction('system', null, 'login_token_rejected', 'student', null, 'ip=' . clientIp());
        return null;
    }

    // Single use.
    $pdo->prepare("UPDATE student_login_tokens SET used_at = NOW() WHERE id = ?")
        ->execute([$student['token_id']]);

    unipaySession();
    // New session id on privilege change, so a fixed id cannot be inherited.
    session_regenerate_id(true);
    $_SESSION['student_id']      = (int) $student['id'];
    $_SESSION['student_number']  = $student['student_number'];
    $_SESSION['student_name']    = $student['first_name'];
    $_SESSION['student_expires'] = time() + STUDENT_SESSION_TTL;

    logAction('student', $student['id'], 'logged_in', 'student', $student['id'], 'ip=' . clientIp());

    return $student;
}


/**
 * The signed-in student, or null. Expired sessions are cleared.
 */
function currentStudent(): ?array
{
    unipaySession();

    if (empty($_SESSION['student_id'])) {
        return null;
    }
    if (($_SESSION['student_expires'] ?? 0) < time()) {
        endStudentSession();
        return null;
    }

    $stmt = getDb()->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$_SESSION['student_id']]);
    return $stmt->fetch() ?: null;
}


/**
 * Page guard. Sends anyone not signed in to request a link.
 */
function requireStudent(): array
{
    $student = currentStudent();
    if (!$student) {
        header('Location: student_login.php');
        exit;
    }
    return $student;
}


function endStudentSession(): void
{
    unipaySession();
    unset($_SESSION['student_id'], $_SESSION['student_number'],
          $_SESSION['student_name'], $_SESSION['student_expires']);
}


function pruneLookupAttempts(): int
{
    $pdo  = getDb();
    $stmt = $pdo->prepare("DELETE FROM lookup_attempts WHERE created_at < (NOW() - INTERVAL 7 DAY)");
    $stmt->execute();
    $pdo->exec("DELETE FROM student_login_tokens WHERE expires_at < (NOW() - INTERVAL 1 DAY)");
    return $stmt->rowCount();
}
