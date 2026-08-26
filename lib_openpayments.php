<?php
/**
 * Interledger Open Payments integration.
 *
 * This wraps the Open Payments flow: get a quote (currency/asset conversion),
 * then create the incoming/outgoing payment. The network calls to Interledger
 * are still stubbed so the rest of the app can be demoed; the FX rates behind
 * getQuote() are now LIVE (see lib_rates.php).
 *
 * Real flow (for reference, when you wire it up):
 *   1. GET the receiving wallet address -> resource server + auth server URLs
 *   2. POST /incoming-payments on the receiver's resource server (needs a grant)
 *   3. POST /quotes on the sender's resource server, referencing the incoming payment
 *   4. Get an interactive grant for the outgoing payment (redirect student to authorize)
 *   5. POST /outgoing-payments to actually move the funds
 *
 * NOTE when you do wire up step 3: the Open Payments /quotes response is
 * authoritative for the conversion. At that point the rate from lib_rates.php
 * becomes a pre-authorization *estimate* shown to the student, and must NOT be
 * applied on top of the ILP rate. Converting twice is a nasty bug to find.
 */

require_once __DIR__ . '/lib_rates.php';

/**
 * Get a quote converting $amountAud (the amount owed, in AUD) into the
 * currency the student wants to pay with.
 *
 * @throws RatesUnavailableException if no trustworthy rate is available.
 *         This is deliberate — there is no fallback rate table. Callers should
 *         surface a "try again shortly" message rather than quoting a guess.
 */
function getQuote(float $amountAud, string $targetCurrency): array
{
    $conv = convertFromBase($amountAud, $targetCurrency);

    return [
        'quote_id'        => 'quote-' . bin2hex(random_bytes(8)),
        'source_currency' => BASE_CURRENCY,
        'source_amount'   => $amountAud,
        'target_currency' => strtoupper($targetCurrency),
        'target_amount'   => $conv['amount'],
        'rate'            => $conv['rate'],        // 1 AUD = rate * target
        'rate_as_of'      => $conv['as_of'],       // CurrencyFreaks' own timestamp
        'expires_at'      => date('c', time() + 300), // quotes are short-lived, 5 min
    ];
}

/**
 * Execute the payment against the quote. In the real integration this
 * triggers the outgoing payment grant + creation on the student's wallet.
 */
function createPayment(array $quote, string $studentWalletPointer): array
{
    // --- TODO: replace with real Open Payments /outgoing-payments call ---
    return [
        'payment_id'    => 'demo-payment-' . bin2hex(random_bytes(8)),
        'status'        => 'completed', // real integration: 'pending' until webhook/poll confirms
        'quote_id'      => $quote['quote_id'],
        'wallet_pointer'=> $studentWalletPointer,
        'completed_at'  => date('c'),
    ];
}
