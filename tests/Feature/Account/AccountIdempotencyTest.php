<?php

namespace Tests\Feature\Account;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_idempotency_key_header_is_required(): void
    {
        $account = Account::factory()->create(['balance' => 5000]);

        $response = $this->postJson("/api/v1/accounts/{$account->id}/withdraw-idempotent", [
            'amount' => 4000,
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseHas('accounts', ['id' => $account->id, 'balance' => 5000]);
    }

    public function test_first_request_withdraws_and_is_not_marked_as_replayed(): void
    {
        $account = Account::factory()->create(['balance' => 5000]);
        $key = (string) Str::uuid();

        $response = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/accounts/{$account->id}/withdraw-idempotent", ['amount' => 4000]);

        $response->assertStatus(200)
            ->assertJsonPath('data.balance', '1000.00')
            ->assertHeader('Idempotency-Replayed', 'false');

        $this->assertDatabaseHas('idempotency_keys', [
            'key' => $key,
            'status' => 'completed',
            'response_status' => 200,
        ]);
    }

    public function test_retrying_the_same_key_replays_the_original_response_without_double_withdrawal(): void
    {
        $account = Account::factory()->create(['balance' => 5000]);
        $key = (string) Str::uuid();

        $first = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/accounts/{$account->id}/withdraw-idempotent", ['amount' => 4000]);

        $retry = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/accounts/{$account->id}/withdraw-idempotent", ['amount' => 4000]);

        $first->assertStatus(200)->assertJsonPath('data.balance', '1000.00');

        $retry->assertStatus(200)
            ->assertJsonPath('data.balance', '1000.00')
            ->assertHeader('Idempotency-Replayed', 'true');

        // Only the first attempt actually touched the balance.
        $this->assertDatabaseHas('accounts', ['id' => $account->id, 'balance' => 1000]);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_reusing_the_same_key_with_a_different_payload_is_rejected(): void
    {
        $account = Account::factory()->create(['balance' => 5000]);
        $key = (string) Str::uuid();

        $first = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/accounts/{$account->id}/withdraw-idempotent", ['amount' => 4000]);

        $conflicting = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/accounts/{$account->id}/withdraw-idempotent", ['amount' => 500]);

        $first->assertStatus(200);

        $conflicting->assertStatus(409)
            ->assertJsonPath('status', 'error');

        // The conflicting request must not have been processed as a second withdrawal.
        $this->assertDatabaseHas('accounts', ['id' => $account->id, 'balance' => 1000]);
    }

    public function test_a_failed_response_is_memoized_and_replayed_on_retry(): void
    {
        // A key represents "this exact logical operation", error included -
        // Stripe's idempotency keys behave the same way. A retry with the
        // same key must not re-run the query against the (now possibly
        // different) account state; it replays the original 422 verbatim.
        $account = Account::factory()->create(['balance' => 1000]);
        $key = (string) Str::uuid();

        $first = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/accounts/{$account->id}/withdraw-idempotent", ['amount' => 4000]);

        $first->assertStatus(422)->assertJsonPath('status', 'error');

        // Top up the balance - a fresh attempt would now succeed - but the
        // replay must still return the original failure, not a live re-check.
        $account->forceFill(['balance' => 5000])->save();

        $retry = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/accounts/{$account->id}/withdraw-idempotent", ['amount' => 4000]);

        $retry->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertDatabaseHas('accounts', ['id' => $account->id, 'balance' => 5000]);
    }

    public function test_a_new_key_allows_a_genuinely_new_attempt_after_a_failure(): void
    {
        $account = Account::factory()->create(['balance' => 1000]);

        $first = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/accounts/{$account->id}/withdraw-idempotent", ['amount' => 4000]);

        $first->assertStatus(422);

        $account->forceFill(['balance' => 5000])->save();

        $retryWithNewKey = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/accounts/{$account->id}/withdraw-idempotent", ['amount' => 4000]);

        $retryWithNewKey->assertStatus(200)
            ->assertJsonPath('data.balance', '1000.00')
            ->assertHeader('Idempotency-Replayed', 'false');
    }
}
