# UniPay

Student fee payments over Interledger. Staff generate a payment link, the student opens it,
picks a currency, and pays. Money moves through [Rafiki](https://github.com/interledger/rafiki).

Built for the WSU x Interledger Hackathon, Track 1. Started from the form/checkout pattern in
[FolkSplitter](https://github.com/Aturkeyll/FolkSplitter).

It handles student union fees, club fees, trips and events, library fines, and one-off dues.
Students can also pay something that has no link yet, and staff match it up afterwards in the
reconciliation queue. There's an AI assistant that answers "what do I owe" in plain English.

## Requirements

| | Version | Why |
|---|---|---|
| PHP | 7.3+ (8.0+ preferred) | Needs `pdo_mysql`, `curl`, `mbstring` |
| MariaDB or MySQL | 10.4+ / 8.0+ | |
| Docker | any recent | Only for Rafiki |
| Node.js | see Rafiki's `.nvmrc` | Only for Rafiki |

Rafiki is optional. Without it UniPay runs in stub mode: the whole app works, links and
reconciliation and everything else, but payments are fabricated and nothing actually moves.
Fine for working on the UI, useless for a demo.

## Install on Linux

### 1. Rafiki

Clone it somewhere. The submodule matters, the build breaks in confusing ways without it.

```bash
git clone --recurse-submodules https://github.com/interledger/rafiki.git ~/Projects/rafiki
cd ~/Projects/rafiki
nvm install && nvm use
corepack enable
pnpm i
```

If you already cloned without `--recurse-submodules`:

```bash
git submodule update --init --recursive
```

You need to be in the `docker` group or every Docker call needs sudo, which breaks pnpm:

```bash
sudo usermod -aG docker $USER
newgrp docker
```

### 2. UniPay

```bash
bash /home/mint/UniPay/run.sh
```

That does the rest: apt packages, database, migrations, exchange rates, the hourly cron, the
Rafiki playground, and the web server on port 8000. First run takes a while because it pulls
Docker images. Then open http://localhost:8000/.

Make yourself a staff account:

```bash
php /home/mint/UniPay/create_staff.php admin "Your Name" hunter2
```

Re-running `run.sh` is safe. It checks before it changes anything, so mostly it just tells
you what's already fine.

```
run.sh --stop        stop the server and the playground
run.sh --no-rafiki   skip Rafiki, run in stub mode
run.sh --reset-db    drop and recreate the database, destroys data
```

Paths aren't hardcoded: `UNIPAY_DIR=`, `RAFIKI_DIR=`, `UNIPAY_PORT=`.

### If you'd rather do it by hand

```bash
sudo apt install php-cli php-mysql php-curl php-mbstring mariadb-server

sudo mysql <<'SQL'
CREATE DATABASE wsu_payments CHARACTER SET utf8mb4;
CREATE USER 'unipay'@'127.0.0.1' IDENTIFIED BY 'pick-something';
GRANT ALL ON wsu_payments.* TO 'unipay'@'127.0.0.1';
SQL

mysql -u unipay -p -h 127.0.0.1 < schema.sql
echo -n 'pick-something' > .db_password && chmod 600 .db_password

php lib_rates.php                          # fetch exchange rates
cd ~/Projects/rafiki && pnpm localenv:compose up -d
cd /home/mint/UniPay
PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8000
```

`db.php` picks up `.db_password` on its own, so CLI scripts and cron work without exporting
anything.

## Install on Windows (XAMPP)

Put the files in `htdocs`, import `schema.sql` in phpMyAdmin, then run `install_rates_task.bat`
once to register the hourly rate refresh with Task Scheduler.

The thing that catches everyone: Windows PHP ships without a CA bundle, so every HTTPS call
fails with cURL error 60 and the currency picker just says "unavailable". Fix it once in
`C:\xampp\php\php.ini`:

```ini
curl.cainfo = "C:\xampp\apache\bin\curl-ca-bundle.crt"
openssl.cafile = "C:\xampp\apache\bin\curl-ca-bundle.crt"
```

Some builds ship the bundle at `C:\xampp\php\extras\ssl\cacert.pem` instead. Restart Apache
after.

Running Rafiki on Windows means Docker Desktop and WSL2. Doable, not fun. The Linux route is
easier.

## Check it works

Three diagnostic pages. Each one tells you which step failed rather than making you read logs.

```
/check_rates.php     exchange rates, PHP config, cache state
/check_rafiki.php    Interledger config, signature self-test, wallet addresses
/check_payment.php   replays the payment INSERT in a rolled-back transaction
/repair_db.php       applies any missing schema changes
```

`/check_rafiki.php?pay=1&amount=1.00` pushes a real payment through the playground end to end.

**Delete all four before this goes anywhere public.** They print file paths and schema. They're
in `.gitignore` already, but the copies on disk are still live pages.

## How payments work

Four steps through Rafiki's Admin GraphQL API:

1. Create a receiver on the union's wallet. Effectively an invoice.
2. Create a quote. Rafiki works out what the sender must debit, including conversion and fees.
3. Create the outgoing payment against that quote.
4. Wait. It settles asynchronously.

Requests are signed with HMAC-SHA256 over a canonicalised JSON body, same scheme as Rafiki's
own Bruno collection. That's in `lib_rafiki.php`.

### Two rates, and which one wins

There are two sources of currency conversion and they won't agree exactly.

`lib_rates.php` gives the student a figure before they commit. It's instant, no network call,
so the currency picker stays responsive. Rafiki's quote is what actually moves money.

**Rafiki's number wins.** The estimate gets stored in `fx_rate` and `rate_source` purely as a
record of what the student was shown, because that matters if they query the amount later.
Applying the estimate's rate on top of Rafiki's amounts converts twice. That's the classic bug
in this integration and it's easy to reintroduce.

Also worth knowing: debit and receive amounts differ. The union receives exactly the fee and
the network fee lands on the payer, so a 45.00 fee typically debits around 45.90.

### Settlement asset

Fees are in AUD. Rafiki settles in whatever `RAFIKI_ASSET_CODE` says, and the Local Playground
seeds **USD**. So `createPayment()` converts the AUD fee into the settlement asset before
creating the receiver. Without that an A$45 fee would quietly collect US$45.

For anything real, add an AUD asset to your Rafiki instance and set `RAFIKI_ASSET_CODE=AUD`.
Then there's no conversion at all and the ILP quote is the only one in the system.

### Webhooks

Rafiki settles asynchronously, so a payment can still be `SENDING` when the student's browser
finishes. Those get stored as `pending`. `rafiki_webhook.php` resolves them later: marks the
transaction complete and closes the link, or reopens the link on failure so they can retry.

Add this to `localenv/cloud-nine-wallet/.env` and restart the playground:

```
WEBHOOK_URL=http://172.17.0.1:8000/rafiki_webhook.php
```

That IP is the `docker0` bridge gateway. `run.sh` prints yours if it's different.
`host.docker.internal` does not exist on Linux Docker, and `localhost` from inside a container
is the container.

Without webhooks nothing breaks, but slow payments sit in the reconciliation queue until
someone deals with them by hand.

## Exchange rates

Fiat comes from [Frankfurter](https://frankfurter.dev), which is ECB reference data. No API
key, no signup, no quota. Cron fetches it hourly, the web only ever reads the cache, so nobody
waits on a third party mid-checkout.

Coverage is the ECB list, 31 currencies. USD, EUR, GBP, CNY, INR, IDR, JPY, KRW, MYR, PHP,
SGD, THB are all there. VND, NPR, BDT, PKR, LKR and the Gulf currencies are not, which is a
real gap for a WSU cohort. If a student's currency isn't listed it won't appear in the picker.

ECB publishes once per working day around 16:00 CET, so a Sunday quote uses Friday's rate.
That's correct behaviour, not staleness.

### Crypto is manual

Frankfurter is fiat only. Crypto prices live in `crypto_rates.php` and you maintain them:

```php
'as_of' => '2026-08-26',
'rates' => [
    'BTC' => ['name' => 'Bitcoin', 'aud_price' => 98500.00],
],
```

Enter the AUD price of one coin, the number you'd read off an exchange. The code inverts it.
Typing the reciprocal by hand is how a misplaced zero becomes a 10x mispricing.

Edits take effect on the next page load. No cron, no restart.

Nothing updates these for you. Crypto moves 10% in a day, so `MANUAL_RATES_MAX_AGE_DAYS`
(default 3) refuses crypto quotes once `as_of` goes stale, and the cron warns you the day
before. The checkout labels them "indicative rate, set manually on <date>" so students aren't
shown a hand-typed number as though it were live.

**The prices in the file right now are made up.** Replace them before demoing.

## Nothing falls back

If rates are unavailable, or the cache is stale, or the manual crypto table has expired, the
checkout refuses to quote and says so. It does not guess. Refusing is correct; inventing a
rate and charging someone on it is not.

Same for Interledger. If Rafiki is unreachable you get "the payment network is unavailable,
you have not been charged" rather than a fabricated success.

## Configuration

Everything in `rafiki_config.php`, all overridable by environment variable. Defaults match the
Local Playground.

```
RAFIKI_MODE=live                  # 'stub' fabricates payments
RAFIKI_SENDER_HOST=http://localhost:3001
RAFIKI_RECEIVER_HOST=http://localhost:4001
RAFIKI_ADMIN_SECRET=...           # playground default is PUBLIC, replace for real use
RAFIKI_ASSET_CODE=USD
RAFIKI_UNION_WALLET_ADDRESS=https://happy-life-bank-backend/accounts/asmith
```

The default admin secret is the one published in Rafiki's own repo. It's fine on localhost and
nowhere else.

## When it breaks

**"Currency conversion unavailable"** means cron hasn't run or can't reach Frankfurter. Run
`php lib_rates.php` and read what it says. On Windows it's almost always the CA bundle.

**"Payment could not be completed"** is the generic message. From localhost you get the real
exception instead. If you're seeing the generic one you're not on localhost. Usually it's a
missing schema change, so run `/repair_db.php`.

**Access denied for user 'root'** on Linux. MariaDB root uses `unix_socket`, so it can't
connect over TCP at all. Run `run.sh` once to create the `unipay` user.

**HTTP 301 from Frankfurter** means the API moved and your `lib_rates.php` predates the fix.
It's `api.frankfurter.dev/v1` now.

**Rafiki 401** is a rejected signature. Check `RAFIKI_ADMIN_SECRET`, the tenant ID, and your
system clock. The signature carries a timestamp and Rafiki rejects it if you've drifted.

**Student can't sign in and no email arrives.** Expected on a local box, `mail()` usually
isn't configured. From localhost the link is printed on the page instead. If you're hitting the
app on a LAN address rather than `localhost`, that shortcut is deliberately disabled: use
`http://localhost:8000` or configure a real mail transport.

**"Security token expired"** on a form means the session was recycled, usually because the
server restarted. Reload the page and resubmit.

**pnpm failing on Docker** means you're not in the `docker` group, or you added yourself and
haven't started a new login session.

## Pushing changes

```bash
bash push.sh "what changed"
```

It stages everything, refuses to continue if `.db_password`, a `.env` or anything resembling a
live API key is staged, commits, and pushes. Auth is easiest through the GitHub CLI:

```bash
sudo apt install gh
gh auth login
```

Without `gh`, git prompts for a username and password, and the password has to be a Personal
Access Token from https://github.com/settings/tokens with the `repo` scope. Your account
password will not work.

`rafiki_config.php` does contain the Local Playground admin secret. That value is published in
Rafiki's own repository, so committing it is not a leak, but never reuse it on a real instance.

## Files

| | |
|---|---|
| `run.sh` | Linux setup and launch. Start here. |
| `push.sh` | Commit and push, with a secret check first |
| `schema.sql` / `migrations/` | Database. `repair_db.php` applies migrations for you. |
| `db.php` | Connection and the audit logger |
| `index.php` | Home |
| `staff_generate.php` | Staff create payment links, choose which fields lock |
| `pay.php` | Student checkout |
| `get_quote.php`, `process_payment.php` | Checkout backend |
| `manual_payment.php` | Paying something with no link yet |
| `my_payments.php` | What a student owes |
| `ask.php`, `agent.php` | AI assistant, UI and backend |
| `reconcile.php` | Matching payments to items, overdue list |
| `lookup.php`, `add_student.php` | Staff tools |
| `lib_rafiki.php` | Rafiki client: HMAC signing, receiver/quote/payment |
| `rafiki_config.php` | Interledger settings |
| `rafiki_webhook.php` | Settlement callbacks |
| `lib_openpayments.php` | Keeps the estimate and the real quote apart |
| `lib_rates.php`, `crypto_rates.php` | Exchange rates |
| `lib_student_auth.php` | One-time email sign-in links, sessions, rate limiting |
| `lib_session.php` | Session hardening and CSRF |
| `student_login.php`, `student_logout.php` | Student sign-in |
| `header.php`, `index.css`, `LogoWname.png` | Branding |
| `check_*.php`, `repair_db.php` | Diagnostics. Delete before deploying. |

## The AI assistant

A signed-in student asks something like "what do I owe". The backend pulls only that student's
own rows, using the session rather than anything in the request body, hands that JSON to Claude,
and gets back a sentence. The model never touches the database. It can't leak another student's
data because it never receives it, no matter how the question is phrased.

```bash
export ANTHROPIC_API_KEY=sk-ant-...
```

Without a key it falls back to a plain formatted list, so the page still works in a demo.

## Signing in

Students never type a password. They enter their student number, we email a one-time link to
the address already on file, and following it starts a session that lasts an hour.

Nothing is revealed before that link is used, so a student number on its own gets an attacker
nothing. The response is identical whether the number exists or not, tokens are single-use and
expire in 15 minutes, and only a SHA-256 hash of each token is stored, so reading the database
does not hand over working links. Requests are rate limited to five per IP per fifteen minutes.

If `mail()` isn't configured, which is normal on a demo box, the sign-in link is printed on
screen instead. That only happens for requests from 127.0.0.1. From anywhere else it would be
handing out sessions to whoever typed a number.

Staff log in with a username and password as before. Failed attempts are throttled to ten per
IP per fifteen minutes and the error never says which field was wrong, because "no such user"
confirms which usernames exist.

## Security

What's covered:

Payment amounts are recomputed server-side from the link token, so editing values in devtools
achieves nothing. Payment link tokens are single use. Every quote shown is recorded, checked
for expiry, and consumed when paid, so a stale quote can't be replayed. Every POST form carries
a CSRF token verified with `hash_equals`, and session cookies are `HttpOnly`, `SameSite=Lax`,
`Secure` over HTTPS, with the session id rotated on login and every 30 minutes. Sending a
reminder is a POST, not a GET, so an image tag can't fire one. The AI assistant is scoped to
the signed-in student's own rows and never receives anyone else's data.

What's still true and you should know about:

**Students pay from a wallet UniPay operates, not their own.** This is the real remaining gap
and it isn't a bug to patch, it's the next piece of work. Real third-party wallets need the
full Open Payments client flow, described below.

**Rate limits are per IP** and stored in a table. Fine for coursework, trivially sidestepped by
anyone with a handful of addresses. Real deployment wants limits per account as well.

**Email delivery is PHP `mail()`.** Most hosts don't have it configured, and it doesn't
authenticate, so links land in spam. Swap in SendGrid or Postmark before anyone relies on it.

**No brute-force lockout on individual staff accounts**, only per IP.

Rafiki itself is explicit that it's meant to be run by regulated account servicing entities. A
student union is not one. This is a hackathon project.

## Moving to real Open Payments

The Admin API is the right tool while UniPay operates the sender's wallet. When students bring
their own, you need the client-side flow instead: GNAP grants with Ed25519 signatures rather
than HMAC, an interactive redirect, and a `payment_callback.php` for the return leg.

The four-step shape doesn't change, so `lib_openpayments.php` keeps its interface. Rafiki's
Bruno collection under `bruno/collections/Rafiki/Open Payments APIs/` has the exact requests.
