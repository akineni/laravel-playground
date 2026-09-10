<?php

namespace App\Http\Resources;

use App\Models\AccountDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccountDocument */
class AccountDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'secure_url' => $this->secure_url,
            'original_filename' => $this->original_filename,
            'created_at' => $this->created_at,
        ];
    }
}
