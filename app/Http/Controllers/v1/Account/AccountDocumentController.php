<?php

namespace App\Http\Controllers\v1\Account;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountDocumentRequest;
use App\Http\Resources\AccountDocumentResource;
use App\Models\Account;
use App\Services\Account\AccountDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * @group Direct-to-Cloud Uploads: Presigned URLs
 *
 * A client uploads a file straight to Cloudinary instead of routing the
 * bytes through this app. We never see the file or hold a Cloudinary
 * secret on the client - instead we hand back a short-lived signature the
 * client attaches to its upload request, and independently re-verify
 * Cloudinary's own response signature before trusting what comes back.
 * See `php artisan accounts:simulate-presigned-upload` for the full round
 * trip, including a tampered request being rejected.
 */
class AccountDocumentController extends Controller
{
    public function __construct(
        private readonly AccountDocumentService $accountDocumentService
    ) {}

    /**
     * List Documents
     *
     * Documents recorded against this account so far.
     *
     * @subgroup Inspect Documents
     *
     * @urlParam account int required The account ID. Example: 1
     *
     * @response 200 scenario="Documents found" {
     *   "status": "success",
     *   "message": "Documents retrieved successfully",
     *   "data": [
     *     {
     *       "id": 1,
     *       "public_id": "accounts/1/documents/6e4b6e0a-6f0a-4a2b-8f3f-8f0e6e4b6e0a",
     *       "secure_url": "https://res.cloudinary.com/demo/image/upload/v1699999999/accounts/1/documents/6e4b6e0a-6f0a-4a2b-8f3f-8f0e6e4b6e0a.jpg",
     *       "original_filename": "passport.jpg",
     *       "created_at": "2026-08-01T09:00:00.000000Z"
     *     }
     *   ]
     * }
     */
    public function index(Account $account): JsonResponse
    {
        return ApiResponse::success(
            'Documents retrieved successfully',
            AccountDocumentResource::collection($account->documents()->latest()->get())
        );
    }

    /**
     * Presign Upload
     *
     * Generates a signed set of Cloudinary upload parameters scoped to a
     * fresh `public_id` inside this account's own folder, so the resulting
     * signature can't be reused to upload as a different account or
     * overwrite an existing file. Valid for the short time it takes to
     * actually POST the upload to Cloudinary; the account never appears
     * to have credentials of its own.
     *
     * @subgroup Request a Presigned Upload
     *
     * @subgroupDescription The server never touches the file bytes - it only ever produces a signature the client attaches to its own request to Cloudinary.
     *
     * @urlParam account int required The account ID. Example: 1
     *
     * @response 200 scenario="Signature generated" {
     *   "status": "success",
     *   "message": "Upload signature generated",
     *   "data": {
     *     "timestamp": 1699999999,
     *     "signature": "3f7f1a2b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f",
     *     "api_key": "123456789012345",
     *     "cloud_name": "demo",
     *     "folder": "accounts/1/documents",
     *     "public_id": "6e4b6e0a-6f0a-4a2b-8f3f-8f0e6e4b6e0a"
     *   }
     * }
     */
    public function presign(Account $account): JsonResponse
    {
        return ApiResponse::success(
            'Upload signature generated',
            $this->accountDocumentService->presignUpload($account)
        );
    }

    /**
     * Record an Uploaded Document
     *
     * After uploading directly to Cloudinary, the client reports back what
     * Cloudinary returned. Before persisting anything, we recompute
     * Cloudinary's own signature over `public_id` + `version` using our API
     * secret and compare it against the `signature` the client is
     * claiming - only a payload Cloudinary itself produced can match.
     *
     * @subgroup Record the Result
     *
     * @subgroupDescription A forged or tampered payload fails signature verification and is rejected with 422, never silently trusted.
     *
     * @urlParam account int required The account ID. Example: 1
     *
     * @bodyParam public_id string required The `public_id` Cloudinary assigned (matches the one we signed). Example: 6e4b6e0a-6f0a-4a2b-8f3f-8f0e6e4b6e0a
     * @bodyParam version int required The `version` Cloudinary returned for the uploaded asset. Example: 1699999999
     * @bodyParam signature string required The `signature` Cloudinary returned in its upload response. Example: 9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3f7f1a2b
     * @bodyParam secure_url string required The `secure_url` Cloudinary returned for the uploaded asset. Example: https://res.cloudinary.com/demo/image/upload/v1699999999/accounts/1/documents/6e4b6e0a-6f0a-4a2b-8f3f-8f0e6e4b6e0a.jpg
     * @bodyParam original_filename string The original filename on the client, for display purposes only. Example: passport.jpg
     *
     * @response 201 scenario="Document recorded" {
     *   "status": "success",
     *   "message": "Document recorded successfully",
     *   "data": {
     *     "id": 1,
     *     "public_id": "accounts/1/documents/6e4b6e0a-6f0a-4a2b-8f3f-8f0e6e4b6e0a",
     *     "secure_url": "https://res.cloudinary.com/demo/image/upload/v1699999999/accounts/1/documents/6e4b6e0a-6f0a-4a2b-8f3f-8f0e6e4b6e0a.jpg",
     *     "original_filename": "passport.jpg",
     *     "created_at": "2026-08-01T09:00:01.000000Z"
     *   }
     * }
     * @response 422 scenario="Signature does not match (forged or tampered payload)" {
     *   "status": "error",
     *   "message": "The upload could not be verified. It may have failed or been tampered with."
     * }
     */
    public function store(StoreAccountDocumentRequest $request, Account $account): JsonResponse
    {
        $document = $this->accountDocumentService->store($account, $request->validated());

        return ApiResponse::success(
            'Document recorded successfully',
            new AccountDocumentResource($document),
            Response::HTTP_CREATED
        );
    }
}
