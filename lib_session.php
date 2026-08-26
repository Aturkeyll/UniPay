<?php
/**
 * lib_session.php: session hardening and CSRF protection.
 *
 * Include this INSTEAD of calling session_start() directly. PHP's defaults
 * leave the session cookie readable by JavaScript and sent on cross-site
 * requests, which undermines the CSRF tokens below.
 */

/**
 * Start a session with sane cookie flags. Safe to call more than once.
 */
function unipaySession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Secure only over HTTPS: setting it on plain HTTP means the cookie is
    // never sent and nobody can log in, which is how people end up disabling
    // all of this in frustration.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,          // dies with the browser session
        'path'     => '/',
        'httponly' => true,       // JavaScript cannot read it, so XSS cannot steal it
        'secure'   => $https,
        'samesite' => 'Lax',      // not sent on cross-site POSTs
    ]);

    session_start();

    // Rotate the id periodically so a fixated or leaked id has a short life.
    if (!isset($_SESSION['_created'])) {
        $_SESSION['_created'] = time();
    } elseif (time() - $_SESSION['_created'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }
}


/**
 * The CSRF token for this session, created on first use.
 */
function csrfToken(): string
{
    unipaySession();
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}


/** Hidden input to drop inside every POST form. */
function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrfToken()) . '">';
}


/**
 * Verify the token on a POST. Returns false rather than throwing so callers
 * can show a normal error.
 *
 * hash_equals, not ==, so the comparison time does not reveal how much of the
 * token was guessed correctly.
 */
function csrfValid(): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }
    unipaySession();

    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return !empty($_SESSION['_csrf']) && is_string($sent)
        && hash_equals($_SESSION['_csrf'], $sent);
}


/**
 * Guard a POST handler. Call before doing anything with $_POST.
 * Stops on failure rather than returning, so a missed check cannot fall
 * through into the action it was meant to protect.
 */
function csrfGuard(): void
{
    if (csrfValid()) {
        return;
    }

    error_log('[unipay/csrf] rejected ' . ($_SERVER['REQUEST_URI'] ?? '?')
        . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));

    http_response_code(400);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Security token expired. Reload and try again.']);
    } else {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">'
           . '<link rel="stylesheet" href="index.css"><title>Session expired | UniPay</title></head><body>'
           . '<div class="notice overdue">Your session expired or the form was submitted from '
           . 'another site. Go back, reload the page, and try again.</div></body></html>';
    }
    exit;
}


/**
 * Staff-only page guard. Replaces the repeated session_start + empty() check.
 */
function requireStaff(): int
{
    unipaySession();
    if (empty($_SESSION['staff_id'])) {
        header('Location: login.php');
        exit;
    }
    return (int) $_SESSION['staff_id'];
}
