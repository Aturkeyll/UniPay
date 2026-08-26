<?php
/**
 * Interledger Open Payments integration, backed by Rafiki.
 * ---------------------------------------------------------------------------
 * UniPay has two sources of conversion:
 *
 *   1. lib_rates.php  (Frankfurter ECB + hand-set crypto). Available instantly,
 *      no network call on the checkout path. Used ONLY to show the student an
 *      indicative figure before they commit.
 *
 *   2. Rafiki's quote. Authoritative. It is what actually moves money, and it
 *      includes the network's own conversion and fees.
*/




require_once __DIR__ . '/lib_rates.php';
require_once __DIR__ . '/lib_rafiki.php';

function getQuote(float $amountAud, string $targetCurrency): array
{
    $conv = convertFromBase($amountAud, $targetCurrency);

    return [
        'quote_id'        => 'estimate-' . bin2hex(random_bytes(8)),
        'is_estimate'     => true,
        'source_currency' => BASE_CURRENCY,
        'source_amount'   => $amountAud,
        'target_currency' => strtoupper($targetCurrency),
        'target_amount'   => $conv['amount'],
        'rate'            => $conv['rate'],
        'rate_source'     => $conv['source'],
        'rate_as_of'      => $conv['as_of'],
        'expires_at'      => date('c', time() + 300),
    ];
}

/**
 * Execute the payment through Rafiki.
 *
 * The $estimate is passed in for logging and comparison only. Everything
 * recorded downstream comes from the Rafiki response.
 *
 * @param array  $estimate       The display quote from getQuote().
 * @param string $senderWallet   Wallet address to debit.
 * @param string $description    Shown in the Rafiki/ILP metadata.
 * @return array Normalised payment result.
 * @throws RafikiException on any Interledger failure.
 */
function createPayment(array $estimate, string $senderWallet = '', string $description = 'UniPay student fee', ?string $externalRef = null): array
{
    // Escape hatch for demoing without a running playground. Nothing moves.
    if (RAFIKI_MODE === 'stub') {
        return [
            'payment_id'     => 'stub-' . bin2hex(random_bytes(8)),
            'status'         => 'completed',
            'state'          => 'STUB',
            'mode'           => 'stub',
            'quote_id'       => $estimate['quote_id'] ?? null,
            'wallet_pointer' => $senderWallet ?: RAFIKI_DEFAULT_SENDER_WALLET_ADDRESS,
            'debit_amount'   => null,
            'receive_amount' => null,
            'completed_at'   => date('c'),
        ];
    }

    $senderWallet = $senderWallet ?: RAFIKI_DEFAULT_SENDER_WALLET_ADDRESS;

    // Resolve the sender's wallet URL to the internal id the Admin API needs.
    $wallet = rafikiFindWalletByAddress(RAFIKI_SENDER_HOST, RAFIKI_SENDER_TENANT, $senderWallet);
    if (!$wallet) {
        throw new RafikiException("Sender wallet address not found on this Rafiki instance: $senderWallet");
    }

    // The fee is denominated in AUD, but the union's Rafiki wallet settles in
    // RAFIKI_ASSET_CODE. On the Local Playground that asset is USD, so asking
    // for "45.00" without converting would collect 45 USD for a 45 AUD fee.
    //
    // When the settlement asset already is the base currency (the correct
    // production setup: an AUD asset on your Rafiki instance) this is a no-op.
    // Otherwise we convert the fee into the settlement asset first, using the
    // same ECB feed as the estimate, and record which rate was used.
    $feeAud          = (float) $estimate['source_amount'];
    $settlementRate  = 1.0;
    $amountToReceive = $feeAud;

    if (RAFIKI_ASSET_CODE !== BASE_CURRENCY) {
        // Throws RatesUnavailableException rather than guessing, consistent
        // with the no-fallback rule everywhere else.
        $settle          = convertFromBase($feeAud, RAFIKI_ASSET_CODE);
        $amountToReceive = $settle['amount'];
        $settlementRate  = $settle['rate'];
    }

    $result = rafikiPay(
        $wallet['id'],
        RAFIKI_UNION_WALLET_ADDRESS,
        $amountToReceive,
        $description,
        $externalRef
    );

    if (!$result['succeeded'] && ($result['state'] ?? '') === 'FAILED') {
        $err = $result['payment']['error'] ?? 'unknown error';
        throw new RafikiException("Interledger payment failed: $err");
    }

    $debit   = $result['debitAmount'];
    $receive = $result['receiveAmount'];

    return [
        'payment_id'     => $result['payment']['id'],
        // UNKNOWN means still settling, not failed. The webhook resolves it.
        'status'         => $result['succeeded'] ? 'completed' : 'pending',
        'state'          => $result['state'],
        'mode'           => 'live',
        'quote_id'       => $result['quote']['id'],
        'receiver_id'    => $result['receiver']['id'],
        'wallet_pointer' => $senderWallet,

        // Authoritative amounts, straight from Rafiki.
        'debit_amount'   => $debit,
        'receive_amount' => $receive,
        'sent_amount'    => $result['sentAmount'],
        'debit_value'    => $debit   ? fromMinorUnits($debit['value'],   (int) $debit['assetScale'])   : null,
        'debit_currency' => $debit['assetCode']   ?? null,
        'recv_value'     => $receive ? fromMinorUnits($receive['value'], (int) $receive['assetScale']) : null,
        'recv_currency'  => $receive['assetCode'] ?? null,

        // How the AUD fee was expressed in the settlement asset. 1.0 when the
        // Rafiki asset is already AUD.
        'fee_aud'          => $feeAud,
        'settlement_rate'  => $settlementRate,
        'settlement_asset' => RAFIKI_ASSET_CODE,

        'completed_at'   => date('c'),
    ];
}
