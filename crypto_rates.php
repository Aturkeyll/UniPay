<?php
/**
 * crypto_rates.php: manually maintained crypto (and other non-ECB) rates.
 *
 * Frankfurter covers ECB fiat only, so anything else UniPay accepts is priced
 * from this file. Edit it and the change takes effect on the next page load: * no cron run needed, nothing to restart.
 *
 * HOW TO UPDATE:
 *   1. Look up the AUD price of one coin on any exchange.
 *   2. Put that number in 'aud_price'. Do NOT enter the reciprocal; the code
 *      inverts it for you. Typing 0.0000101523 by hand is how a misplaced zero
 *      turns into a 10x mispricing.
 *   3. Update 'as_of' to today's date. This is not decorative: rates older than
 *      MANUAL_RATES_MAX_AGE_DAYS are refused, and the checkout page shows this
 *      date to the student so they know the price is indicative.
 *
 * CAUTION: unlike the ECB feed, nothing refreshes these for you. Crypto can
 * move 10% in a day. A week-old BTC price will charge a student the wrong
 * amount. Set the expiry window in lib_rates.php to something you can live with.
 */

return [
    // Date these prices were checked. Format: YYYY-MM-DD.
    'as_of' => '2026-08-26',

    'rates' => [
        // CODE => [ 'name' => display name, 'aud_price' => price of 1 unit in AUD ]
        'BTC'  => ['name' => 'Bitcoin',   'aud_price' => 98500.00],
        'ETH'  => ['name' => 'Ethereum',  'aud_price' => 5200.00],
        'USDC' => ['name' => 'USD Coin',  'aud_price' => 1.52],
        'USDT' => ['name' => 'Tether',    'aud_price' => 1.52],
        'XRP'  => ['name' => 'XRP',       'aud_price' => 3.40],

        // Add more as needed. Codes must not collide with an ECB fiat code; // lib_rates.php throws on collision rather than letting a crypto rate
        // silently shadow a real currency.
    ],
];
