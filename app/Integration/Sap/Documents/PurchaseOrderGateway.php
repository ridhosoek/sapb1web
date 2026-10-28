<?php

namespace App\Integration\Sap\Documents;

class PurchaseOrderGateway extends SapDocumentGateway
{
    /**
     * @return list<array{document: array<string, mixed>, lines: list<array<string, mixed>>}>
     */
    public function findByPurchaseQuotation(int $purchaseQuotationDocEntry): array
    {
        return $this->findByBaseDocument(
            (int) config('sap.object_types.purchase_quotation'),
            $purchaseQuotationDocEntry,
        );
    }

    protected function endpointKey(): string
    {
        return 'purchase_orders';
    }
}
