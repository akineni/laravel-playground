<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a stable demo account for manually testing the locking endpoints
     * in Postman. Re-running this seeder resets it back to a clean
     * balance/version instead of creating duplicates, so you can reseed
     * between manual test runs or before firing the race simulation.
     */
    public function run(): void
    {
        $account = Account::query()->updateOrCreate(
            ['name' => 'Demo Account'],
            ['balance' => 5000, 'version' => 0]
        );

        $this->command?->info("Demo account ready: id={$account->id}, balance={$account->balance}, version={$account->version}");
    }
}
