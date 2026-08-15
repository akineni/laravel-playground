<?php

namespace Tests\Feature\Account;

use App\Models\Account;
use App\Repositories\Contracts\AccountRepositoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------
    // Pessimistic lock (lockForUpdate)
    // -------------------------------------------------------

    public function test_pessimistic_withdrawal_decrements_balance(): void
    {
        $account = Account::factory()->create(['balance' => 5000]);

        $response = $this->postJson("/api/v1/accounts/{$account->id}/withdraw-pessimistic", [
            'amount' => 4000,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.balance', '1000.00');

        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'balance' => 1000,
        ]);
    }

    public function test_pessimistic_withdrawal_fails_when_insufficient_funds(): void
    {
        $account = Account::factory()->create(['balance' => 1000]);

        $response = $this->postJson("/api/v1/accounts/{$account->id}/withdraw-pessimistic", [
            'amount' => 4000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'balance' => 1000,
        ]);
    }

    // -------------------------------------------------------
    // Optimistic lock (version column)
    // -------------------------------------------------------

    public function test_optimistic_withdrawal_decrements_balance_and_bumps_version(): void
    {
        $account = Account::factory()->create(['balance' => 5000, 'version' => 0]);

        $response = $this->postJson("/api/v1/accounts/{$account->id}/withdraw-optimistic", [
            'amount' => 4000,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.balance', '1000.00')
            ->assertJsonPath('data.version', 1);

        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'balance' => 1000,
            'version' => 1,
        ]);
    }

    public function test_optimistic_withdrawal_fails_when_insufficient_funds(): void
    {
        $account = Account::factory()->create(['balance' => 1000, 'version' => 0]);

        $response = $this->postJson("/api/v1/accounts/{$account->id}/withdraw-optimistic", [
            'amount' => 4000,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'balance' => 1000,
            'version' => 0,
        ]);
    }

    /**
     * This is the core lost-update guard: two "requests" that both read
     * version 0 race to write. Whichever writes first wins and bumps the
     * version; the second is rejected because its expected version (0)
     * no longer matches the row (now 1) - a lost update is prevented.
     */
    public function test_optimistic_lock_rejects_a_stale_write_after_a_concurrent_winner(): void
    {
        $account = Account::factory()->create(['balance' => 5000, 'version' => 0]);

        /** @var AccountRepositoryInterface $repository */
        $repository = app(AccountRepositoryInterface::class);

        $firstWriterWon = $repository->withdrawIfVersionMatches($account->id, 4000, 0);
        $secondWriterLost = $repository->withdrawIfVersionMatches($account->id, 4000, 0);

        $this->assertTrue($firstWriterWon);
        $this->assertFalse($secondWriterLost);

        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'balance' => 1000,
            'version' => 1,
        ]);
    }

    public function test_optimistic_withdrawal_endpoint_returns_409_when_version_conflicts(): void
    {
        $account = Account::factory()->create(['balance' => 5000, 'version' => 0]);

        $this->mock(AccountRepositoryInterface::class, function ($mock) use ($account) {
            $mock->shouldReceive('findOrFail')->once()->andReturn($account);
            $mock->shouldReceive('withdrawIfVersionMatches')->once()->andReturn(false);
        });

        $response = $this->postJson("/api/v1/accounts/{$account->id}/withdraw-optimistic", [
            'amount' => 4000,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('status', 'error');
    }
}
