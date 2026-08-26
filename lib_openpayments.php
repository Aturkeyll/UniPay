<?php
/**
 * Interledger Open Payments integration.
 *
 * This wraps the Open Payments flow: get a quote (currency/asset conversion),
 * then create the incoming/outgoing payment. Right now the network calls are
 * stubbed out with realistic responses so the rest of the app can be built and
 * demoed immediately. Swap getQuote()/createPayment() for real calls once you
 * have sandbox wallet addresses from https://openpayments.dev.
 *
 * Real flow (for reference, when you wire it up):
 *   1. GET the receiving wallet address -> resource server + auth server URLs
 *   2. POST /incoming-payments on the receiver's resource server (needs a grant)
 *   3. POST /quotes on the sender's resource server, referencing the incoming payment
 *   4. Get an interactive grant for the outgoing payment (redirect student to authorize)
 *   5. POST /outgoing-payments to actually move the funds
 */

// Supported settlement currencies for the demo. Swap for a live FX/crypto rate API.
const SUPPORTED_CURRENCIES = [
    'AUD' => 1.0,
    'USD' => 0.66,
    'EUR' => 0.61,
    'USDC' => 0.66,   // stablecoin, roughly pegged to USD
    'BTC'  => 0.0000094, // illustrative only — replace with a live feed
];

/**
 * Get a quote converting $amountAud (the amount owed, in AUD) into the
 * currency the student wants to pay with.
 */
function getQuote(float $amountAud, string $targetCurrency): array {
    if (!isset(SUPPORTED_CURRENCIES[$targetCurrency])) {
        throw new InvalidArgumentException("Unsupported currency: $targetCurrency");
    }

    // --- TODO: replace with a real Open Payments /quotes call ---
    $rate = SUPPORTED_CURRENCIES[$targetCurrency];
    $convertedAmount = round($amountAud * $rate, 8);

    return [
        'quote_id' => 'demo-quote-' . bin2hex(random_bytes(8)),
        'source_currency' => 'AUD',
        'source_amount' => $amountAud,
        'target_currency' => $targetCurrency,
        'target_amount' => $convertedAmount,
        'expires_at' => date('c', time() + 300), // quotes are short-lived, 5 min here
    ];
}

/**
 * Execute the payment against the quote. In the real integration this
 * triggers the outgoing payment grant + creation on the student's wallet.
 */
function createPayment(array $quote, string $studentWalletPointer): array {
    // --- TODO: replace with real Open Payments /outgoing-payments call ---
    return [
        'payment_id' => 'demo-payment-' . bin2hex(random_bytes(8)),
        'status' => 'completed', // real integration: 'pending' until webhook/poll confirms
        'quote_id' => $quote['quote_id'],
        'wallet_pointer' => $studentWalletPointer,
        'completed_at' => date('c'),
    ];
}
