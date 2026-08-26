<?php
/**
 * lib_student_auth.php: verify a student before showing them their own data.
 *
 * WHAT THIS PROTECTS AGAINST
 * Student numbers are 7 sequential digits. Without a second factor, anyone can
 * walk the ID space (7718600, 7718601, ...) and harvest names, emails and
 * payment histories. Requiring a matching surname breaks bulk enumeration:
 * each number now needs a correct name to go with it.
 *
 * WHAT THIS IS NOT
 * Surnames are not secret; they're on class lists and LinkedIn. Against
 * someone targeting one known student this barely slows them down. It stops
 * scraping, not impersonation. Before this handles anything sensitive, move to
 * real authentication: email the student a one-time link, or put it behind
 * university SSO. Treat this as a hackathon-grade speed bump, not a login.
 *
 * Usage:
 *   $student = verifyStudent($_POST['student_number'], $_POST['surname']);
 *   if (!$student) { show STUDENT_AUTH_GENERIC_ERROR; }
 */

require_once __DIR__ . '/db.php';

// Deliberately vague: never reveal WHICH field was wrong, or whether a student
// number exists. "No such student" vs "wrong surname" is itself an oracle that
// lets someone confirm valid IDs without knowing any names.
const STUDENT_AUTH_GENERIC_ERROR =
    'We could not find a match. Please check your student number and surname.';

const STUDENT_AUTH_MAX_ATTEMPTS  = 5;     // failures per IP...
const STUDENT_AUTH_WINDOW        = 900;   // ...within this many seconds (15 min)
const STUDENT_AUTH_LOCKOUT_ERROR =
    'Too many attempts. Please wait 15 minutes and try again.';


/** Thrown when an IP has exceeded the failure allowance. */
class StudentAuthRateLimited extends RuntimeException {}


function clientIp(): string
{
    // XAMPP/local dev has no proxy. Do NOT trust X-Forwarded-For unless you
    // control the proxy in front of this, it is attacker-supplied otherwise,
    // and trusting it makes rate limiting trivially bypassable.
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}


/**
 * Normalise a surname for comparison: trim, collapse inner whitespace,
 * casefold. Hyphens and apostrophes are preserved: O'Brien and Smith-Jones
 * are real names and stripping them would lock those students out.
 */
function normaliseSurname(string $surname): string
{
    $s = trim($surname);
    $s = preg_replace('/\s+/u', ' ', $s);
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}


function countRecentFailures(PDO $pdo, string $ip): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM lookup_attempts
         WHERE ip = ? AND success = 0 AND created_at > (NOW() - INTERVAL ? SECOND)"
    );
    $stmt->execute([$ip, STUDENT_AUTH_WINDOW]);
    return (int) $stmt->fetchColumn();
}


function recordAttempt(PDO $pdo, string $ip, ?string $studentNumber, bool $success): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO lookup_attempts (ip, student_number, success) VALUES (?, ?, ?)"
    );
    // Store the attempted number so a burst of failures across many IDs is
    // visible in the data as enumeration, not just as noise.
    $stmt->execute([$ip, $studentNumber !== '' ? $studentNumber : null, $success ? 1 : 0]);
}


/**
 * Verify a student number against a surname.
 *
 * @return array|null The student row on success, null on any mismatch.
 * @throws StudentAuthRateLimited if this IP has failed too often.
 */
function verifyStudent(string $studentNumber, string $surname): ?array
{
    $pdo = getDb();
    $ip  = clientIp();

    if (countRecentFailures($pdo, $ip) >= STUDENT_AUTH_MAX_ATTEMPTS) {
        logAction('system', null, 'lookup_rate_limited', 'student', null, "ip=$ip");
        throw new StudentAuthRateLimited(STUDENT_AUTH_LOCKOUT_ERROR);
    }

    $studentNumber = trim($studentNumber);

    // Shape check first: a 7-digit format saves a pointless query.
    if (!preg_match('/^\d{7}$/', $studentNumber) || trim($surname) === '') {
        recordAttempt($pdo, $ip, $studentNumber, false);
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM students WHERE student_number = ?");
    $stmt->execute([$studentNumber]);
    $student = $stmt->fetch();

    // Compare in PHP rather than in the WHERE clause, so the query is identical
    // whether or not the surname matches. Matching in SQL makes "student exists
    // but wrong surname" measurably faster than "no such student", which leaks
    // valid IDs through response timing.
    $matched = false;
    if ($student) {
        $matched = hash_equals(
            normaliseSurname($student['last_name']),
            normaliseSurname($surname)
        );
    }

    recordAttempt($pdo, $ip, $studentNumber, $matched);

    if (!$matched) {
        logAction('system', null, 'lookup_failed', 'student', $student['id'] ?? null,
            "number=$studentNumber ip=$ip");
        return null;
    }

    logAction('student', $student['id'], 'lookup_success', 'student', $student['id'], "ip=$ip");
    return $student;
}


/**
 * Prune old rows. Call from the rate cron, or ignore; the table is tiny.
 */
function pruneLookupAttempts(): int
{
    $pdo = getDb();
    $stmt = $pdo->prepare("DELETE FROM lookup_attempts WHERE created_at < (NOW() - INTERVAL 7 DAY)");
    $stmt->execute();
    return $stmt->rowCount();
}
