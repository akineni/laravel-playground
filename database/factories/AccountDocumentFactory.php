<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountDocument>
 */
class AccountDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $publicId = 'accounts/documents/'.fake()->uuid();

        return [
            'account_id' => Account::factory(),
            'public_id' => $publicId,
            'secure_url' => "https://res.cloudinary.com/demo/image/upload/v1699999999/{$publicId}.jpg",
            'original_filename' => fake()->lexify('document-????.pdf'),
        ];
    }
}
