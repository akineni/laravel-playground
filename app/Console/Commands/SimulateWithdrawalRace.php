<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

#[Signature('accounts:simulate-race
    {account? : Existing account ID to reset and reuse (creates a new one if omitted)}
    {--strategy=pessimistic : Locking strategy to exercise: pessimistic or optimistic}
    {--amount=4000 : Amount withdrawn by each of the two concurrent requests}
    {--balance=5000 : Starting balance to seed/reset the account to before racing}
    {--base-url=http://127.0.0.1:8000 : Base URL of the running app (php artisan serve)}
')]
#[Description('Fire two concurrent withdrawal requests at the same account to demonstrate pessimistic vs optimistic locking.')]
class SimulateWithdrawalRace extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $strategy = $this->option('strategy');

        if (! in_array($strategy, ['pessimistic', 'optimistic'], true)) {
            $this->error("Invalid --strategy [{$strategy}]. Use 'pessimistic' or 'optimistic'.");

            return self::FAILURE;
        }

        $amount = (float) $this->option('amount');
        $startingBalance = (float) $this->option('balance');
        $baseUrl = rtrim((string) $this->option('base-url'), '/');

        $account = $this->resolveAccount($startingBalance);

        $this->info("Account #{$account->id} reset to balance={$account->balance}, version={$account->version}.");
        $this->line("Firing two concurrent withdrawals of {$amount} using the [{$strategy}] strategy...");
        $this->newLine();

        $endpoint = "{$baseUrl}/api/v1/accounts/{$account->id}/withdraw-{$strategy}";

        $responses = Http::pool(fn (Pool $pool) => [
            $pool->as('Request A')->post($endpoint, ['amount' => $amount]),
            $pool->as('Request B')->post($endpoint, ['amount' => $amount]),
        ]);

        $succeeded = 0;

        foreach (['Request A', 'Request B'] as $label) {
            /** @var Response|\Throwable $response */
            $response = $responses[$label];

            if ($response instanceof \Throwable) {
                $this->warn("{$label}: connection failed - {$response->getMessage()}");

                continue;
            }

            $succeeded += $response->successful() ? 1 : 0;

            $this->line(sprintf(
                '%s: HTTP %d - %s',
                $label,
                $response->status(),
                $response->json('message'),
            ));
        }

        $account->refresh();

        $this->newLine();
        $this->info("Final balance: {$account->balance} (version {$account->version})");
        $this->line("Requests that succeeded: {$succeeded}/2");

        $expectedBalance = $startingBalance - ($succeeded * $amount);

        if (round((float) $account->balance, 2) === round($expectedBalance, 2)) {
            $this->info('No lost update: final balance matches the number of requests that actually succeeded.');
        } else {
            $this->error("Lost update detected: expected balance {$expectedBalance} but got {$account->balance}.");
        }

        return self::SUCCESS;
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
            'name' => 'Race Simulation Account',
            'balance' => $startingBalance,
            'version' => 0,
        ]);
    }
}
