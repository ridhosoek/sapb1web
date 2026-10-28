<?php

namespace Database\Factories;

use App\Models\SapDocument;
use App\Models\SapDocumentAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SapDocumentAttachment>
 */
class SapDocumentAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sap_document_id' => SapDocument::factory(),
            'uploaded_by' => User::factory(),
            'disk' => 'local',
            'path' => 'goods-receipt-po/'.$this->faker->uuid().'.jpg',
            'original_name' => $this->faker->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => $this->faker->numberBetween(50_000, 2_000_000),
            'caption' => $this->faker->optional()->sentence(),
        ];
    }
}
