<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

#[Signature('accounts:simulate-presigned-upload
    {account? : Existing account ID to reuse (creates a new one if omitted)}
    {--base-url=http://127.0.0.1:8000 : Base URL of the running app (php artisan serve)}
')]
#[Description('Demonstrate a presigned Cloudinary upload end to end: request a signature, upload directly to Cloudinary with it, record the result, then show a forged payload being rejected.')]
class SimulatePresignedUpload extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cloudName = (string) config('services.cloudinary.cloud_name');

        if ($cloudName === '') {
            $this->error('CLOUDINARY_CLOUD_NAME / CLOUDINARY_API_KEY / CLOUDINARY_API_SECRET are not set in .env.');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $account = $this->resolveAccount();
        $endpoint = "{$baseUrl}/api/v1/accounts/{$account->id}/documents";

        $this->info("Account #{$account->id} ready.");
        $this->newLine();

        $this->line('--- 1. Ask our server for a signed upload (no file sent yet) ---');
        $presignResponse = Http::post("{$endpoint}/presign");

        if ($presignResponse->failed()) {
            $this->error("Presign request failed: HTTP {$presignResponse->status()} - {$presignResponse->body()}");

            return self::FAILURE;
        }

        $presigned = $presignResponse->json('data');
        $this->line("Signed public_id: {$presigned['public_id']}");
        $this->newLine();

        $this->line('--- 2. Upload directly to Cloudinary using that signature (our server never sees the bytes) ---');
        $cloudinaryResponse = Http::attach(
            'file', $this->onePixelPng(), 'presigned-demo.png'
        )->post("https://api.cloudinary.com/v1_1/{$cloudName}/auto/upload", [
            'api_key' => $presigned['api_key'],
            'timestamp' => $presigned['timestamp'],
            'signature' => $presigned['signature'],
            'folder' => $presigned['folder'],
            'public_id' => $presigned['public_id'],
        ]);

        if ($cloudinaryResponse->failed()) {
            $this->error("Cloudinary upload failed: HTTP {$cloudinaryResponse->status()} - {$cloudinaryResponse->body()}");

            return self::FAILURE;
        }

        $uploaded = $cloudinaryResponse->json();
        $this->line("Cloudinary stored it at: {$uploaded['secure_url']}");
        $this->newLine();

        $this->line('--- 3. Report the result back to our server, which independently re-verifies the signature ---');
        $this->report('Genuine result', Http::post($endpoint, [
            'public_id' => $uploaded['public_id'],
            'version' => $uploaded['version'],
            'signature' => $uploaded['signature'],
            'secure_url' => $uploaded['secure_url'],
            'original_filename' => 'presigned-demo.png',
        ]));

        $this->newLine();
        $this->line('--- 4. A forged payload: real signature, but claiming a different asset ---');
        $this->report('Forged result', Http::post($endpoint, [
            'public_id' => $uploaded['public_id'].'-forged',
            'version' => $uploaded['version'],
            'signature' => $uploaded['signature'],
            'secure_url' => str_replace($uploaded['public_id'], $uploaded['public_id'].'-forged', $uploaded['secure_url']),
            'original_filename' => 'not-actually-uploaded.png',
        ]));

        $this->newLine();
        $documents = Http::get($endpoint)->json('data');
        $this->info(sprintf('Account now has %d recorded document(s). Only the genuine upload was persisted.', count($documents)));

        return self::SUCCESS;
    }

    protected function report(string $label, Response $response): void
    {
        $this->line(sprintf(
            '%s: HTTP %d - %s',
            $label,
            $response->status(),
            $response->json('message'),
        ));
    }

    /**
     * A minimal valid 1x1 transparent PNG, generated in memory so the demo
     * doesn't depend on a fixture file existing on disk.
     */
    protected function onePixelPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        );
    }

    protected function resolveAccount(): Account
    {
        $accountId = $this->argument('account');

        if ($accountId) {
            return Account::query()->findOrFail($accountId);
        }

        return Account::factory()->create([
            'name' => 'Presigned Upload Simulation Account',
        ]);
    }
}
