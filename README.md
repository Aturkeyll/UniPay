# WSU Payments — built on FolkSplitter

Extends [Aturkeyll/FolkSplitter](https://github.com/Aturkeyll/FolkSplitter)'s form → checkout
pattern into a staff-generated link → student checkout → Interledger payment flow for
student union/society/club/event fees.

## Setup

1. `mysql -u root -p < schema.sql` to create the database and tables.
   *(Upgrading an existing database instead? Run each file in `migrations/` in order.)*
2. Edit `db.php` with your real DB credentials.
3. Import your student roster into the `students` table (adapt the `students.json` generator we built earlier into an INSERT loop, or use `add_student.php` one at a time).
4. Set up exchange rates — see [Exchange rates](#exchange-rates) below. **The checkout page will not offer a currency picker until the first cron run succeeds.**
5. Serve the folder with PHP's built-in server for local testing:

```
php -S localhost:8000
```

6. Visit `staff_generate.php` to create your first payment link (you'll need a `staff` row and basic login — `login.php` isn't included here, just the session check;
for the hackathon demo you can seed `$_SESSION['staff_id'] = 1;` directly to skip auth).

## File map

| File                       | Role                                                                                     |
| -------------------------- | ---------------------------------------------------------------------------------------- |
| `schema.sql`               | Full DB schema: students, payees, items, staff, payment_links, transactions, audit_log |
| `migrations/`              | Incremental DB changes for databases created before a schema change                      |
| `db.php`                   | PDO connection + `logAction()` audit helper                                              |
| `staff_generate.php`       | Staff picks student + item + amount, locks fields, generates a unique link               |
| `pay.php`                  | Student-facing checkout — locked/prefilled fields, currency picker, Pay Now              |
| `get_quote.php`            | Backend endpoint: gets an Interledger Open Payments quote                                |
| `process_payment.php`      | Backend endpoint: executes the payment, records the transaction                          |
| `lib_openpayments.php`     | Interledger integration layer — **ILP calls still stubbed**, FX rates are live           |
| `lib_rates.php`            | Rate layer: live ECB fiat + hand-set crypto, plus the cron entry point                    |
| `crypto_rates.php`         | Hand-maintained crypto prices — edit this to update BTC/ETH/etc.                          |
| `reconcile.php`            | Staff dashboard: unmatched/manual payments to allocate, overdue list with reminders      |
| `lookup.php`               | Search a student/payee by number/name/email — shows outstanding + history                |
| `add_student.php`          | Manually add a student or external payee                                                 |
| `send_reminder.php`        | Emails a reminder for an overdue payment link                                            |
| `login.php` / `logout.php` | Staff authentication                                                                     |
| `create_staff.php`         | CLI script: `php create_staff.php <username> <full_name> <password>`                     |
| `agent.php`                | AI agent backend — answers "what do I owe" using only that student's own DB data         |
| `ask.php`                  | Student-facing chat UI for the AI agent                                                  |
| `index.php`                | Home page linking staff and student tools                                                |
| `index.css`                | Shared styling, extends FolkSplitter's original stylesheet                               |

## Exchange rates

Rates come from two places, handled differently on purpose.

### Fiat — Frankfurter (live)

[Frankfurter](https://frankfurter.dev) serves ECB reference rates. **No API key, no signup,
no quota.** It supports `base=AUD` natively, so no cross-rate maths is involved.

```
php lib_rates.php          # populates cache/
```

```
# crontab -e
MAILTO=you@example.com
0 * * * * /usr/bin/php /path/to/lib_rates.php >> /var/log/unipay-rates.log 2>&1
```

Coverage is the ECB list — 31 currencies including USD, EUR, GBP, CNY, INR, IDR, JPY, KRW,
MYR, PHP, SGD, THB. Notably **absent**: VND, NPR, BDT, PKR, LKR and the Gulf currencies. If a
student's home currency isn't on the list, it won't appear in the picker.

The ECB publishes once per working day around 16:00 CET, so rates are end-of-day and a
Sunday quote legitimately uses Friday's fix.

### Crypto — `crypto_rates.php` (hand-maintained)

Frankfurter is fiat-only, so crypto is priced from a file you edit yourself:

```php
'as_of' => '2026-08-26',
'rates' => [
    'BTC' => ['name' => 'Bitcoin', 'aud_price' => 98500.00],
],
```

Enter the **AUD price of one coin** — the figure you read off an exchange. The code inverts
it for you. Hand-typing the reciprocal (`0.0000101523`) is how a misplaced zero becomes a
10x mispricing.

Edits take effect on the next page load; no cron run or restart needed.

**These rates do not update themselves.** Crypto can move 10% in a day, so a stale entry
charges students the wrong amount. Two guards:

- `MANUAL_RATES_MAX_AGE_DAYS` in `lib_rates.php` (default **3**) refuses crypto quotes once
  `as_of` is older than that. Set it to `null` to disable, which is only sensible in a
  controlled demo.
- The cron prints the age of the manual table every run and warns the day before it expires,
  so you find out from the log rather than from a student hitting a refusal at checkout.

The checkout page labels these prices "indicative rate, set manually on <date>" so students
aren't shown a hand-typed number as though it were a live market rate.

### How the two fit together

- **Cron fetches fiat; the web only reads.** `pay.php`, `get_quote.php` and
  `process_payment.php` never make an outbound call, so nobody waits on a third party
  mid-checkout.
- **No silent fallback.** Missing cache, a stale ECB fix (>5 days), or an expired manual
  table all raise `RatesUnavailableException` and the checkout says "temporarily
  unavailable". Refusing to quote is correct; inventing a rate is not.
- **Codes can't collide.** If `crypto_rates.php` defines a code that's already an ECB
  currency, `getRates()` throws rather than letting a hand-set price shadow a real one.
- **Provenance is recorded.** `transactions.rate_source` stores `ecb` or `manual` alongside
  `fx_rate` and `rate_as_of`, so a disputed crypto amount can be told apart from a disputed
  EUR one:

```sql
SELECT id, currency_source, amount_source, fx_rate, rate_as_of, created_at
FROM transactions WHERE rate_source = 'manual' ORDER BY created_at DESC;
```

### Cache directory

`cache/` must be writable by both the cron user and the web server user. It ships with an
Apache `.htaccess` denying access; moving it outside your document root is better.

## Setting up staff login

```
php create_staff.php admin "Jane Smith" mypassword123
```

Then log in at `login.php`. Every staff-only page (`staff_generate.php`, `reconcile.php`, `lookup.php`, `add_student.php`) checks `$_SESSION['staff_id']` and redirects to `login.php` if not signed in.

## AI agent (`agent.php` / `ask.php`)

This is the "AI agents + interoperable payments" piece of the hackathon theme. A student
enters their student number and asks a plain-language question ("what do I owe?", "when's
my club fee due?"). The backend:

1. Validates the student number and pulls **only that student's own** outstanding items
   from the DB — the model is never given DB access itself, just this pre-filtered JSON.
2. Passes the question + data to Claude (`claude-sonnet-4-6` via the Messages API) to
   produce a natural-language answer.
3. Falls back to a plain formatted list (no API call) if `ANTHROPIC_API_KEY` isn't set,
   so the page still works for a demo without any key configured.

Set the key before running:

```
export ANTHROPIC_API_KEY=sk-ant-...
```

This also demonstrates the "protecting users" part of the theme brief: the agent is
scoped so it structurally cannot see or answer about another student's payments, no
matter how the question is phrased.

## What's real vs. stubbed right now

**Real:**

- Full DB-backed flow: link generation → checkout → payment → reconciliation → audit log
- Locked/read-only fields on the checkout page, driven by staff's checkbox choices
- **Live ECB fiat rates** via Frankfurter, plus hand-set crypto prices, with the rate and its source persisted per transaction
- Server-side quote recomputation — the browser can't influence the amount charged
- Overdue detection and reminder emails
- Reconciliation queue for unmatched/manual payments

**Stubbed (clearly marked with `TODO` in `lib_openpayments.php`):**

- `createPayment()` fabricates a successful payment instead of driving the real
  incoming/outgoing payment + interactive grant flow
- There's no wallet address collection from the student yet — `$studentWalletPointer` in `process_payment.php` is a placeholder

## Wiring up real Interledger Open Payments

When you're ready to go live for the demo:

1. Get a test wallet at a provider supporting Open Payments (e.g. via the
   [Open Payments docs](https://openpayments.dev)) for both a "student" and "receiving"
   (student union) wallet.
2. Replace `getQuote()` in `lib_openpayments.php` with real calls: fetch the wallet
   address, create an incoming payment on the receiver's resource server, then a quote
   referencing it.
3. Replace `createPayment()` with the outgoing payment flow — this involves redirecting
   the student to authorize the grant, then creating the outgoing payment on callback.
   You'll likely want a small `payment_callback.php` to handle that redirect.
4. Everything downstream (DB writes, reconciliation, audit log) stays the same — the
   stub and the real integration return the same shape of data on purpose.

**Important:** once step 2 is real, the Open Payments `/quotes` response is authoritative
for the conversion. At that point the rates from `lib_rates.php` become a pre-authorization
*estimate* shown to the student and must not be applied on top of the ILP rate — converting
twice is a nasty bug to track down. This matters most for the hand-set crypto prices, which
will drift furthest from whatever the ILP network actually quotes.

## Known gaps to fill before the demo

- No email service wired up (`mail()` in `send_reminder.php` needs a real SMTP/API config
  — most shared hosts don't enable PHP's `mail()` by default, so swap in SendGrid/Postmark)
- `agent.php` calls the Anthropic API directly with `curl` for simplicity — fine for a
  hackathon demo, consider basic rate-limiting if you expose this publicly
- No CSRF tokens on the staff forms — add if you have time, not critical for a demo
- Quotes aren't persisted server-side. `process_payment.php` recomputes the amount from the
  link token so the charge can't be tampered with, but there's no record of quotes that were
  shown and never paid.
- Crypto prices are manual. Nobody is watching the market for you — put updating
  `crypto_rates.php` on someone's checklist, or swap in a price feed before this handles
  real money.
