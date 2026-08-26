<?php
/**
 * lib_rafiki.php: Rafiki Admin GraphQL client.
 *
 * Talks to a Rafiki instance (the Local Playground by default) using the
 * Backend Admin API. Requests are authenticated with an HMAC-SHA256 signature
 * over a canonicalised JSON body, matching Rafiki's own Bruno collection
 * (generateBackendApiSignature in collections/Rafiki/scripts.js).
 *
 * WHY THE ADMIN API RATHER THAN OPEN PAYMENTS DIRECTLY
 * The full Open Payments client flow requires GNAP grants signed with Ed25519
 * HTTP Message Signatures (RFC 9421), plus an interactive redirect for the
 * student to authorise the outgoing payment. That is the right model when the
 * payer's wallet lives at a third party. For this build UniPay acts as the
 * wallet operator on the sender side, so the Admin API is both sufficient and
 * far simpler: no key distribution, no interaction redirect. See
 * OPEN_PAYMENTS_NOTES in README for what changes if you move to true
 * third-party wallets.
 *
 * Configuration lives in rafiki_config.php.
 */

require_once __DIR__ . '/rafiki_config.php';

/** Thrown for any Rafiki transport, auth or GraphQL error. */
class RafikiException extends RuntimeException {}


/**
 * Canonical JSON, ported from stableStringify in the Rafiki tooling.
 *
 * Object keys are sorted; arrays keep their order. The signature is computed
 * over THIS string, so it must match byte for byte on both sides. PHP's
 * json_encode does not sort keys and escapes slashes by default, so neither
 * can be relied on here.
 */
function rafikiCanonicalize($value): string
{
    if ($value === null || is_scalar($value)) {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // A PHP array is either a JSON array or object depending on its keys.
    if (is_array($value) && array_is_list($value)) {
        $parts = [];
        foreach ($value as $item) {
            $parts[] = rafikiCanonicalize($item);
        }
        return '[' . implode(',', $parts) . ']';
    }

    $assoc = is_object($value) ? get_object_vars($value) : $value;
    $keys  = array_keys($assoc);
    sort($keys, SORT_STRING);   // JS Array.prototype.sort is lexicographic

    $parts = [];
    foreach ($keys as $k) {
        $parts[] = json_encode((string) $k, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                 . ':' . rafikiCanonicalize($assoc[$k]);
    }
    return '{' . implode(',', $parts) . '}';
}


/**
 * Build the signature header: "t=<ms timestamp>, v1=<hex hmac>".
 * Rafiki rejects signatures outside its timestamp tolerance, so system clock
 * drift on the web server shows up here as a 401.
 */
function rafikiSignature(array $body, string $secret, int $version = 1): string
{
    $timestamp = (int) round(microtime(true) * 1000);
    $payload   = $timestamp . '.' . rafikiCanonicalize($body);
    $digest    = hash_hmac('sha256', $payload, $secret);

    return "t={$timestamp}, v{$version}={$digest}";
}


/**
 * Execute a GraphQL operation against a Rafiki Admin endpoint.
 *
 * @param string $host     e.g. http://localhost:3001
 * @param string $tenantId Rafiki tenant UUID
 * @throws RafikiException on transport failure or GraphQL errors.
 */
function rafikiGraphql(string $host, string $tenantId, string $query, array $variables = []): array
{
    if ($tenantId === '') {
        throw new RafikiException('Rafiki tenant ID is not configured.');
    }
    if (RAFIKI_ADMIN_SECRET === '') {
        throw new RafikiException('RAFIKI_ADMIN_SECRET is not configured.');
    }

    // Note: variables must be an object in the signed payload, not an empty
    // array, or PHP encodes it as [] and the signature will not match.
    $body = [
        'query'     => $query,
        'variables' => $variables === [] ? new stdClass() : $variables,
    ];

    $signature = rafikiSignature($body, RAFIKI_ADMIN_SECRET, RAFIKI_SIGNATURE_VERSION);

    $ch = curl_init(rtrim($host, '/') . '/graphql');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => RAFIKI_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => RAFIKI_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'tenant-id: ' . $tenantId,
            'signature: ' . $signature,
        ],
    ]);

    $raw    = curl_exec($ch);
    $errNo  = curl_errno($ch);
    $errMsg = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo !== 0 || $raw === false) {
        throw new RafikiException("Cannot reach Rafiki at $host ($errNo: $errMsg). Is the playground running?");
    }

    $json = json_decode((string) $raw, true);
    if (!is_array($json)) {
        throw new RafikiException("Rafiki returned a non-JSON response (HTTP $status).");
    }

    if (!empty($json['errors'])) {
        $messages = array_map(fn($e) => $e['message'] ?? 'unknown', $json['errors']);
        throw new RafikiException('Rafiki GraphQL error: ' . implode('; ', $messages));
    }

    if ($status < 200 || $status >= 300) {
        throw new RafikiException("Rafiki returned HTTP $status.");
    }

    return $json['data'] ?? [];
}


// ---------------------------------------------------------------------------
// Amount helpers
// ---------------------------------------------------------------------------

/**
 * Convert a decimal amount into Interledger minor units.
 *
 * Interledger amounts are integer strings paired with an assetScale: AUD 45.00
 * at scale 2 is "4500". Using a float here would round badly, so the maths is
 * done on strings via bcmath-style scaling.
 */
function toMinorUnits(float $amount, int $assetScale): string
{
    if ($amount < 0) {
        throw new RafikiException('Amount cannot be negative.');
    }
    // round() before casting: (int)(0.29 * 100) is 28 in binary floating point.
    return (string) (int) round($amount * (10 ** $assetScale));
}

/** Convert Interledger minor units back to a decimal amount. */
function fromMinorUnits($value, int $assetScale): float
{
    return ((float) $value) / (10 ** $assetScale);
}

/** Format an Interledger amount object for display, e.g. "45.00 AUD". */
function formatIlpAmount(?array $amount): string
{
    if (!$amount) {
        return 'n/a';
    }
    $scale = (int) ($amount['assetScale'] ?? 2);
    return number_format(fromMinorUnits($amount['value'] ?? 0, $scale), $scale)
         . ' ' . ($amount['assetCode'] ?? '');
}


// ---------------------------------------------------------------------------
// Wallet addresses
// ---------------------------------------------------------------------------

/**
 * List wallet addresses on an instance. Used by the staff wallet picker and by
 * check_rafiki.php to prove connectivity.
 */
function rafikiListWalletAddresses(string $host, string $tenantId): array
{
    $data = rafikiGraphql($host, $tenantId, <<<'GQL'
    query GetWalletAddresses {
      walletAddresses {
        edges {
          node {
            id
            publicName
            address
            asset { code scale }
          }
        }
      }
    }
    GQL);

    $out = [];
    foreach ($data['walletAddresses']['edges'] ?? [] as $edge) {
        $out[] = $edge['node'];
    }
    return $out;
}


/** Find a wallet address by its public URL, across the configured instance. */
function rafikiFindWalletByAddress(string $host, string $tenantId, string $address): ?array
{
    foreach (rafikiListWalletAddresses($host, $tenantId) as $wa) {
        if (rtrim((string) $wa['address'], '/') === rtrim($address, '/')) {
            return $wa;
        }
    }
    return null;
}


// ---------------------------------------------------------------------------
// The payment flow: receiver -> quote -> outgoing payment
// ---------------------------------------------------------------------------

/**
 * Step 1: create a receiver (a remote incoming payment on the union's wallet).
 * This is the invoice the student's payment will settle.
 *
 * @param float  $amount        Amount to be RECEIVED by the union.
 * @param string $walletAddress The union's wallet address URL.
 */
function rafikiCreateReceiver(float $amount, string $walletAddress, string $description, ?string $externalRef = null): array
{
    $input = [
        'walletAddressUrl' => $walletAddress,
        'incomingAmount'   => [
            'assetCode'  => RAFIKI_ASSET_CODE,
            'assetScale' => RAFIKI_ASSET_SCALE,
            'value'      => toMinorUnits($amount, RAFIKI_ASSET_SCALE),
        ],
        'metadata' => array_filter([
            'description' => $description,
            'externalRef' => $externalRef,
        ]),
    ];

    $data = rafikiGraphql(RAFIKI_SENDER_HOST, RAFIKI_SENDER_TENANT, <<<'GQL'
    mutation CreateReceiver($input: CreateReceiverInput!) {
      createReceiver(input: $input) {
        receiver {
          id
          completed
          walletAddressUrl
          incomingAmount { assetCode assetScale value }
          receivedAmount { assetCode assetScale value }
        }
      }
    }
    GQL, ['input' => $input]);

    $receiver = $data['createReceiver']['receiver'] ?? null;
    if (empty($receiver['id'])) {
        throw new RafikiException('Rafiki did not return a receiver id.');
    }
    return $receiver;
}


/**
 * Step 2: create a quote. Rafiki works out what the sender must debit for the
 * receiver to get the requested amount, including any cross-currency conversion.
 *
 * THIS QUOTE IS AUTHORITATIVE for the conversion. The rates in lib_rates.php
 * are only a pre-authorisation estimate shown to the student before they
 * commit. Never apply a lib_rates rate on top of these figures.
 */
function rafikiCreateQuote(string $senderWalletAddressId, string $receiverId): array
{
    $data = rafikiGraphql(RAFIKI_SENDER_HOST, RAFIKI_SENDER_TENANT, <<<'GQL'
    mutation CreateQuote($input: CreateQuoteInput!) {
      createQuote(input: $input) {
        quote {
          id
          walletAddressId
          receiver
          debitAmount { assetCode assetScale value }
          receiveAmount { assetCode assetScale value }
        }
      }
    }
    GQL, ['input' => [
        'walletAddressId' => $senderWalletAddressId,
        'receiver'        => $receiverId,
    ]]);

    $quote = $data['createQuote']['quote'] ?? null;
    if (empty($quote['id'])) {
        throw new RafikiException('Rafiki did not return a quote id.');
    }
    return $quote;
}


/**
 * Step 3: accept the quote and create the outgoing payment.
 * Returns immediately; the payment settles asynchronously.
 */
function rafikiCreateOutgoingPayment(string $senderWalletAddressId, string $quoteId): array
{
    $data = rafikiGraphql(RAFIKI_SENDER_HOST, RAFIKI_SENDER_TENANT, <<<'GQL'
    mutation CreateOutgoingPayment($input: CreateOutgoingPaymentInput!) {
      createOutgoingPayment(input: $input) {
        payment {
          id
          state
          error
          receiver
          debitAmount { assetCode assetScale value }
          sentAmount { assetCode assetScale value }
          receiveAmount { assetCode assetScale value }
        }
      }
    }
    GQL, ['input' => [
        'walletAddressId' => $senderWalletAddressId,
        'quoteId'         => $quoteId,
    ]]);

    $payment = $data['createOutgoingPayment']['payment'] ?? null;
    if (empty($payment['id'])) {
        throw new RafikiException('Rafiki did not return an outgoing payment id.');
    }
    return $payment;
}


/** Read the current state of an outgoing payment. */
function rafikiGetOutgoingPayment(string $paymentId): array
{
    $data = rafikiGraphql(RAFIKI_SENDER_HOST, RAFIKI_SENDER_TENANT, <<<'GQL'
    query GetOutgoingPayment($id: String!) {
      outgoingPayment(id: $id) {
        id
        state
        error
        debitAmount { assetCode assetScale value }
        sentAmount { assetCode assetScale value }
        receiveAmount { assetCode assetScale value }
      }
    }
    GQL, ['id' => $paymentId]);

    $payment = $data['outgoingPayment'] ?? null;
    if (!$payment) {
        throw new RafikiException("Outgoing payment $paymentId not found.");
    }
    return $payment;
}


/**
 * Poll until the payment reaches a terminal state or the budget runs out.
 *
 * Rafiki settles asynchronously, so a freshly created payment is normally
 * FUNDING or SENDING. Polling keeps the demo synchronous; in production the
 * webhook in rafiki_webhook.php is the authoritative signal and this is only a
 * convenience. Note that a timeout here means UNKNOWN, not failed: the payment
 * may still complete, which is exactly why the webhook exists.
 */
function rafikiAwaitPayment(string $paymentId, int $maxAttempts = 8, int $sleepMs = 400): array
{
    $terminal = ['COMPLETED', 'FAILED'];
    $payment  = null;

    for ($i = 0; $i < $maxAttempts; $i++) {
        $payment = rafikiGetOutgoingPayment($paymentId);
        if (in_array($payment['state'] ?? '', $terminal, true)) {
            return $payment;
        }
        usleep($sleepMs * 1000);
    }

    return $payment ?? ['id' => $paymentId, 'state' => 'UNKNOWN'];
}


/**
 * The whole flow in one call: receiver, quote, outgoing payment, settle.
 *
 * @return array Structured result including each step, for the audit trail and
 *               the "how it works" panel on the confirmation page.
 */
function rafikiPay(
    string $senderWalletAddressId,
    string $receiverWalletAddress,
    float  $amount,
    string $description,
    ?string $externalRef = null
): array {
    $receiver = rafikiCreateReceiver($amount, $receiverWalletAddress, $description, $externalRef);
    $quote    = rafikiCreateQuote($senderWalletAddressId, $receiver['id']);
    $payment  = rafikiCreateOutgoingPayment($senderWalletAddressId, $quote['id']);
    $final    = rafikiAwaitPayment($payment['id']);

    return [
        'receiver'      => $receiver,
        'quote'         => $quote,
        'payment'       => $final,
        'state'         => $final['state'] ?? 'UNKNOWN',
        'succeeded'     => ($final['state'] ?? '') === 'COMPLETED',
        'debitAmount'   => $final['debitAmount']   ?? $quote['debitAmount']   ?? null,
        'receiveAmount' => $final['receiveAmount'] ?? $quote['receiveAmount'] ?? null,
        'sentAmount'    => $final['sentAmount']    ?? null,
    ];
}
