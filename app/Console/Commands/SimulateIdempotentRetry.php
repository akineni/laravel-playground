<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

#[Signature('accounts:simulate-idempotent-retry
    {account? : Existing account ID to reset and reuse (creates a new one if omitted)}
    {--mode=sequential : Scenario to run: sequential, concurrent, or conflict}
    {--amount=4000 : Amount withdrawn}
    {--balance=5000 : Starting balance to seed/reset the account to before the demo}
    {--base-url=http://127.0.0.1:8000 : Base URL of the running app (php artisan serve)}
')]
#[Description('Demonstrate the Idempotency-Key header against the withdraw-idempotent endpoint: a lost-response retry, a concurrent duplicate, and a reused key with a different payload.')]
class SimulateIdempotentRetry extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $mode = $this->option('mode');

        if (! in_array($mode, ['sequential', 'concurrent', 'conflict'], true)) {
            $this->error("Invalid --mode [{$mode}]. Use 'sequential', 'concurrent', or 'conflict'.");

            return self::FAILURE;
        }

        $amount = (float) $this->option('amount');
        $startingBalance = (float) $this->option('balance');
        $baseUrl = rtrim((string) $this->option('base-url'), '/');

        $account = $this->resolveAccount($startingBalance);
        $endpoint = "{$baseUrl}/api/v1/accounts/{$account->id}/withdraw-idempotent";
        $key = (string) Str::uuid();

        $this->info("Account #{$account->id} reset to balance={$account->balance}.");
        $this->line("Idempotency-Key: {$key}");
        $this->newLine();

        match ($mode) {
            'sequential' => $this->runSequential($endpoint, $key, $amount),
            'concurrent' => $this->runConcurrent($endpoint, $key, $amount),
            'conflict' => $this->runConflict($endpoint, $key, $amount),
        };

        $account->refresh();
        $this->newLine();
        $this->info("Final balance: {$account->balance}");

        $expectedBalance = round($startingBalance - $amount, 2);

        if (round((float) $account->balance, 2) === $expectedBalance) {
            $this->info('Exactly one withdrawal took effect: retries were replayed, not reprocessed.');
        } else {
            $this->error("Double-processing detected: expected balance {$expectedBalance} but got {$account->balance}.");
        }

        return self::SUCCESS;
    }

    /**
     * The client withdraws, never sees the response (timeout, dropped
     * connection...), and retries with the same key a moment later.
     */
    protected function runSequential(string $endpoint, string $key, float $amount): void
    {
        $this->line('--- First attempt (simulates the response being lost) ---');
        $this->report('Request A', $this->post($endpoint, $key, $amount));

        $this->line('--- Client retries with the same Idempotency-Key ---');
        $this->report('Request B (retry)', $this->post($endpoint, $key, $amount));
    }

    /**
     * Two requests carrying the same key land at the same instant - e.g. a
     * client double-tapping submit, or its retry racing the original
     * request's still-in-flight response.
     */
    protected function runConcurrent(string $endpoint, string $key, float $amount): void
    {
        $this->line('--- Two concurrent requests, same Idempotency-Key ---');

        $responses = Http::pool(fn (Pool $pool) => [
            $pool->as('Request A')->withHeaders(['Idempotency-Key' => $key])->post($endpoint, ['amount' => $amount]),
            $pool->as('Request B')->withHeaders(['Idempotency-Key' => $key])->post($endpoint, ['amount' => $amount]),
        ]);

        foreach (['Request A', 'Request B'] as $label) {
            $this->report($label, $responses[$label]);
        }
    }

    /**
     * Same key, different payload - the client is misusing the key
     * (or is bugged), and the server must not replay a stale response
     * for a request that is not actually the same operation.
     */
    protected function runConflict(string $endpoint, string $key, float $amount): void
    {
        $this->line('--- First attempt ---');
        $this->report('Request A', $this->post($endpoint, $key, $amount));

        $this->line('--- Same key, different amount ---');
        $this->report('Request B (reused key)', $this->post($endpoint, $key, $amount + 1000));
    }

    protected function post(string $endpoint, string $key, float $amount): Response|\Throwable
    {
        try {
            return Http::withHeaders(['Idempotency-Key' => $key])->post($endpoint, ['amount' => $amount]);
        } catch (\Throwable $e) {
            return $e;
        }
    }

    protected function report(string $label, Response|\Throwable $response): void
    {
        if ($response instanceof \Throwable) {
            $this->warn("{$label}: connection failed - {$response->getMessage()}");

            return;
        }

        $replayed = $response->header('Idempotency-Replayed') === 'true' ? ' [replayed]' : '';

        $this->line(sprintf(
            '%s: HTTP %d - %s%s',
            $label,
            $response->status(),
            $response->json('message'),
            $replayed,
        ));
    }

    protected function resolveAccount(float $startingBalance): Account
    {
        $accountId = $this->argument('account');

        if ($accountId) {
            $account = Account::query()->findOrFail($accountId);
            $account->forceFill(['balance' => $startingBalance, 'version' => 0])->save();

            return $account;
        }

        return Account::factory()->create([
            'name' => 'Idempotency Simulation Account',
            'balance' => $startingBalance,
            'version' => 0,
        ]);
    }
}
