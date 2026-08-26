# UniPay

## Setup
```
php -S localhost:8000
```

  `staff_generate.php` to create your first payment link to the students, gives the cost of it in AUD and what type of service/product they owe

## File map

| File                       | Role                                                                                     |
| -------------------------- | ---------------------------------------------------------------------------------------- |
| `schema.sql`               | Full DB schema: students, payees, items, staff, payment_links, transactions, audit_log |
| `migrations/`              | Incremental DB changes for databases created before a schema change                      |
| `db.php`                   | PDO connection + `logAction()` audit helper                                              |
| `staff_generate.php`       | Staff picks student + item + amount, locks fields, generates a unique link               |
| `pay.php`                  | Student-facing checkout, locked/prefilled fields, currency picker, Pay Now              |
| `get_quote.php`            | Backend endpoint: gets an Interledger Open Payments quote                                |
| `process_payment.php`      | Backend endpoint: executes the payment, records the transaction                          |
| `lib_openpayments.php`     | Bridges UniPay to Rafiki; keeps the estimate and the authoritative quote apart            |
| `lib_rafiki.php`           | Rafiki Admin GraphQL client: HMAC signing, receiver/quote/payment flow                    |
| `rafiki_config.php`        | Interledger connection settings (env-overridable)                                         |
| `rafiki_webhook.php`       | Receives Rafiki webhooks; resolves payments that settle after checkout                    |
| `check_rafiki.php`         | Interledger diagnostics. Delete before deploying publicly.                                |
| `lib_rates.php`            | Rate layer: live ECB fiat + hand-set crypto, plus the cron entry point                    |
| `header.php`               | Shared branded page header (logo + Home button)                                          |
| `crypto_rates.php`         | Hand-maintained crypto prices, edit this to update BTC/ETH/etc.                          |
| `refresh_rates.bat`        | Windows: refresh rates once (double-click) or via Task Scheduler                          |
| `install_rates_task.bat`   | Windows: registers the hourly refresh task. Run once.                                     |
| `check_rates.php`          | Diagnostic page for rate problems. Delete before deploying publicly.                       |
| `reconcile.php`            | Staff dashboard: unmatched/manual payments to allocate, overdue list with reminders      |
| `lookup.php`               | Search a student/payee by number/name/email, shows outstanding + history                |
| `add_student.php`          | Manually add a student or external payee                                                 |
| `send_reminder.php`        | Emails a reminder for an overdue payment link                                            |
| `login.php` / `logout.php` | Staff authentication                                                                     |
| `create_staff.php`         | CLI script: `php create_staff.php <username> <full_name> <password>`                     |
| `agent.php`                | AI agent backend, answers "what do I owe" using only that student's own DB data         |
| `ask.php`                  | Student-facing chat UI for the AI agent                                                  |
| `index.php`                | Home page linking staff and student tools                                                |
| `index.css`                | Shared styling, extends FolkSplitter's original stylesheet                               |

## Exchange rates

Rates are pulled from two different areas. This is due to only limitations of time and knowledge. 

### Fiat, Frankfurter (live)

[Frankfurter](https://frankfurter.dev) was the API of choice as it allows for free, unlimited service that can exchange currency and rates. Only issue is that because of this it is extremely limited to the currencies. Unfortunately meaning we had to hardcode crypto currencies due to lack of API capability.   


### UniPay Flow

1. **Create receiver** on the union's wallet: a remote incoming payment, effectively an invoice.
2. **Create quote** from the sender's wallet to that receiver. Rafiki calculates the debit,
   including conversion and network fees.
3. **Create outgoing payment** against the quote.
4. **Poll** briefly, then let the webhook resolve anything still settling.


### Settlement asset

Fees are denominated in AUD; Rafiki settles in `RAFIKI_ASSET_CODE`. **The Local Playground
seeds USD**, so `createPayment()` converts the AUD fee into the settlement asset before
creating the receiver. Without that step an A$45 fee would collect US$45.

For production, add an AUD asset to your Rafiki instance and set `RAFIKI_ASSET_CODE=AUD`, so
no conversion happens at all and the ILP quote is the only conversion in the system.

### Configuration

Everything lives in `rafiki_config.php`, and every value can be overridden by an environment
variable of the same name. Defaults match the Local Playground:

```
RAFIKI_MODE=live                  # 'stub' fabricates payments if the playground is down
RAFIKI_SENDER_HOST=http://localhost:3001
RAFIKI_RECEIVER_HOST=http://localhost:4001
RAFIKI_ADMIN_SECRET=...           # Local Playground default is PUBLIC; replace for real use
RAFIKI_ASSET_CODE=USD
RAFIKI_UNION_WALLET_ADDRESS=https://happy-life-bank-backend/accounts/asmith
```


## Setting up staff login

```
php create_staff.php admin "Jane Smith" mypassword123
```

Then log in at `login.php`. Every staff-only page (`staff_generate.php`, `reconcile.php`, `lookup.php`, `add_student.php`) checks `$_SESSION['staff_id']` and redirects to `login.php` if not signed in.



## AI agent (`agent.php` / `ask.php`)

As for now, the AI agent is not fully functionally due to time limitations but something to consider in the future


```
export ANTHROPIC_API_KEY=sk-ant-...
```


