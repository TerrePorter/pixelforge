# Pixelforge image generator

A Laravel 13 + Inertia 3 + Vue 3 app where people sign in, generate images through the
[Pixelforge](https://pixelforge.promptedit.dev/docs) API, and are charged exactly what each
image cost plus a 25% markup. Built for the PromptEdit take-home assessment.

**Deployed app:** _to be added_

## What it does

- **Generate:** write a prompt, choose a size, quality and up to 4 reference image URLs, and
  see the cost range live before submitting. The page shows every generation with its status
  (waiting, queued, generating, done, failed), estimated and actual cost, and image. It
  updates itself while anything is running.
- **Exact billing:** each succeeded generation is charged Pixelforge's cost for its real
  token usage, plus 25%. Failed generations are free. Nothing is ever charged twice.
- **Webhooks:** signed Pixelforge webhooks are verified, stored once and applied in any order.
  Polling takes over when webhooks don't arrive.
- **Balances (stretch):** a credit balance in the header, fake "Buy credits" packs
  ($1 / $5 / $20) with a ledger history, and generating is blocked when the worst-case price
  doesn't fit the balance.
- **Admin report (stretch):** credit spend, provider cost and profit per UTC day and per user,
  compared day by day with Pixelforge's `GET /v1/usage`, to the nanodollar.

## Key decisions

### Money is integer nanodollars

All amounts are stored as `BIGINT` nanodollars (1 USD = 1,000,000,000). Pixelforge reports
`cost_usd` with exactly 9 decimals, so nanodollars hold every amount it can produce with no
rounding, and our totals can be compared with `/v1/usage` exactly. Rates like `"12.50"` are
parsed from strings into integers; floats are never used for money
([`app/Support/Money.php`](app/Support/Money.php)).

- **Provider cost** = Σ tokens × rate ÷ 1,000,000, summed in integers. It is exact for any
  rate with up to 3 decimals (Pixelforge publishes 2).
- **User charge** = provider cost × 1.25, **rounded up** to the next nanodollar. The
  rounding is always in our favour and always less than one nanodollar. The tests check
  `charge ≥ cost × 1.25` and `charge < cost × 1.25 + 1 n$`.
- **Display** rounds to 4 decimals (`$0.0863`); hovering shows the exact 9-decimal value.

### The estimate is a range, and its maximum is what we hold

Output tokens and reference-image tokens are known up front. Text tokens are only estimated
(`ceil(characters / 4)`, ±15%) and reasoning tokens vary by quality, so the form shows a
**min–max range**. The maximum is the real ceiling, so it's what we say ("never more than
$X") and what we reserve against the balance. The browser computes the estimate live from
`GET /v1/pricing`, cached for an hour with a bundled fallback; the server recomputes it on
submit, and that is the number recorded.

The **actual** cost always uses the usage and the `rates` on the finished generation (fixed
when Pixelforge created it), never the current price list.

### One place changes status and money

[`GenerationStateApplier`](app/Services/GenerationStateApplier.php) is the only code that
changes a generation's status or cost. Webhooks, polling and the create response all pass it
Pixelforge's generation object. It:

- locks the generation row,
- only moves status forward (`pending → queued → processing → succeeded | failed`) and never
  leaves a finished state, so a late `processing` after `succeeded` is ignored,
- records money only on the transition into a finished state.

On top of that, the ledger table has a unique `(generation_id, type)` index, so the database
itself refuses a second hold, release or charge for the same generation.

### Submitting through a queued job

Pixelforge allows 3 running generations per account, 60 requests a minute and 500 a day, and
sometimes returns `503 overloaded`. The controller saves the generation and its hold, then
[`SubmitGeneration`](app/Jobs/SubmitGeneration.php) sends it:

- **Every attempt uses the same `Idempotency-Key`** (the generation's own UUID), so a retry
  can never create a second billable generation.
- `metadata.generation_id` lets a webhook find our row even if it arrives before the create
  request returns.
- Errors are sorted three ways ([`PixelforgeException`](app/Services/Pixelforge/PixelforgeException.php)):
    - **Busy** (rate limit, concurrency limit, overloaded): nothing was created, so retry
      after `Retry-After`. The card says why it's waiting.
    - **Uncertain** (timeout, bare 5xx): the generation may exist. Retry with the same key,
      and **never** mark it failed.
    - **Rejected** (content policy, validation, daily limit): fail it for free with a clear
      message.
- If our own records show 3 generations running, the job waits without calling Pixelforge,
  rather than spending a request on a guaranteed 429.
- `internal_error` failures are not resubmitted automatically: that would be a new billable
  generation the user didn't ask for. The card offers "Try again" instead.

### Webhooks plus polling

- [`PixelforgeWebhookController`](app/Http/Controllers/PixelforgeWebhookController.php)
  checks `Pixelforge-Signature` (HMAC-SHA256 of `"<t>.<raw body>"`, keyed with the full
  `whsec_…` secret, compared in constant time, rejected if more than 5 minutes old). It
  stores each `evt_` id once, so a redelivery is acknowledged and skipped. The route has no
  login or CSRF, because Pixelforge doesn't follow redirects.
- [`generations:reconcile`](app/Console/Commands/ReconcileGenerations.php) runs every 10
  seconds. It polls generations that have had no update for `PIXELFORGE_POLL_AFTER_SECONDS`
  and applies the result exactly like a webhook, and it re-dispatches stuck pending ones
  (the submit job is unique per generation, so this never duplicates work). It stops early
  when Pixelforge says it's busy. The app works fully with no webhook URL at all.
- The browser polls **our** server, never Pixelforge, using Inertia's `rest` mode so slow
  responses can't pile up.

### Balances

Every credit movement is a row in `credit_transactions` (purchase, hold, hold release,
charge), and `users.balance_nanos` is updated in the same transaction
([`Ledger`](app/Services/Ledger.php)). A hold takes the worst case out of the balance while a
generation runs, so "can they afford it" is one comparison against the balance. Submitting
locks the user row, so two submits at once can't spend the same credits. New users start at
$0; there's no welcome bonus, because it would look like credits nobody paid for.

### Admin report

[`UsageReport`](app/Services/Reports/UsageReport.php) counts succeeded generations on the
**UTC day they completed**, because that's the day Pixelforge bills them. For each day it
compares our provider cost, generation count and token totals with `GET /v1/usage` and marks
the day ✓ or ⚠. Days that only Pixelforge knows about still appear. If Pixelforge can't be
reached, the page shows our own numbers and says so.

## Running it locally

Requirements: PHP 8.3+, Composer, Node 22.18+ or 24.11+, and a Pixelforge account with an API
key.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
npm run build
```

Then set the Pixelforge values in `.env`:

| Variable                        | Purpose                                                                                                                      |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `PIXELFORGE_API_KEY`            | API key. Use a separate key for each environment.                                                                            |
| `PIXELFORGE_WEBHOOK_SECRET`     | That key's signing secret, including the `whsec_` prefix.                                                                    |
| `PIXELFORGE_POLL_AFTER_SECONDS` | How long a generation can go without an update before it is polled. Use about 5 locally without webhooks, 120 in production. |
| `PIXELFORGE_BASE_URL`           | Defaults to `https://pixelforge.promptedit.dev/v1`.                                                                          |

The app needs **three processes**. Without the queue worker, generations are never sent;
without the scheduler, nothing is polled.

```bash
php artisan serve
php artisan queue:work
php artisan schedule:work
```

**Webhooks locally:** Pixelforge can't reach localhost, so either leave the key's webhook URL
empty and rely on polling, or run a tunnel and set the webhook URL to
`https://<tunnel-host>/webhooks/pixelforge`:

```bash
cloudflared tunnel --url http://localhost:8000
```

**Admin access:** `php artisan users:admin you@example.com` (add `--revoke` to remove it).

## Deploying

Any host that runs Laravel works. You need:

- a database (Postgres recommended), and `php artisan migrate --force` on deploy,
- a **queue worker** (`php artisan queue:work`) and the **scheduler** (`schedule:run` every
  minute; the reconcile task repeats every 10 seconds within that minute),
- a **separate Pixelforge API key** for production, with its webhook URL set to
  `https://<your-domain>/webhooks/pixelforge` and its secret in `PIXELFORGE_WEBHOOK_SECRET`.
  Because `/v1/usage` is reported per key, this is also what makes the admin report match
  Pixelforge exactly.

### On a server (EC2 or any VM)

[`deploy/`](deploy/) has the server configs. Each file says where it goes and what to change
for Ubuntu or Amazon Linux / RHEL; replace `your-domain` and `/var/www/pixelforge` in them.

| File                                       | What it is                                                              |
| ------------------------------------------ | ----------------------------------------------------------------------- |
| `deploy/nginx/pixelforge.conf`             | nginx site with PHP-FPM (use this **or** Apache)                        |
| `deploy/apache/pixelforge.conf`            | Apache httpd virtual host with PHP-FPM; rewrites via `.htaccess`        |
| `deploy/supervisor/pixelforge-worker.conf` | Queue worker under supervisor (use this **or** systemd)                 |
| `deploy/systemd/pixelforge-worker.service` | Queue worker as a systemd service (Amazon Linux 2023 has no supervisor) |
| `deploy/cron/pixelforge`                   | Scheduler entry for `/etc/cron.d`                                       |
| `deploy/deploy.sh`                         | Redeploy: pull, install, build, migrate, cache, restart the worker      |

First setup, on Ubuntu 24.04 (a t3.small or larger, ports 80 and 443 open, a domain
pointing at the instance's Elastic IP):

1. Install PHP 8.3 with FPM and the `pgsql`, `mbstring`, `xml`, `curl`, `bcmath`, `intl`
   and `zip` extensions, Composer, Node 24, Postgres, and nginx or Apache.
2. Clone the repo to `/var/www/pixelforge`, copy `.env.example` to `.env`, run
   `php artisan key:generate`, and set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`,
   the `DB_*` values and the `PIXELFORGE_*` values.
3. Give PHP-FPM's user and yours a shared group with write access to everything the app
   writes, including `database/` if you use SQLite (SQLite writes a journal file next to the
   database, so the folder needs write access too, not just the file; without it sessions
   fail to save and every form returns 419 Page Expired):
   `chown -R $USER:www-data storage bootstrap/cache database`,
   `chmod -R g+rwX storage bootstrap/cache database` and
   `find storage bootstrap/cache database -type d -exec chmod g+s {} +`.
   Then make PHP-FPM create group-writable files: `systemctl edit php8.3-fpm` (`php-fpm` on
   Amazon Linux), add `[Service]` and `UMask=0002`, and restart it. Without this, `artisan`
   commands run as you can't clear or rewrite files PHP-FPM created.
4. Install the web server config, the worker (supervisor or systemd) and the cron file, then
   get a certificate with `certbot --nginx` or `certbot --apache`. In the worker and cron
   files, set the user to PHP-FPM's user (`user =` in its pool config).
5. Run `./deploy/deploy.sh`. Run it again for every later deploy.
6. Register, then `php artisan users:admin you@example.com`.

On Amazon Linux 2023 the steps are the same, with these differences: PHP-FPM runs as
`apache` even when nginx serves the site, and there is no `www-data` group. Create it and
add both users before step 3: `sudo groupadd www-data` and
`sudo usermod -aG www-data apache` and `sudo usermod -aG www-data $USER` (then log in
again). Use the systemd worker, since there is no supervisor package, and install cron with
`sudo dnf install -y cronie && sudo systemctl enable --now crond`.

## Tests

```bash
php artisan test     # 175 Pest tests
composer test        # Pint, PHPStan (level 7) and the tests
npm run check        # lint and format
npm run types:check  # vue-tsc
```

The tests never call the real API (`Http::preventStrayRequests()`). They cover, among other
things: the docs' worked examples to the nanodollar ($0.373875 worst case, $0.169080 usage),
markup rounding, duplicate and out-of-order webhooks, webhooks that arrive before the create
response, tampered and stale signatures, every Pixelforge error code on submit, timeouts never
being treated as failures, balance checks, and the admin report against matching and
mismatching `/v1/usage` responses. One end-to-end test runs a submission through the real
database queue and a worker.

To try the failure handling by hand, use Pixelforge's prompt keywords: `[fail]`, `[slow]`,
`[duplicate]`, `[blocked]`, `[overloaded]` and `[succeed]`.

## Code map

| Path                                                   | What it is                                                    |
| ------------------------------------------------------ | ------------------------------------------------------------- |
| `app/Support/Money.php`                                | Nanodollar value object: parsing, markup, formatting          |
| `app/Services/Pixelforge/Pricing/`                     | Price list, estimates and actual cost                         |
| `app/Services/Pixelforge/PixelforgeClient.php`         | Thin HTTP client; every error becomes a `PixelforgeException` |
| `app/Services/GenerationStateApplier.php`              | The only place status and money change                        |
| `app/Services/Ledger.php`                              | Holds, releases, charges and purchases                        |
| `app/Jobs/SubmitGeneration.php`                        | Sends a generation, with retries and an idempotency key       |
| `app/Http/Controllers/PixelforgeWebhookController.php` | Signed, deduplicated webhooks                                 |
| `app/Console/Commands/ReconcileGenerations.php`        | Polling fallback and resubmission                             |
| `app/Services/Reports/UsageReport.php`                 | Admin report and `/v1/usage` comparison                       |
| `resources/js/lib/pricing.ts`                          | Browser copy of the estimate, for the live preview            |
| `resources/js/pages/`                                  | `Generate`, `Credits`, `admin/Report` and the auth pages      |

## What I'd do next

- Push status changes to the browser (Laravel Reverb) instead of polling.
- Paginate the generation history, which currently shows the latest 50.
- Run the test suite on Postgres in CI as well as SQLite.
- Store images ourselves, since Pixelforge's URLs expire after 24 hours.
- Real payments for credits, with refunds through the same ledger.
