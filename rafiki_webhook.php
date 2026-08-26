<?php
/**
 * rafiki_webhook.php: receives Rafiki webhook events.
 *
 * Rafiki settles asynchronously. process_payment.php polls briefly and records
 * whatever state it sees, but a payment that is still SENDING when the poll
 * budget runs out is recorded as 'pending'. THIS endpoint is what finally
 * resolves those rows, so a payment that succeeded thirty seconds after
 * checkout still closes its payment link.
 *
 * Point Rafiki at it (Local Playground, in the backend env):
 *     WEBHOOK_URL=http://host.docker.internal/folkTeach/rafiki_webhook.php
 *
 * From inside Docker, "localhost" is the container itself, so host.docker.internal
 * is required to reach XAMPP on the Windows host.
 *
 * Events handled:
 *   outgoing_payment.completed  -> mark the transaction paid, close the link
 *   outgoing_payment.failed     -> mark failed, reopen the link for retry
 *   incoming_payment.completed  -> record that the union's side received funds
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rafiki_config.php';
require_once __DIR__ . '/lib_rafiki.php';

header('Content-Type: application/json');

$raw   = file_get_contents('php://input');
$event = json_decode($raw, true);

if (!is_array($event) || empty($event['type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Malformed webhook payload']);
    exit;
}

/**
 * Optional signature check, same scheme as the Admin API.
 * Uses hash_equals so a wrong signature cannot be discovered byte by byte
 * through response timing.
 */
if (RAFIKI_WEBHOOK_SECRET !== '') {
    $header = $_SERVER['HTTP_SIGNATURE'] ?? '';
    $ok = false;

    if (preg_match('/t=(\d+),\s*v\d+=([a-f0-9]+)/i', $header, $m)) {
        $expected = hash_hmac('sha256', $m[1] . '.' . rafikiCanonicalize($event), RAFIKI_WEBHOOK_SECRET);
        // Reject stale signatures so a captured webhook cannot be replayed later.
        $fresh = abs((int) round(microtime(true) * 1000) - (int) $m[1]) < 300000; // 5 min
        $ok = $fresh && hash_equals($expected, strtolower($m[2]));
    }

    if (!$ok) {
        error_log('[unipay/webhook] rejected: bad or stale signature');
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

$type = $event['type'];
$data = $event['data'] ?? [];
$id   = $data['id'] ?? null;

$pdo = getDb();

try {
    switch ($type) {

        case 'outgoing_payment.completed':
            if (!$id) break;

            $pdo->beginTransaction();

            // Record the final settled amounts, which can differ from what the
            // brief poll at checkout saw.
            $debit   = $data['debitAmount']   ?? null;
            $receive = $data['receiveAmount'] ?? null;

            $stmt = $pdo->prepare(
                "UPDATE transactions
                 SET status = 'completed', ilp_state = 'COMPLETED',
                     amount_source = COALESCE(?, amount_source),
                     currency_source = COALESCE(?, currency_source),
                     amount_dest = COALESCE(?, amount_dest),
                     currency_dest = COALESCE(?, currency_dest)
                 WHERE ilp_outgoing_payment_id = ?"
            );
            $stmt->execute([
                $debit   ? fromMinorUnits($debit['value'],   (int) $debit['assetScale'])   : null,
                $debit['assetCode']   ?? null,
                $receive ? fromMinorUnits($receive['value'], (int) $receive['assetScale']) : null,
                $receive['assetCode'] ?? null,
                $id,
            ]);

            // Close the payment link only now that funds have actually moved.
            $pdo->prepare(
                "UPDATE payment_links SET status = 'paid'
                 WHERE id IN (SELECT payment_link_id FROM (
                     SELECT payment_link_id FROM transactions
                     WHERE ilp_outgoing_payment_id = ? AND payment_link_id IS NOT NULL
                 ) AS t)"
            )->execute([$id]);

            logAction('system', null, 'ilp_payment_completed', 'transaction', null, "outgoing_payment=$id");
            $pdo->commit();
            break;

        case 'outgoing_payment.failed':
            if (!$id) break;

            $pdo->beginTransaction();

            $pdo->prepare(
                "UPDATE transactions SET status = 'failed', ilp_state = 'FAILED'
                 WHERE ilp_outgoing_payment_id = ?"
            )->execute([$id]);

            // Reopen the link so the student can try again. Without this the
            // link stays unusable after a failure that took no money.
            $pdo->prepare(
                "UPDATE payment_links SET status = 'pending'
                 WHERE id IN (SELECT payment_link_id FROM (
                     SELECT payment_link_id FROM transactions
                     WHERE ilp_outgoing_payment_id = ? AND payment_link_id IS NOT NULL
                 ) AS t)"
            )->execute([$id]);

            logAction('system', null, 'ilp_payment_failed', 'transaction', null,
                "outgoing_payment=$id error=" . ($data['error'] ?? 'unknown'));
            $pdo->commit();
            break;

        case 'incoming_payment.completed':
            logAction('system', null, 'ilp_incoming_completed', 'transaction', null,
                "incoming_payment=$id received=" . formatIlpAmount($data['receivedAmount'] ?? null));
            break;

        default:
            // Liquidity and wallet-address events are logged but not acted on.
            logAction('system', null, 'ilp_webhook_ignored', null, null, "type=$type");
    }

    echo json_encode(['received' => true]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[unipay/webhook] ' . $e->getMessage());
    // A non-2xx makes Rafiki retry, which is what we want for a transient DB error.
    http_response_code(500);
    echo json_encode(['error' => 'Processing failed']);
}
