<?php

namespace App\Application\Approvals;

use App\Application\Inventory\PostApprovedGoodsIssue;
use App\Application\Purchasing\PostApprovedPurchaseRequest;
use App\Exceptions\SapSubmissionException;
use App\Models\SapDocument;
use App\Models\User;

class PostApprovedDocument
{
    public function __construct(
        private readonly PostApprovedPurchaseRequest $postPurchaseRequest,
        private readonly PostApprovedGoodsIssue $postGoodsIssue,
    ) {}

    public function handle(SapDocument $document, User $actor): SapDocument
    {
        $document->loadMissing('approval');

        if ($document->approval?->status !== 'APPROVED') {
            throw new SapSubmissionException('Dokumen belum mendapatkan approval final.');
        }

        return match ($document->document_type) {
            'PURCHASE_REQUEST' => $this->postPurchaseRequest->handle($document, $actor),
            'GOODS_ISSUE' => $this->postGoodsIssue->handle($document, $actor),
            default => throw new SapSubmissionException('Jenis dokumen approval tidak didukung.'),
        };
    }
}
