# PromptEdit × Pixelforge — Implementation Plan

Starting point: Laravel 13 + Inertia 3 + Vue 3 blank starter kit (Pest, Larastan, Pint, Wayfinder, Tailwind 4). No auth, no domain code, **not a git repo yet**. App runs in the Vagrant VM at `/home/vagrant/Code/_play/pixelforge.lan`.

Guiding rule from the brief: **money correctness > robustness > UX > polish.**

---

## 1. Key decisions

### Money: integer nanodollars

- All money is stored as `BIGINT` **nanodollars** (1 USD = 1,000,000,000 n$). Pixelforge reports `cost_usd` with exactly 9 decimals, so nanodollars match its numbers exactly.
- Provider cost is always exact in n$. Rates have 2 decimals, so `cost_n$ = tokens × rate_cents × 10`. Example: 6,450 output tokens × `40.00` → 6,450 × 4,000 × 10 = 258,000,000 n$ = $0.258.
- Parse rate strings with a string → integer parser, never floats (`"12.50"` → 1250 cents).
- **User charge = ceil(provider × 5 / 4)** in n$ (`intdiv(p*5 + 3, 4)`). Rounding up happens below one nanodollar and always in our favour. Document this.
- One small `Money`/`Nanodollars` value object handles parsing, ×1.25 markup and formatting. UI shows `$0.3739`, with full precision in a tooltip.

### Pricing source of truth

- **Estimate**: use `GET /v1/pricing` (no auth, cached for about 1 hour) and the docs' formula:
    - text = `ceil(mb_strlen(prompt)/4)` (worst case ×1.15, rounded up)
    - image = 765 × refs
    - output = table[quality][size] (exact)
    - reasoning = min/max by quality
- Record **estimate_min / estimate_max** for both provider cost and user price. The **max** is the "up to" figure and the balance hold.
- **Actual**: compute from the generation's own `usage` × `rates` (rates are fixed at creation). Never use current pricing for actual costs.
- The frontend recomputes the estimate live from the same pricing payload (passed as an Inertia prop). Count characters as Unicode code points (`[...str].length`) to match `mb_strlen`. The server recomputes on submit and its number is authoritative.

### Submission via a queued job (not inline)

Pixelforge allows **3 concurrent** generations per account, 60 req/min and 500/day, and it returns 503 `overloaded` at random. Submitting from a job lets us retry without making the user wait or re-click:

1. The controller validates input, locks the user row, checks the balance, creates the local `Generation` (status `pending`, `idempotency_key = uuid`), places a hold, then dispatches `SubmitGeneration`.
2. The job sends `POST /v1/generations` with `Idempotency-Key` set to the local uuid, so retries can never double-create. It also sends `metadata: {generation_id: <local id>}`.
3. Response handling:

| Response                                                 | Action                                                                     |
| -------------------------------------------------------- | -------------------------------------------------------------------------- |
| `202`                                                    | Store `provider_id`, apply the generation object (see §State)              |
| `429 concurrency_limit_exceeded` / `rate_limit_exceeded` | `release(Retry-After)`, status stays `pending` ("Waiting for a free slot") |
| `503 overloaded`                                         | Release with `Retry-After` plus backoff, capped number of attempts         |
| `429 daily_limit_exceeded`                               | Fail locally ("Daily provider limit reached"), release hold                |
| `400 content_policy_violation` / `422`                   | Fail locally with Pixelforge's message, release hold                       |
| `409 idempotency_key_reused`                             | Bug signal: log and fail                                                   |
| network error / 5xx                                      | Retry with backoff (safe because of the idempotency key)                   |

Final job failure (`failed()` hook) → mark failed and release the hold.

### State machine (one place, idempotent, monotonic)

`GenerationStateApplier::apply(Generation $g, array $remote)` is the **only** code that changes status or money. Webhooks, polling and the submit response all call it.

- It runs in a DB transaction with `lockForUpdate()` on the generation row.
- Status rank: `pending(0) < queued(1) < processing(2) < succeeded|failed(3)`. Ignore anything that does not move the rank forward. **Terminal states never change.** This handles out-of-order events, such as a late `processing` arriving after `succeeded`.
- On the move to `succeeded`: store usage tokens, rates, `image_url` and expiry, `provider_cost_n$` and `charge_n$`. Write a ledger **charge** and a **hold release**.
- On the move to `failed`: store the error and write a hold release. Cost is 0.
- Belt and braces: the `credit_transactions` table has a unique index on `(generation_id, type)`, so a charge **cannot** be written twice even if the rank check had a bug.

### Webhooks

`POST /webhooks/pixelforge`, excluded from CSRF, no auth:

1. Verify `Pixelforge-Signature: t=…,v1=…`:
    - Compute `hash_hmac('sha256', "$t.$rawBody", secret)` and compare with `hash_equals`.
    - Reject when `|now − t| > 300s`.
    - Use `$request->getContent()` (raw body).
    - Signing failure → `400`.
    - The HMAC key is the **full secret, including the `whsec_` prefix** (confirmed). Stored as `PIXELFORGE_WEBHOOK_SECRET`.
2. `INSERT … ON CONFLICT DO NOTHING` into `webhook_events` (unique `event_id`), storing the payload for auditing. If it is a duplicate, return `200` immediately.
3. Find the local generation by `data.object.metadata.generation_id` first, then by `provider_id`. A webhook can arrive **before** the POST response, so the metadata lookup matters. Fill in `provider_id` if it is missing.
4. `apply()` synchronously (fast, no external calls) and return `200` within 10s. `ping` or an unknown generation → `200` and log.

### Polling fallback (reconciliation)

- Scheduled `generations:reconcile` runs every minute. It fetches `GET /v1/generations/{id}` for non-terminal generations that have had no update for about 2 minutes (`[slow]` takes up to 90s). It respects the rate limit: it caps work per run and backs off on 429.
- It also picks up `pending` generations whose job died.
- The browser **polls our own app**, never Pixelforge: Inertia `usePoll` / partial reload `only: ['generations']` every 2–3s while any generation is non-terminal.

### Local development & webhooks

Pixelforge rejects localhost and private IPs as webhook URLs. It also doesn't follow redirects. In the dashboard, leaving a key's webhook URL empty stops webhooks for that key. That leaves two ways to work locally:

- **Tunnel (to test webhooks):** run a Cloudflare quick tunnel from inside the VM. It needs no account:
  `cloudflared tunnel --url http://localhost:80 --http-host-header pixelforge.lan`
  Set the local key's webhook URL to `https://<random>.trycloudflare.com/webhooks/pixelforge`. Expose or ngrok work the same way. The quick-tunnel URL changes on every run, so update the dashboard each time. The webhook route must answer directly (no auth redirect, no http→https or trailing-slash redirect, CSRF excluded), because redirects count as failed deliveries.
- **No webhook URL (polling only):** the app must still work fully. Polling therefore has to be a complete path in its own right, not just a fallback:
    - `PIXELFORGE_POLL_AFTER_SECONDS` sets how long a non-terminal generation can go without an update before we fetch it. Use ~5 locally with no webhooks and ~120 in production.
    - Locally, run the scheduler with `php artisan schedule:work` and schedule reconcile at sub-minute frequency (`everyFiveSeconds()`, with `withoutOverlapping()`).
    - Budget: 3 concurrent generations polled every 5s is ~36 req/min, under the 60 req/min limit, and the reconciler backs off on 429.
- Test both modes before deploying. The tests already exercise both paths, since the applier is shared.

### Balances (stretch, but designed in from the start)

- `credit_transactions` ledger: `user_id`, `generation_id?`, `type` (`purchase | hold | hold_release | charge`), signed `amount_n$`, timestamps.
- `users.balance_n$` is a cached value updated in the same transaction (ledger = audit, column = fast reads).
- Available = balance − open holds.
- Submit is blocked when available < estimate_max. The worst-case estimate should be an upper bound for the actual charge. If the actual ever exceeds it, charge the real amount anyway (it may go slightly negative) and log it.
- "Buy credits": fake purchase of $1, $5 or $20 → `purchase` row.
- New users get a small starting grant (e.g. $2) so the demo works immediately.

### Admin report (stretch)

- `users.is_admin` plus a `can:admin` gate.
- Groups data **by UTC day of `completed_at`**, because Pixelforge bills on the completion day, not the creation day. Only succeeded generations count.
- Two tables:
    - per day: generations, charged, provider cost, profit
    - per user × day: charged, provider cost, profit
- **Reconciliation column**: call `GET /v1/usage?start_date&end_date` (≤92 days, cached a few minutes). Show Pixelforge `cost_usd` and our provider cost next to each other, with a ✓ or Δ per day. Compare token totals as well.
- The deployed app uses its own API key, so its numbers match `/v1/usage` exactly.

### Auth

`laravel/fortify` (headless) with Inertia Login, Register and Logout pages. The brief only asks for sign-in, so skip email verification and 2FA.

---

## 2. Data model

**generations**

- `id`, `user_id` FK, `idempotency_key` uuid unique, `provider_id` nullable unique
- `status` enum: pending | queued | processing | succeeded | failed
- `prompt` text, `size`, `quality`, `reference_images` json
- `est_provider_min_n$`, `est_provider_max_n$`, `est_charge_min_n$`, `est_charge_max_n$`
- `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens` (nullable)
- `rates` json (as returned)
- `provider_cost_n$`, `charge_n$` (nullable until terminal, 0 on fail)
- `image_url`, `image_url_expires_at`, `error_code`, `error_message`
- `submitted_at`, `started_at`, `completed_at`, `last_synced_at`, timestamps
- indexes: (`user_id`, `created_at`), (`status`), (`completed_at`)

**webhook_events**: `id`, `event_id` unique, `type`, `provider_generation_id`, `payload` json, `delivery_attempt`, `processed_at`, timestamps

**credit_transactions**: `id`, `user_id`, `generation_id` nullable, `type`, `amount_n$` bigint, `meta` json, timestamps; unique(`generation_id`, `type`) where generation_id is not null

**users** + `balance_n$` bigint default 0, `is_admin` bool

Postgres in production (Laravel Cloud). SQLite locally is fine, but test the partial unique index and `lockForUpdate` on Postgres before submitting.

---

## 3. Code layout

```
app/Support/Money.php                    nanodollar value object (parse "12.50", markup, format)
app/Services/Pixelforge/Client.php       thin HTTP client (Http::baseUrl, token, timeout, typed exceptions)
app/Services/Pixelforge/Exceptions/*     RateLimited(retryAfter), ConcurrencyLimited, Overloaded, DailyLimit, Rejected(code,msg)
app/Services/Pixelforge/Pricing.php      fetch+cache /v1/pricing; estimate(prompt,size,quality,refs) → {min,max}; actual(usage,rates)
app/Services/Pixelforge/WebhookSignature.php  verify(rawBody, header, secret, now)
app/Services/GenerationStateApplier.php  the state machine + money postings
app/Services/Ledger.php                  hold/release/charge/purchase, balance locking
app/Jobs/SubmitGeneration.php
app/Console/Commands/ReconcileGenerations.php
app/Http/Controllers/{Generation,Webhook,Credits,Admin\Report}Controller.php
app/Http/Requests/StoreGenerationRequest.php
resources/js/pages/{Generate,History,Admin/Report,Auth/*}.vue
resources/js/lib/pricing.ts              mirror of the estimate formula
config/services.php → pixelforge.{key, webhook_secret, base_url}
```

Generate and History could be a single page: the composer on top, the live list below. That is better UX and needs fewer routes.

---

## 4. UX

- **Composer**:
    - prompt with character counter (max 4000)
    - size and quality as segmented buttons with short labels ("Square / Landscape / Portrait", "Low / Medium / High")
    - up to 4 reference URL inputs (add/remove)
- **Cost panel** that updates live, e.g. "Estimated **$0.12 – $0.16** · you'll be charged the actual cost after it finishes · failed generations are free". A collapsible breakdown shows the token lines. Below it: balance and "available after this".
- The submit button is disabled with a reason when the balance is too low ("Need $0.37, you have $0.20 — Buy credits").
- **History cards**:
    - status pill: Waiting for slot / Queued / Generating… with elapsed time / Done / Failed (+ reason)
    - estimate vs. actual
    - image thumbnail that opens full size; "Image expired" placeholder after `image_url_expires_at`
    - "Retry" on failed cards (creates a new generation with the same params)
- Errors in plain words: content policy, daily limit, provider busy (auto-retrying), generation failed (not charged).
- Header: balance + "Buy credits"; admin link for admins.

---

## 5. Build order (time-boxed)

| #   | Step                                                                                                                                                             | ~Time |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----- |
| 0   | `git init`, GitHub repo, `.env` keys, Pixelforge account + **local** API key, tunnel (cloudflared / Expose from the VM)                                          | 15m   |
| 1   | Fortify auth + layout                                                                                                                                            | 20m   |
| 2   | `Money` + `Pricing` (estimate & actual) + **unit tests first**: docs worst case = 373,875,000 n$; usage example 41/3300/2950 → 169,080,000 n$; markup rounding   | 30m   |
| 3   | Migrations, models, `Ledger`, `GenerationStateApplier` + tests (out-of-order, duplicate, terminal lock, failed = 0, unique charge)                               | 40m   |
| 4   | `Client`, `SubmitGeneration` job + tests with `Http::fake` for every error code                                                                                  | 30m   |
| 5   | Webhook endpoint + signature tests (valid, tampered, stale, duplicate event id, before-POST arrival)                                                             | 25m   |
| 6   | Generate/History page with live estimate and polling                                                                                                             | 45m   |
| 7   | Reconcile command + scheduler (configurable `PIXELFORGE_POLL_AFTER_SECONDS`, works with no webhook URL)                                                          | 15m   |
| 8   | Manual run against the real API with keywords `[fail]`, `[slow]`, `[duplicate]`, `[overloaded]`, `[blocked]`, 4 parallel submits (concurrency), dashboard resend | 20m   |
| 9   | Deploy to Laravel Cloud (Postgres, queue worker, scheduler), **separate prod API key** + webhook URL/secret, smoke test, leave sample generations                | 30m   |
| 10  | Stretch: balances UI + Buy credits (the ledger already exists)                                                                                                   | 30m   |
| 11  | Stretch: Admin report + `/v1/usage` reconciliation                                                                                                               | 45m   |
| 12  | README: decisions (nanodollars, ceil markup, estimate = upper bound, completion-day reporting, why jobs), how to run, test keywords                              | 15m   |

---

## 6. Test checklist (Pest)

- Pricing: docs worked example exact; every size/quality output; Unicode prompt counting; rates parsed from strings without floats.
- Markup: ceil behaviour; `charge ≥ provider × 1.25`, and less than 1 n$ above it.
- Applier:
    - succeeded twice → 1 charge
    - processing after succeeded → stays succeeded
    - failed → charge 0 and hold released
    - succeeded arriving with no prior queued/processing state → fine
- Webhook: bad signature 400; stale timestamp 400; same `evt_` twice → one row, one charge; two different events for the same terminal state → one charge; unknown generation → 200.
- Job: 429 concurrency → released with delay and the same idempotency key; 503 → retried; 400 policy → failed, hold released; replay (`Idempotent-Replayed`) handled as normal.
- Balance: insufficient → 422 and nothing created; concurrent submits cannot overspend (row lock).
- Admin: daily grouping by `completed_at` UTC; matches the faked `/v1/usage`.

---

## 7. Open questions to verify against the live API

1. ~~Is the `whsec_` prefix part of the HMAC key?~~ **Confirmed: yes, the full `whsec_…` string is the key.**
2. The `ping` event payload shape (make sure the handler does not crash on it).
3. Which `error.code` `[fail]` produces (`generation_failed` vs `internal_error`). Show the message either way.
4. Does `daily_limit_exceeded` return `Retry-After`? (Treat it as terminal for that request.)
5. Does a worst-case estimate always bound the actual cost? Log any overshoot, check after test runs.
6. The docs' example timestamps disagree (2025 vs 2026 epoch). Trust the ISO fields.

## 8. Choices to be ready to defend in the interview

- Why nanodollars instead of decimals, and why ceil for the markup.
- Why the estimate shown and held is the **upper bound**: users are never surprised upward.
- Why `internal_error` failures are not auto-resubmitted: that would be a new billable generation the user did not ask for. The user gets a one-click Retry instead.
- Why reports group by completion day: it matches Pixelforge's billing.
- Why there is a single state applier with row locks plus a DB unique constraint (defence in depth).
- Why the browser polls our app and not Pixelforge (rate-limit budget, keys stay server-side).
