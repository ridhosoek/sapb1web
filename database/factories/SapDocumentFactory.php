<?php

namespace Database\Factories;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Models\SapDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SapDocument>
 */
class SapDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_db' => 'TEST_DB',
            'document_type' => 'PURCHASE_ORDER',
            'sap_entity' => 'PurchaseOrders',
            'sap_doc_entry' => $this->faker->unique()->numberBetween(1, 999999),
            'sap_doc_num' => (string) $this->faker->numberBetween(260000000, 269999999),
            'source' => 'SAP',
            'sap_business_status' => BusinessStatus::Open,
            'integration_status' => IntegrationStatus::InSync,
            'created_from_web' => false,
            'normalized_data' => [
                'progress_status' => 'READY_FOR_GRPO',
                'lines' => [],
            ],
        ];
    }
}
