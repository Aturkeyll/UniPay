# WSU Payments — built on FolkSplitter

Extends [Aturkeyll/FolkSplitter](https://github.com/Aturkeyll/FolkSplitter)'s form → checkout
pattern into a staff-generated link → student checkout → Interledger payment flow for
student union/society/club/event fees.

## Setup

1. `mysql -u root -p < schema.sql` to create the database and tables.
2. Edit `db.php` with your real DB credentials.
3. Import your student roster into the `students` table (adapt the `students.json`
   generator we built earlier into an INSERT loop, or use `add_student.php` one at a time).
4. Serve the folder with PHP's built-in server for local testing:
   ```
   php -S localhost:8000
   ```
5. Visit `staff_generate.php` to create your first payment link (you'll need a `staff`
   row and basic login — `login.php` isn't included here, just the session check;
   for the hackathon demo you can seed `$_SESSION['staff_id'] = 1;` directly to skip auth).

## File map

| File | Role |
|---|---|
| `schema.sql` | Full DB schema: students, payees, items, staff, payment_links, transactions, audit_log |
| `db.php` | PDO connection + `logAction()` audit helper |
| `staff_generate.php` | Staff picks student + item + amount, locks fields, generates a unique link |
| `pay.php` | Student-facing checkout — locked/prefilled fields, currency picker, Pay Now |
| `get_quote.php` | Backend endpoint: gets an Interledger Open Payments quote |
| `process_payment.php` | Backend endpoint: executes the payment, records the transaction |
| `lib_openpayments.php` | Interledger integration layer — **currently stubbed**, see below |
| `reconcile.php` | Staff dashboard: unmatched/manual payments to allocate, overdue list with reminders |
| `lookup.php` | Search a student/payee by number/name/email — shows outstanding + history |
| `add_student.php` | Manually add a student or external payee |
| `send_reminder.php` | Emails a reminder for an overdue payment link |
| `login.php` / `logout.php` | Staff authentication |
| `create_staff.php` | CLI script: `php create_staff.php <username> <full_name> <password>` |
| `agent.php` | AI agent backend — answers "what do I owe" using only that student's own DB data |
| `ask.php` | Student-facing chat UI for the AI agent |
| `index.php` | Home page linking staff and student tools |
| `index.css` | Shared styling, extends FolkSplitter's original stylesheet |

## Setting up staff login

```
php create_staff.php admin "Jane Smith" mypassword123
```
Then log in at `login.php`. Every staff-only page (`staff_generate.php`, `reconcile.php`,
`lookup.php`, `add_student.php`) checks `$_SESSION['staff_id']` and redirects to `login.php`
if not signed in.

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
- Overdue detection and reminder emails
- Reconciliation queue for unmatched/manual payments

**Stubbed (clearly marked with `TODO` in `lib_openpayments.php`):**
- `getQuote()` uses a hardcoded rate table instead of a real Open Payments `/quotes` call
- `createPayment()` fabricates a successful payment instead of driving the real
  incoming/outgoing payment + interactive grant flow
- There's no wallet address collection from the student yet — `$studentWalletPointer`
  in `process_payment.php` is a placeholder

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

## Known gaps to fill before the demo

- No email service wired up (`mail()` in `send_reminder.php` needs a real SMTP/API config
  — most shared hosts don't enable PHP's `mail()` by default, so swap in SendGrid/Postmark)
- Currency/crypto rates in `lib_openpayments.php` are illustrative, not live
- `agent.php` calls the Anthropic API directly with `curl` for simplicity — fine for a
  hackathon demo, consider basic rate-limiting if you expose this publicly
- No CSRF tokens on the staff forms — add if you have time, not critical for a demo
