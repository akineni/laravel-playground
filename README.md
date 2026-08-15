# Laravel Playground

A sandbox for practicing backend engineering concepts, **one concept at a time**. Each concept lives behind its own set of routes/classes and gets its own section below (and its own commit) — nothing here is meant to compose into a "real" product.

## Concepts

| # | Concept | Status |
|---|---------|--------|
| 01 | [Concurrency Control — Optimistic vs Pessimistic Locking](#01--concurrency-control--optimistic-vs-pessimistic-locking) | ✅ Done |

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
