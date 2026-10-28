<?php

namespace App\Integration\Sap\Documents;

class GoodsReceiptGateway extends SapDocumentGateway
{
    protected function endpointKey(): string
    {
        return 'goods_receipts';
    }
}
