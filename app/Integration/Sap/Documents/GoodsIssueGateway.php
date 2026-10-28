<?php

namespace App\Integration\Sap\Documents;

class GoodsIssueGateway extends SapDocumentGateway
{
    protected function endpointKey(): string
    {
        return 'goods_issues';
    }
}
