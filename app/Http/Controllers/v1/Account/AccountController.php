<?php

namespace App\Http\Controllers\v1\Account;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\WithdrawRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Services\Account\AccountService;
use Illuminate\Http\JsonResponse;

/**
 * @group Concurrency: Optimistic vs Pessimistic Locking
 *
 * Two ways to prevent a lost update when concurrent requests withdraw from
 * the same account: locking the row for the duration of a transaction
 * (pessimistic), and checking a version column at write time (optimistic).
 * See `php artisan accounts:simulate-race` to fire two concurrent requests
 * and watch each strategy handle the race differently.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly AccountService $accountService
    ) {}

    /**
     * Show Account
     *
     * Retrieve an account's current balance and version. Useful for
     * checking the outcome after racing the withdrawal endpoints below.
     *
     * @subgroup Inspect Account
     *
     * @urlParam account int required The account ID. Example: 1
     *
     * @response 200 scenario="Account found" {
     *   "status": "success",
     *   "message": "Account retrieved successfully",
     *   "data": {
     *     "id": 1,
     *     "name": "Race Simulation Account",
     *     "balance": "5000.00",
     *     "version": 0,
     *     "updated_at": "2026-08-01T09:00:00.000000Z"
     *   }
     * }
     * @response 404 scenario="Account not found" {
     *   "message": "No query results for model [App\\Models\\Account] 1"
     * }
     */
    public function show(Account $account): JsonResponse
    {
        return ApiResponse::success(
            'Account retrieved successfully',
            new AccountResource($account)
        );
    }

    /**
     * Withdraw (Pessimistic Lock)
     *
     * Withdraws using `SELECT ... FOR UPDATE` inside a transaction. A
     * concurrent request against the same account blocks on the row lock
     * and only proceeds once the first transaction commits - so it always
     * re-reads the already-debited balance. Two concurrent withdrawals of
     * ₦4,000 against a ₦5,000 balance are serialized: the first succeeds,
     * the second then correctly fails with insufficient funds.
     *
     * @subgroup Pessimistic Locking (SELECT ... FOR UPDATE)
     *
     * @subgroupDescription Concurrent requests against the same account queue up behind the row lock instead of racing.
     *
     * @urlParam account int required The account ID. Example: 1
     *
     * @bodyParam amount number required Amount to withdraw. Must be greater than 0. Example: 4000
     *
     * @response 200 scenario="Withdrawal successful" {
     *   "status": "success",
     *   "message": "Withdrawal successful (pessimistic lock)",
     *   "data": {
     *     "id": 1,
     *     "name": "Race Simulation Account",
     *     "balance": "1000.00",
     *     "version": 0,
     *     "updated_at": "2026-08-01T09:00:01.000000Z"
     *   }
     * }
     * @response 422 scenario="Insufficient funds" {
     *   "status": "error",
     *   "message": "Insufficient funds for this withdrawal."
     * }
     */
    public function withdrawPessimistic(WithdrawRequest $request, Account $account): JsonResponse
    {
        $updatedAccount = $this->accountService->withdrawPessimistic(
            $account->id,
            (float) $request->validated('amount')
        );

        return ApiResponse::success(
            'Withdrawal successful (pessimistic lock)',
            new AccountResource($updatedAccount)
        );
    }

    /**
     * Withdraw (Optimistic Lock)
     *
     * Reads the account without a lock, then writes conditionally on the
     * version it read (`UPDATE ... WHERE version = ?`). If a concurrent
     * request already won the race and bumped the version, this write
     * affects zero rows and is rejected with a 409 conflict instead of
     * silently overwriting the other request's update.
     *
     * @subgroup Optimistic Locking (Version Column)
     *
     * @subgroupDescription Concurrent requests both read the same version; only the first write wins, the other is rejected with a 409.
     *
     * @urlParam account int required The account ID. Example: 1
     *
     * @bodyParam amount number required Amount to withdraw. Must be greater than 0. Example: 4000
     *
     * @response 200 scenario="Withdrawal successful" {
     *   "status": "success",
     *   "message": "Withdrawal successful (optimistic lock)",
     *   "data": {
     *     "id": 1,
     *     "name": "Race Simulation Account",
     *     "balance": "1000.00",
     *     "version": 1,
     *     "updated_at": "2026-08-01T09:00:01.000000Z"
     *   }
     * }
     * @response 409 scenario="Version conflict (lost the race)" {
     *   "status": "error",
     *   "message": "Account was modified by another request. Please retry the withdrawal."
     * }
     * @response 422 scenario="Insufficient funds" {
     *   "status": "error",
     *   "message": "Insufficient funds for this withdrawal."
     * }
     */
    public function withdrawOptimistic(WithdrawRequest $request, Account $account): JsonResponse
    {
        $updatedAccount = $this->accountService->withdrawOptimistic(
            $account->id,
            (float) $request->validated('amount')
        );

        return ApiResponse::success(
            'Withdrawal successful (optimistic lock)',
            new AccountResource($updatedAccount)
        );
    }

    /**
     * Withdraw (Idempotent)
     *
     * Same pessimistic-locked withdrawal as above, but guarded by an
     * `Idempotency-Key` header. A repeated request with a key that was
     * already used replays the stored response instead of debiting the
     * account again - safe whether the retry arrives seconds later or at
     * the exact same instant as the original (concurrent retries block on
     * the idempotency record's row lock until the first attempt commits).
     * Reusing a key with a different request body is rejected with 409.
     *
     * @subgroup Idempotency (Retry Safety)
     *
     * @subgroupDescription A client retrying a request it never got a response for gets the original result replayed, not a second withdrawal.
     *
     * @urlParam account int required The account ID. Example: 1
     *
     * @header Idempotency-Key string required A unique key generated by the client per logical operation, e.g. a UUID. Example: 6e4b6e0a-6f0a-4a2b-8f3f-8f0e6e4b6e0a
     *
     * @bodyParam amount number required Amount to withdraw. Must be greater than 0. Example: 4000
     *
     * @response 200 scenario="Withdrawal successful (first attempt)" {
     *   "status": "success",
     *   "message": "Withdrawal successful (idempotent)",
     *   "data": {
     *     "id": 1,
     *     "name": "Race Simulation Account",
     *     "balance": "1000.00",
     *     "version": 0,
     *     "updated_at": "2026-08-01T09:00:01.000000Z"
     *   }
     * }
     * @response 200 scenario="Replayed (same key, same payload, retried)" {
     *   "status": "success",
     *   "message": "Withdrawal successful (idempotent)",
     *   "data": {
     *     "id": 1,
     *     "name": "Race Simulation Account",
     *     "balance": "1000.00",
     *     "version": 0,
     *     "updated_at": "2026-08-01T09:00:01.000000Z"
     *   }
     * }
     * @response 400 scenario="Missing Idempotency-Key header" {
     *   "status": "error",
     *   "message": "The Idempotency-Key header is required."
     * }
     * @response 409 scenario="Same key reused with a different payload" {
     *   "status": "error",
     *   "message": "Idempotency-Key was already used with a different request payload."
     * }
     * @response 422 scenario="Insufficient funds" {
     *   "status": "error",
     *   "message": "Insufficient funds for this withdrawal."
     * }
     */
    public function withdrawIdempotent(WithdrawRequest $request, Account $account): JsonResponse
    {
        $updatedAccount = $this->accountService->withdrawPessimistic(
            $account->id,
            (float) $request->validated('amount')
        );

        return ApiResponse::success(
            'Withdrawal successful (idempotent)',
            new AccountResource($updatedAccount)
        );
    }
}
