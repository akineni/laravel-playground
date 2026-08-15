<?php

namespace App\Http\Middleware;

use App\Exceptions\ConflictException;
use App\Helpers\ApiResponse;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Idempotency
 *
 * Makes a side-effecting endpoint safe to retry. A client that sends the
 * same `Idempotency-Key` header twice - because it never received the
 * first response, not because it wants to repeat the action - gets back
 * the exact result of the first attempt instead of triggering the side
 * effect a second time. See `php artisan accounts:simulate-idempotent-retry`.
 */
class EnsureIdempotencyKey
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (JsonResponse)  $next
     */
    public function handle(Request $request, Closure $next): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if (blank($key)) {
            return ApiResponse::error('The Idempotency-Key header is required.', 400);
        }

        $fingerprint = $this->fingerprint($request);

        try {
            return DB::transaction(function () use ($request, $next, $key, $fingerprint) {
                $record = IdempotencyKey::query()->create([
                    'key' => $key,
                    'request_fingerprint' => $fingerprint,
                    'status' => 'processing',
                ]);

                $response = $next($request);

                $record->forceFill([
                    'status' => 'completed',
                    'response_status' => $response->getStatusCode(),
                    'response_body' => json_decode((string) $response->getContent(), true),
                ])->save();

                return $response->header('Idempotency-Replayed', 'false');
            });
        } catch (QueryException $e) {
            if (! $this->isDuplicateKeyViolation($e)) {
                throw $e;
            }

            return $this->replay($key, $fingerprint);
        }
    }

    /**
     * We collided on the unique key - someone else already owns this
     * Idempotency-Key. Two ways that happens:
     *
     *  - Sequential retry: the earlier request already committed; this
     *    row has been sitting there, finished, the whole time.
     *  - Concurrent duplicate: our create() above actually blocked
     *    (InnoDB waits when a duplicate key belongs to an uncommitted
     *    row) until the other transaction committed, and only then
     *    surfaced as this duplicate-key error - so by the time we're
     *    here, that transaction is already done.
     *
     * Either way the row should already be finished by now. lockForUpdate()
     * both takes a lock AND, if the row is still locked by someone else,
     * waits for that transaction to finish before reading - but here that
     * isn't the real wait (that already happened above); it's a defensive
     * guarantee that we never read a still-in-progress record, independent
     * of how a given database handles the insert collision.
     */
    protected function replay(string $key, string $fingerprint): JsonResponse
    {
        return DB::transaction(function () use ($key, $fingerprint) {
            $record = IdempotencyKey::query()->where('key', $key)->lockForUpdate()->firstOrFail();

            // The key matches but the request doesn't - e.g. the client
            // withdrew ₦4,000 under this key earlier and is now reusing
            // the same key for a ₦5,000 withdrawal instead of generating
            // a fresh one. This is a client bug: keys are meant to be
            // unique per logical operation. We can't tell a legitimate
            // retry apart from key misuse by the key alone, only by
            // comparing what the request actually contains. Replaying the
            // stored response here would silently return "success: ₦4,000
            // withdrawn" for a ₦5,000 withdrawal that never happened - so
            // we reject instead of guessing.
            if ($record->request_fingerprint !== $fingerprint) {
                throw new ConflictException(
                    'Idempotency-Key was already used with a different request payload.'
                );
            }

            return response()
                ->json($record->response_body, $record->response_status)
                ->header('Idempotency-Replayed', 'true');
        });
    }

    /**
     * Fingerprint the request so a reused key with a different payload
     * can be rejected instead of silently replaying the wrong response.
     */
    protected function fingerprint(Request $request): string
    {
        return hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($request->all()));
    }

    protected function isDuplicateKeyViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000';
    }
}
