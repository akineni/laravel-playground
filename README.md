# Laravel Playground

A sandbox for practicing backend engineering concepts, **one concept at a time**. Each concept lives behind its own set of routes/classes and gets its own section below (and its own commit) — nothing here is meant to compose into a "real" product.

## Concepts

| # | Concept | Status |
|---|---------|--------|
| 01 | [Concurrency Control — Optimistic vs Pessimistic Locking](#01--concurrency-control--optimistic-vs-pessimistic-locking) | ✅ Done |
| 02 | [Idempotency: Safe Retries with an Idempotency Key](#02--idempotency--safe-retries-with-an-idempotency-key) | ✅ Done |

---

## General Setup

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
```

Set your database credentials in `.env` (MySQL is assumed — see the note in Concept 01 on why that matters for locking demos):

```
DB_CONNECTION=mysql
DB_DATABASE=laravel_playground
DB_USERNAME=root
DB_PASSWORD=
```

```bash
php artisan migrate
composer run dev   # serves the app, queue worker, and Vite together
```

Each concept section below lists any extra seeding/setup it needs on top of this.

---

## 01 — Concurrency Control: Optimistic vs Pessimistic Locking

### The concept

Two requests read the same row, both pass validation against what they read, then both write back — the second write silently overwrites the first. This is the **lost update** problem. Two common fixes:

- **Pessimistic locking** — `SELECT ... FOR UPDATE` inside a transaction. The database physically locks the row, so a second concurrent request blocks until the first transaction commits, then re-reads the *correct*, already-updated value.
- **Optimistic locking** — no lock at read time. Instead, every row carries a `version` number. Writes are conditional: `UPDATE ... WHERE id = ? AND version = ?`. Whoever writes first bumps the version and wins; the second write matches zero rows (version has moved on) and is rejected with a conflict instead of clobbering the first write.

### The scenario

> An account has **₦5,000**. Two concurrent requests each try to withdraw **₦4,000**.
>
> Without protection: both reads see ₦5,000, both pass the "sufficient funds" check, both writes succeed — **₦8,000 withdrawn from a ₦5,000 balance.**

This repo implements the *same* withdrawal twice — once pessimistic, once optimistic — against an `accounts` table (`id`, `name`, `balance`, `version`), so both fixes can be compared side by side against the identical scenario.

### How each fix behaves here

| | Pessimistic (`withdraw-pessimistic`) | Optimistic (`withdraw-optimistic`) |
|---|---|---|
| Read | `SELECT ... FOR UPDATE` inside `DB::transaction()` | Plain read, no lock |
| Concurrent request B | Blocks until A's transaction commits, then reads A's updated balance | Reads the same stale balance as A |
| Write | Row is exclusively locked, so B's write is always fresh | `UPDATE accounts SET balance = balance - ?, version = version + 1 WHERE id = ? AND version = ?` |
| Outcome for the loser | Correctly rejected with **422 Insufficient funds** (it now sees the real, lower balance) | Rejected with **409 Conflict** (its expected `version` no longer matches — nothing was ever overwritten) |
| Result | Exactly one ₦4,000 withdrawal succeeds either way — no lost update |

Relevant code:

- `app/Services/Account/AccountService.php` — `withdrawPessimistic()` / `withdrawOptimistic()`
- `app/Repositories/Eloquent/AccountRepository.php` — `findForUpdate()` (the lock) and `withdrawIfVersionMatches()` (the conditional update)
- `app/Exceptions/InsufficientFundsException.php` (422), `app/Exceptions/ConflictException.php` (409)
- `app/Http/Controllers/v1/Account/AccountController.php` + `routes/accounts.php`

### Setup

Migrate (if you haven't already) and seed a demo account:

```bash
php artisan migrate
php artisan db:seed --class=AccountSeeder
```

This creates (or resets) an account called **"Demo Account"** with `balance = 5000.00`, `version = 0`, and prints its ID. Re-run the seeder anytime to reset it back to a clean state between test runs.

### Testing manually in Postman

1. Regenerate the docs/collection if you've changed anything: `php artisan scribe:generate`.
2. Import `public/docs/collection.json` into Postman. Endpoints are grouped under **"Concurrency: Optimistic vs Pessimistic Locking"**, with a subfolder per strategy. (The same run also writes a browsable HTML page to `public/docs/index.html` and an OpenAPI spec to `public/docs/openapi.yaml`.)
3. Hit these against your seeded account (defaults to ID `1`):

   | Method | Endpoint | Body |
   |---|---|---|
   | GET | `/api/v1/accounts/{account}` | — |
   | POST | `/api/v1/accounts/{account}/withdraw-pessimistic` | `{"amount": 4000}` |
   | POST | `/api/v1/accounts/{account}/withdraw-optimistic` | `{"amount": 4000}` |

4. Withdraw once, check the balance dropped to 1000. Withdraw again and it correctly fails with **422 Insufficient funds**. Re-seed to reset and try again.

Every response follows the same envelope:

```json
{
  "status": "success",
  "message": "Withdrawal successful (optimistic lock)",
  "data": { "id": 1, "name": "Demo Account", "balance": "1000.00", "version": 1 }
}
```

### Running the actual race

A single manual request can't show a race — you need two requests hitting the server *at the same time*. `php artisan serve` handles **one request at a time by default** (it's not multi-threaded), so the two requests fired by the simulate command would just queue up and run one after another — no race, no matter what the locking code does. Start the server with multiple workers first:

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload
```

- **`PHP_CLI_SERVER_WORKERS=4`** — forks 4 separate OS *processes* (not threads — each is a fully independent PHP interpreter with its own DB connection and no shared memory) that all listen on the same port and pick up requests in parallel. Only 2 are strictly needed to race the two requests this simulation fires; the rest is just spare capacity. Without this, the server processes everything sequentially and the race never happens.
- **`--no-reload`** — required for the line above to actually take effect. Laravel's file-watcher/auto-restart feature is incompatible with multi-worker mode; omit this flag and `serve` silently ignores `PHP_CLI_SERVER_WORKERS` (with a warning) and falls back to a single worker.

Then, in another terminal, fire the race:

```bash
# Uses/reseeds account #1 (or omit the ID to spin up a fresh throwaway account)
php artisan accounts:simulate-race 1 --strategy=pessimistic
php artisan accounts:simulate-race 1 --strategy=optimistic
```

Options: `--amount` (default 4000), `--balance` (starting balance, default 5000), `--base-url` (default `http://127.0.0.1:8000`).

**Expected output:**

| Strategy | Request A | Request B | Final balance |
|---|---|---|---|
| Pessimistic | 200 OK | 422 Insufficient funds (waited, then saw the real balance) | 1000.00 |
| Optimistic | 200 OK | 409 Conflict (version already moved on) | 1000.00 |

Either way, the command prints "No lost update: final balance matches the number of requests that actually succeeded." If you ever see a balance of 1000 with *both* requests reporting success (or any mismatch), that's the lost-update bug this concept exists to prevent.

### Automated tests

```bash
php artisan test --compact tests/Feature/Account/AccountWithdrawalTest.php
```

Covers both strategies' happy paths, insufficient-funds rejection, the version-conflict rejection mechanism directly at the repository level, and the `ConflictException → 409` HTTP wiring.

---

## 02 — Idempotency: Safe Retries with an Idempotency Key

### The concept

Locking (Concept 01) protects against two requests running at the *same instant*. It does nothing for the same request running *twice in a row* - which is exactly what happens when a client retries.

> A client calls `POST /withdraw` for ₦4,000. The server debits the account and sends back `200 OK` - but the response never arrives (timeout, dropped connection, a restarting load balancer). From the client's side the request *failed*, so it does the sensible thing and retries the exact same request.

Nothing was raced, no lock was violated - each request was handled correctly and sequentially. The bug is that "processed successfully" and "client received confirmation" are two separate events, and the network sits between them and can fail on its own. The account gets debited twice for one withdrawal the user meant to make once.

**The fix:** the client generates a unique key per logical operation (a UUID) and sends it as an `Idempotency-Key` header. The server remembers the outcome of the first request it sees for that key and, on a repeat, **replays the stored response instead of re-running the operation.**

### How it's implemented here

`App\Http\Middleware\EnsureIdempotencyKey` sits in front of `POST /accounts/{account}/withdraw-idempotent` (alias: `idempotent`) and wraps the whole request in a transaction:

1. **Require the header.** No `Idempotency-Key` → `400`.
2. **Fingerprint the request** (method + path + body, hashed) so a key can't silently be replayed against a *different* request.
3. **Claim the key.** Insert a row into `idempotency_keys` (`key` is unique) inside a `DB::transaction()`, then call the controller. Once it returns, save the response status/body onto that same row and commit - key and response become visible together, atomically.
4. **A duplicate arrives** (the insert hits the unique constraint - either a genuine race or a plain retry after the first one committed): lock that row with `lockForUpdate()` and read it.
   - Same fingerprint → **replay** the stored response (`Idempotency-Replayed: true` header).
   - Different fingerprint → **409 Conflict**, key reused for a different request.

The elegant part: `lockForUpdate()` here is the *exact same mechanism* `AccountRepository::findForUpdate()` uses for pessimistic locking in Concept 01 - just applied to a bookkeeping row instead of a balance. A concurrent duplicate doesn't race the original; it **blocks until the original's transaction commits**, then reads the finished result. Two duplicate requests arriving at literally the same millisecond are just as safe as one arriving an hour later.

**A subtlety worth calling out:** because the whole request (including the exception-to-JSON rendering Laravel does internally) happens inside that one transaction, *error* responses get memoized too - not just successes. Retry a request that failed with **422 Insufficient funds** using the same key, and you get that same 422 back, even if you've since topped up the balance. This matches how real idempotency keys behave (Stripe does the same): a key represents one specific logical attempt, errors included. To genuinely try again, use a **new** key.

Relevant code:

- `app/Http/Middleware/EnsureIdempotencyKey.php` - the whole mechanism
- `app/Models/IdempotencyKey.php` + `database/migrations/*_create_idempotency_keys_table.php`
- `app/Http/Controllers/v1/Account/AccountController.php` - `withdrawIdempotent()` (reuses `AccountService::withdrawPessimistic()` underneath; idempotency and locking are orthogonal, both apply here)
- `routes/accounts.php` - the `idempotent` middleware alias registered in `bootstrap/app.php`

### Testing manually in Postman

Same collection as Concept 01 (`php artisan scribe:generate` to regenerate). The endpoint is grouped under **"Idempotency (Retry Safety)"**. It requires the `Idempotency-Key` header - Postman won't add this for you, set it manually per request (any string works; a UUID is conventional).

| Method | Endpoint | Header | Body |
|---|---|---|---|
| POST | `/api/v1/accounts/{account}/withdraw-idempotent` | `Idempotency-Key: <uuid>` | `{"amount": 4000}` |

Send it once, note the balance drop. Send the *exact same request* (same key, same body) again - balance doesn't move, and the response carries `Idempotency-Replayed: true`. Change the amount but keep the same key - `409 Conflict`.

### Running the actual scenarios

As with Concept 01, a real demo needs the multi-worker dev server so concurrent requests can genuinely overlap:

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload
```

Then, in another terminal:

```bash
php artisan accounts:simulate-idempotent-retry --mode=sequential  # lost-response retry
php artisan accounts:simulate-idempotent-retry --mode=concurrent  # two requests, same key, same instant
php artisan accounts:simulate-idempotent-retry --mode=conflict    # same key, different payload
```

Options: `--amount` (default 4000), `--balance` (default 5000), `--base-url` (default `http://127.0.0.1:8000`), optional leading `{account}` argument to reuse an existing account.

**Expected output:**

| Mode | Request A | Request B | Final balance |
|---|---|---|---|
| `sequential` | 200 OK | 200 OK `[replayed]` | 1000.00 |
| `concurrent` | 200 OK (one of the two - whichever wins the insert) | 200 OK `[replayed]` (blocked on the row lock, then replayed) | 1000.00 |
| `conflict` | 200 OK | 409 Conflict | 1000.00 |

The command prints "Exactly one withdrawal took effect: retries were replayed, not reprocessed." If you ever see a balance that dropped by more than one withdrawal, that's the double-processing bug this concept exists to prevent.

### Automated tests

```bash
php artisan test --compact tests/Feature/Account/AccountIdempotencyTest.php
```

Covers the missing-header rejection, a first request completing normally, a retry with the same key replaying instead of double-withdrawing, a reused key with a different payload being rejected with 409, a failed (422) response being memoized and replayed on retry, and a fresh key allowing a genuinely new attempt.
