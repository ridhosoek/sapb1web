<?php

namespace App\Console\Commands;

use App\Application\Approvals\PostApprovedDocument;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Models\DocumentApproval;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('sap:post-approved {--limit=25 : Maximum approved documents to process}')]
#[Description('Post approved PR and Goods Issue documents that are still pending SAP')]
class PostApprovedDocumentsCommand extends Command
{
    public function handle(PostApprovedDocument $post): int
    {
        $approvals = DocumentApproval::query()
            ->where('status', 'APPROVED')
            ->whereHas('document', fn ($query) => $query->where('integration_status', IntegrationStatus::PendingPost))
            ->with(['document', 'steps.approver', 'submitter'])
            ->oldest('decided_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
        $succeeded = 0;

        foreach ($approvals as $approval) {
            $actor = $approval->steps->where('status', 'APPROVED')->sortByDesc('step_order')->first()?->approver
                ?? $approval->submitter;

            try {
                $post->handle($approval->document, $actor);
                $succeeded++;
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("Document #{$approval->sap_document_id}: {$exception->getMessage()}");
            }
        }

        $this->info("Posted {$succeeded} of {$approvals->count()} approved document(s).");

        return self::SUCCESS;
    }
}
