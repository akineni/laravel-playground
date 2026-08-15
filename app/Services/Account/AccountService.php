<?php

namespace App\Services\Account;

use App\Exceptions\ConflictException;
use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Repositories\Contracts\AccountRepositoryInterface;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
    ) {}

    /**
     * Withdraw using pessimistic locking (SELECT ... FOR UPDATE).
     *
     * The row is locked for the duration of the transaction, so a second
     * concurrent request blocks on the lock and only proceeds once the
     * first transaction commits - at which point it re-reads the
     * already-debited balance instead of the stale value.
     */
    public function withdrawPessimistic(int $accountId, float $amount): Account
    {
        return DB::transaction(function () use ($accountId, $amount) {
            $account = $this->accountRepository->findForUpdate($accountId);

            $this->assertSufficientFunds($account, $amount);

            $this->accountRepository->withdrawLocked($account, $amount);

            return $account->fresh();
        });
    }

    /**
     * Withdraw using optimistic locking (version column).
     *
     * The account is read without a lock, so two concurrent requests can
     * both see the same version. Only the write that matches the version
     * it originally read succeeds; the other affects zero rows and is
     * rejected as a conflict instead of silently overwriting the update.
     */
    public function withdrawOptimistic(int $accountId, float $amount): Account
    {
        $account = $this->accountRepository->findOrFail($accountId);

        $this->assertSufficientFunds($account, $amount);

        $updated = $this->accountRepository->withdrawIfVersionMatches(
            $account->id,
            $amount,
            $account->version,
        );

        if (! $updated) {
            throw new ConflictException(
                'Account was modified by another request. Please retry the withdrawal.'
            );
        }

        return $account->fresh();
    }

    protected function assertSufficientFunds(Account $account, float $amount): void
    {
        if ((float) $account->balance < $amount) {
            throw new InsufficientFundsException('Insufficient funds for this withdrawal.');
        }
    }
}
