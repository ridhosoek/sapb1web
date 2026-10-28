<?php

namespace App\Integration\Sap\Documents;

class PurchaseRequestGateway extends SapDocumentGateway
{
    protected function endpointKey(): string
    {
        return 'purchase_requests';
    }
}
