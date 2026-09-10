<?php

namespace App\Services\Cloudinary;

class CloudinarySignatureService
{
    public function __construct(
        private readonly string $cloudName,
        private readonly string $apiKey,
        private readonly string $apiSecret,
    ) {}

    /**
     * Sign a set of upload parameters so a client can upload directly to
     * Cloudinary without ever holding the API secret. Cloudinary recomputes
     * this same signature server-side and rejects the upload if it doesn't
     * match - so the client can only use the exact params we signed here
     * (this folder, this public_id), not arbitrary ones of its choosing.
     *
     * @param  array<string, scalar>  $params
     * @return array{timestamp: int, signature: string, api_key: string, cloud_name: string}
     */
    public function signUploadParams(array $params): array
    {
        $timestamp = now()->getTimestamp();

        return [
            'timestamp' => $timestamp,
            'signature' => $this->sign([...$params, 'timestamp' => $timestamp]),
            'api_key' => $this->apiKey,
            'cloud_name' => $this->cloudName,
        ];
    }

    /**
     * Recompute Cloudinary's own signature over the `public_id`/`version`
     * pair it returns after a successful upload, and compare it against the
     * `signature` field from that same response. This is what stops a
     * client from skipping the real upload and simply POSTing a made-up
     * URL to our store endpoint: only someone holding the API secret (us,
     * or Cloudinary itself) can produce a signature that matches.
     *
     * @param  array{public_id: string, version: int|string}  $params
     */
    public function verifyUploadResponse(array $params, string $signature): bool
    {
        return hash_equals($this->sign($params), $signature);
    }

    /**
     * @param  array<string, scalar>  $params
     */
    private function sign(array $params): string
    {
        ksort($params);

        $toSign = collect($params)
            ->reject(fn (mixed $value): bool => $value === null || $value === '')
            ->map(fn (mixed $value, string $key): string => "{$key}={$value}")
            ->implode('&');

        return sha1($toSign.$this->apiSecret);
    }
}
