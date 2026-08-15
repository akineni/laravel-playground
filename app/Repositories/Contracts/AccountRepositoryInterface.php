<?php

namespace App\Repositories\Contracts;

use App\Models\Account;

interface AccountRepositoryInterface
{
    /**
     * Find an account by ID or fail.
     */
    public function findOrFail(int $id): Account;

    /**
     * Find an account by ID and lock the row for update.
     *
     * Must be called inside a transaction. Blocks concurrent callers
     * until the owning transaction commits or rolls back.
     */
    public function findForUpdate(int $id): Account;

    /**
     * Debit an already row-locked account (see findForUpdate) and persist it.
     */
    public function withdrawLocked(Account $account, float $amount): bool;

    /**
     * Atomically withdraw from an account only if its version still
     * matches the expected value. Returns false if another write
     * changed the version first (i.e. a lost-update conflict).
     */
    public function withdrawIfVersionMatches(int $id, float $amount, int $expectedVersion): bool;
}
