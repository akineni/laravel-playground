<?php

namespace App\Services\Account;

use App\Exceptions\InvalidUploadSignatureException;
use App\Models\Account;
use App\Models\AccountDocument;
use App\Services\Cloudinary\CloudinarySignatureService;
use Illuminate\Support\Str;

class AccountDocumentService
{
    public function __construct(
        private readonly CloudinarySignatureService $signer,
    ) {}

    /**
     * Generate a signed set of upload parameters scoped to this account's
     * own folder and a fresh public_id, so the client can upload straight
     * to Cloudinary without ever seeing our API secret.
     *
     * @return array{timestamp: int, signature: string, api_key: string, cloud_name: string, folder: string, public_id: string}
     */
    public function presignUpload(Account $account): array
    {
        $folder = "accounts/{$account->id}/documents";
        $publicId = (string) Str::uuid();

        $signed = $this->signer->signUploadParams([
            'folder' => $folder,
            'public_id' => $publicId,
        ]);

        return [...$signed, 'folder' => $folder, 'public_id' => $publicId];
    }

    /**
     * Persist a document the client claims to have uploaded to Cloudinary,
     * after independently verifying Cloudinary's own response signature.
     * This is the check that stops a client from skipping the real upload
     * and just POSTing a made-up URL.
     *
     * @param  array{public_id: string, version: int|string, signature: string, secure_url: string, original_filename?: string|null}  $upload
     */
    public function store(Account $account, array $upload): AccountDocument
    {
        $verified = $this->signer->verifyUploadResponse([
            'public_id' => $upload['public_id'],
            'version' => $upload['version'],
        ], $upload['signature']);

        if (! $verified) {
            throw new InvalidUploadSignatureException;
        }

        return $account->documents()->create([
            'public_id' => $upload['public_id'],
            'secure_url' => $upload['secure_url'],
            'original_filename' => $upload['original_filename'] ?? null,
        ]);
    }
}
