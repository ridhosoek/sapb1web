<?php

namespace App\Integration\Sap\Documents;

class PurchaseQuotationGateway extends SapDocumentGateway
{
    /**
     * @return list<array{document: array<string, mixed>, lines: list<array<string, mixed>>}>
     */
    public function findByPurchaseRequest(int $purchaseRequestDocEntry): array
    {
        return $this->findByBaseDocument(
            (int) config('sap.object_types.purchase_request'),
            $purchaseRequestDocEntry,
        );
    }

    protected function endpointKey(): string
    {
        return 'purchase_quotations';
    }
}
