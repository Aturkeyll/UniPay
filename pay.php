<?php
require 'db.php';
require 'lib_openpayments.php';
$pdo = getDb();

$token = $_GET['token'] ?? '';
$stmt = $pdo->prepare(
    "SELECT pl.*, i.name AS item_name,
            s.student_number, s.first_name, s.last_name,
            p.full_name AS payee_name
     FROM payment_links pl
     JOIN items i ON i.id = pl.item_id
     LEFT JOIN students s ON s.id = pl.student_id
     LEFT JOIN payees p ON p.id = pl.payee_id
     WHERE pl.token = ?"
);
$stmt->execute([$token]);
$link = $stmt->fetch();

if (!$link) {
    http_response_code(404);
    die("Invalid payment link.");
}

$payerLabel = $link['student_id']
    ? $link['first_name'] . ' ' . $link['last_name'] . ' (' . $link['student_number'] . ')'
    : $link['payee_name'];

if ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
    http_response_code(410);
    die("This link has expired. Please ask staff to send you a new one.");
}

if ($link['status'] === 'paid') {
    die("This item has already been paid. Thank you!");
}

$isOverdue = $link['due_date'] && strtotime($link['due_date']) < time();

// Build the currency picker from the live rate feed. Reads cache only — this
// never makes an outbound API call. If the cron has stalled, we show a notice
// instead of a picker rather than offering currencies we can't price.
$currencyGroups = null;
$ratesError     = null;
try {
    $currencyGroups = ['fiat' => [], 'crypto' => [], 'metal' => []];
    foreach (getSupportedCurrencies() as $code => $meta) {
        $currencyGroups[$meta['type']][$code] = $meta['name'];
    }
} catch (RatesUnavailableException $e) {
    error_log('[unipay/rates] ' . $e->getMessage());
    $ratesError = 'Currency conversion is temporarily unavailable. Please try again shortly.';
}

$groupLabels = ['fiat' => 'Currencies', 'crypto' => 'Cryptocurrencies', 'metal' => 'Metals'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Make a Payment — WSU Payments</title>
    <link rel="stylesheet" href="index.css">
</head>
<body>
<h1>WSU Payments <span class="badge">x Interledger</span></h1>

<h3>Review your payment</h3>

<?php if ($isOverdue): ?>
    <div class="notice overdue">This payment is overdue (was due <?= htmlspecialchars($link['due_date']) ?>).</div>
<?php endif; ?>

<div class="itemRow">
    <label><?= $link['student_id'] ? 'Student' : 'Payee' ?></label>
    <input type="text" value="<?= htmlspecialchars($payerLabel) ?>"
           <?= $link['lock_payer'] ? 'readonly' : '' ?>>
</div>

<div class="itemRow">
    <label>Item</label>
    <input type="text" id="itemName" value="<?= htmlspecialchars($link['item_name']) ?>"
           <?= $link['lock_item'] ? 'readonly' : '' ?>>
</div>

<div class="itemRow">
    <label>Amount owed (AUD)</label>
    <input type="text" id="amountAud" value="<?= htmlspecialchars($link['amount']) ?>" readonly>
</div>

<h3>Pay with</h3>

<?php if ($ratesError): ?>
    <div class="notice overdue"><?= htmlspecialchars($ratesError) ?></div>
<?php else: ?>
    <div class="itemRow">
        <select id="currencySelect">
            <?php foreach ($currencyGroups as $type => $list): ?>
                <?php if (!$list) continue; ?>
                <optgroup label="<?= htmlspecialchars($groupLabels[$type]) ?>">
                    <?php foreach ($list as $code => $name): ?>
                        <option value="<?= htmlspecialchars($code) ?>"
                            <?= $code === BASE_CURRENCY ? 'selected' : '' ?>>
                            <?= htmlspecialchars($code . ' — ' . $name) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>
        <button type="button" id="convertBtn">Get quote</button>
    </div>

    <div id="quoteBox" style="display:none;">
        <p>You will pay: <strong id="convertedAmount"></strong></p>
        <p class="small" id="rateLine"></p>
        <p class="small">Quote expires in 5 minutes — click "Get quote" again if it lapses.</p>
        <button type="button" id="payBtn">PAY NOW</button>
    </div>

    <div id="resultBox"></div>

<script>
const token = <?= json_encode($token) ?>;
const amountAud = <?= json_encode((float)$link['amount']) ?>;
let currentQuote = null;

// Trim trailing zeros so 0.000470000000 renders as 0.00047.
function fmt(n) {
    return parseFloat(Number(n).toFixed(12)).toString();
}

document.getElementById('convertBtn').addEventListener('click', async () => {
    const currency = document.getElementById('currencySelect').value;
    const res = await fetch('get_quote.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token, currency })
    });
    const data = await res.json();
    if (!data.success) {
        alert('Could not get quote: ' + data.error);
        return;
    }
    currentQuote = data.quote;
    document.getElementById('convertedAmount').textContent =
        `${fmt(data.quote.target_amount)} ${data.quote.target_currency}`;
    document.getElementById('rateLine').textContent =
        `Rate: 1 AUD = ${fmt(data.quote.rate)} ${data.quote.target_currency}`
        + (data.quote.rate_as_of ? ` (as of ${data.quote.rate_as_of})` : '');
    document.getElementById('quoteBox').style.display = 'block';
});

document.getElementById('payBtn').addEventListener('click', async () => {
    if (!currentQuote) return;
    // Send only the currency and what we displayed. The server recomputes the
    // amount from the link token, so editing these values achieves nothing.
    const res = await fetch('process_payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            token,
            currency: currentQuote.target_currency,
            displayed_amount: currentQuote.target_amount,
            quote_id: currentQuote.quote_id
        })
    });
    const data = await res.json();
    const box = document.getElementById('resultBox');
    if (data.success) {
        box.innerHTML = '<p class="notice">Payment completed. Thank you!</p>';
        document.getElementById('payBtn').disabled = true;
    } else {
        box.innerHTML = '<p class="notice overdue">Payment failed: ' + data.error + '</p>';
    }
});
</script>
<?php endif; ?>

</body>
</html>
