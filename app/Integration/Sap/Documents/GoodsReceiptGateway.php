<?php

namespace App\Integration\Sap\Documents;

class GoodsReceiptGateway extends SapDocumentGateway
{
    /**
     * @return list<array{document: array<string, mixed>, lines: list<array<string, mixed>>}>
     */
    public function findByPurchaseOrder(int $purchaseOrderDocEntry): array
    {
        return $this->findByBaseDocument(
            (int) config('sap.object_types.purchase_order', 22),
            $purchaseOrderDocEntry,
        );
    }

    protected function endpointKey(): string
    {
        return 'goods_receipts';
    }
}
