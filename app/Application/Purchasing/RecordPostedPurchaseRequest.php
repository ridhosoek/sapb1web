<?php

namespace App\Application\Purchasing;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Domain\Sap\Enums\WatchStatus;
use App\Exceptions\SapSubmissionException;
use App\Models\AuditLog;
use App\Models\SapCommand;
use App\Models\SapDocument;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The single way a Purchase Request that SAP holds is written to the portal, used by the normal POST and by the
 * reconciliation (TD-14). Everything is written in one transaction on locked rows.
 */
class RecordPostedPurchaseRequest
{
    /**
     * Progress of a request that SAP had already closed when the portal first learned of it. Being closed says nothing
     * about a quotation, Purchase Order or receipt, so no later step is claimed.
     */
    public const CLOSED_IN_SAP = 'CLOSED_IN_SAP';

    /**
     * @param  array<string, mixed>  $auditMetadata
     * @param  Closure(SapDocument, SapCommand): void|null  $guard  called on the locked rows before anything is
     *                                                              written; throwing from it writes nothing
     */
    public function handle(
        SapDocument $document,
        SapCommand $command,
        int $docEntry,
        ?string $docNum,
        ?int $actorId,
        string $auditEvent,
        array $auditMetadata = [],
        ?string $ipAddress = null,
        bool $isClosedInSap = false,
        ?Closure $guard = null,
    ): SapDocument {
        return DB::transaction(function () use ($document, $command, $docEntry, $docNum, $actorId, $auditEvent, $auditMetadata, $ipAddress, $isClosedInSap, $guard): SapDocument {
            $document = SapDocument::query()->lockForUpdate()->findOrFail($document->id);
            $command = SapCommand::query()->lockForUpdate()->findOrFail($command->id);

            if ($guard !== null) {
                $guard($document, $command);
            }

            if ($document->integration_status === IntegrationStatus::InSync && (int) $document->sap_doc_entry === $docEntry) {
                return $document;
            }

            $uncertain = [IntegrationStatus::Posting, IntegrationStatus::PostingUnknown];

            if (! in_array($document->integration_status, $uncertain, true)
                || ! in_array($command->status, $uncertain, true)
                || ($document->sap_doc_entry !== null && (int) $document->sap_doc_entry !== $docEntry)) {
                throw new SapSubmissionException('Status Purchase Request berubah sehingga hasil SAP tidak dicatat otomatis. Perlu investigasi.');
            }

            $data = $document->normalized_data ?? [];
            $data['approval_status'] = 'APPROVED';
            $data['progress_status'] = $isClosedInSap ? self::CLOSED_IN_SAP : 'WAITING_QUOTATION';
            $document->update([
                'sap_doc_entry' => $docEntry,
                'sap_doc_num' => $docNum,
                'sap_business_status' => $isClosedInSap ? BusinessStatus::Closed : BusinessStatus::Open,
                'integration_status' => IntegrationStatus::InSync,
                'normalized_data' => $data,
            ]);
            $document->watcher()->updateOrCreate([], [
                'watch_status' => $isClosedInSap ? WatchStatus::Stopped : WatchStatus::Active,
                'next_poll_at' => $isClosedInSap ? null : now(),
                'consecutive_failures' => 0,
                'last_error' => null,
            ]);
            $command->update([
                'status' => IntegrationStatus::InSync,
                'sap_doc_entry' => $docEntry,
                'sap_doc_num' => $docNum,
                'last_error' => null,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actorId,
                'event' => $auditEvent,
                'auditable_type' => SapDocument::class,
                'auditable_id' => $document->id,
                'metadata' => [
                    'company_db' => $document->company_db,
                    'sap_doc_entry' => $docEntry,
                    ...$auditMetadata,
                ],
                'ip_address' => $ipAddress,
            ]);

            return $document->fresh();
        });
    }
}
