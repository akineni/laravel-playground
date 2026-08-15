<?php

namespace App\Repositories\Eloquent;

use App\Models\Account;
use App\Repositories\Contracts\AccountRepositoryInterface;

class AccountRepository implements AccountRepositoryInterface
{
    public function findOrFail(int $id): Account
    {
        return Account::query()->findOrFail($id);
    }

    public function findForUpdate(int $id): Account
    {
        return Account::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function withdrawLocked(Account $account, float $amount): bool
    {
        $account->balance = (string) round((float) $account->balance - $amount, 2);
        $account->version++;

        return $account->save();
    }

    public function withdrawIfVersionMatches(int $id, float $amount, int $expectedVersion): bool
    {
        $affected = Account::query()
            ->whereKey($id)
            ->where('version', $expectedVersion)
            ->decrement('balance', $amount, [
                'version' => $expectedVersion + 1,
            ]);

        return $affected > 0;
    }
}
