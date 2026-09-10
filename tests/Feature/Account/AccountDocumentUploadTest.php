<?php

namespace Tests\Feature\Account;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake, known credentials so signatures in this test are computed
        // independently of whatever real Cloudinary keys are in .env.
        config([
            'services.cloudinary.cloud_name' => 'demo',
            'services.cloudinary.api_key' => 'test-key',
            'services.cloudinary.api_secret' => 'test-secret',
        ]);
    }

    public function test_presign_returns_a_signature_matching_the_configured_secret(): void
    {
        $account = Account::factory()->create();

        $response = $this->postJson("/api/v1/accounts/{$account->id}/documents/presign");

        $response->assertStatus(200);

        $data = $response->json('data');

        $expectedSignature = $this->cloudinarySignature([
            'folder' => $data['folder'],
            'public_id' => $data['public_id'],
            'timestamp' => $data['timestamp'],
        ]);

        $this->assertSame($expectedSignature, $data['signature']);
        $this->assertSame('test-key', $data['api_key']);
        $this->assertSame('demo', $data['cloud_name']);
        $this->assertSame("accounts/{$account->id}/documents", $data['folder']);
    }

    public function test_a_genuinely_signed_upload_is_recorded(): void
    {
        $account = Account::factory()->create();
        $publicId = 'accounts/1/documents/genuine';
        $version = 1699999999;

        $response = $this->postJson("/api/v1/accounts/{$account->id}/documents", [
            'public_id' => $publicId,
            'version' => $version,
            'signature' => $this->cloudinarySignature(['public_id' => $publicId, 'version' => $version]),
            'secure_url' => "https://res.cloudinary.com/demo/image/upload/v{$version}/{$publicId}.jpg",
            'original_filename' => 'passport.jpg',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.public_id', $publicId)
            ->assertJsonPath('data.original_filename', 'passport.jpg');

        $this->assertDatabaseHas('account_documents', [
            'account_id' => $account->id,
            'public_id' => $publicId,
        ]);
    }

    public function test_a_forged_signature_is_rejected_and_nothing_is_persisted(): void
    {
        $account = Account::factory()->create();
        $publicId = 'accounts/1/documents/genuine';
        $version = 1699999999;

        // Signature is valid for a different public_id - simulates a client
        // that never actually uploaded this asset.
        $forgedSignature = $this->cloudinarySignature(['public_id' => 'someone-elses-asset', 'version' => $version]);

        $response = $this->postJson("/api/v1/accounts/{$account->id}/documents", [
            'public_id' => $publicId,
            'version' => $version,
            'signature' => $forgedSignature,
            'secure_url' => "https://res.cloudinary.com/demo/image/upload/v{$version}/{$publicId}.jpg",
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseMissing('account_documents', [
            'account_id' => $account->id,
            'public_id' => $publicId,
        ]);
    }

    public function test_store_requires_the_expected_fields(): void
    {
        $account = Account::factory()->create();

        $response = $this->postJson("/api/v1/accounts/{$account->id}/documents", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['public_id', 'version', 'signature', 'secure_url']);
    }

    public function test_index_lists_documents_recorded_against_the_account(): void
    {
        $account = Account::factory()->create();
        $account->documents()->create([
            'public_id' => 'accounts/1/documents/existing',
            'secure_url' => 'https://res.cloudinary.com/demo/image/upload/v1/existing.jpg',
            'original_filename' => 'existing.jpg',
        ]);

        $response = $this->getJson("/api/v1/accounts/{$account->id}/documents");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', 'accounts/1/documents/existing');
    }

    /**
     * @param  array<string, scalar>  $params
     */
    protected function cloudinarySignature(array $params): string
    {
        ksort($params);

        $toSign = collect($params)
            ->reject(fn (mixed $value): bool => $value === null || $value === '')
            ->map(fn (mixed $value, string $key): string => "{$key}={$value}")
            ->implode('&');

        return sha1($toSign.'test-secret');
    }
}
