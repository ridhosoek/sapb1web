<?php

namespace App\Application\Receiving;

use App\Models\AuditLog;
use App\Models\SapCommand;
use App\Models\SapDocument;
use Illuminate\Support\Collection;

/**
 * Finds the first sender of Goods Receipt POs and commands stored before `web_created_by` / `requested_by` existed
 * (TD-11). Only exact evidence counts:
 *
 * - a `goods_receipt_po.posted` audit entry written for that very document, or
 * - the sender already stored on the command with the same request UUID, or
 * - a `goods_receipt_po.posting_unknown` audit entry for that very command and UUID.
 *
 * The actor of `goods_receipt_po.reconciled`, a photo uploader and the Purchase Order are never evidence. When the
 * evidence is missing or names more than one user, the row stays without an owner and is reported.
 */
class AttributeGoodsReceiptCreators
{
    /**
     * @return array{
     *     documents_attributed: int,
     *     documents_unattributed: list<array{id: int, company_db: string, sap_doc_num: string|null, reason: string}>,
     *     commands_attributed: int,
     *     commands_unattributed: int,
     * }
     */
    public function handle(?string $companyDb = null, bool $apply = false): array
    {
        $documentsAttributed = 0;
        $documentsUnattributed = [];
        $provenDocumentOwners = [];

        SapDocument::query()
            ->where('document_type', 'GOODS_RECEIPT_PO')
            ->where('created_from_web', true)
            ->whereNull('web_created_by')
            ->when($companyDb !== null, fn ($query) => $query->forCompany($companyDb))
            ->orderBy('id')
            ->chunkById(200, function (Collection $documents) use ($apply, &$documentsAttributed, &$documentsUnattributed, &$provenDocumentOwners): void {
                foreach ($documents as $document) {
                    $owner = $this->documentCreator($document);

                    if ($owner === null) {
                        $documentsUnattributed[] = [
                            'id' => $document->id,
                            'company_db' => $document->company_db,
                            'sap_doc_num' => $document->sap_doc_num,
                            'reason' => 'Tidak ada audit posted yang cocok tepat dengan dokumen, dan tidak ada pengirim pada command/UUID yang sama.',
                        ];

                        continue;
                    }

                    $documentsAttributed++;

                    if (filled($document->transaction_uuid)) {
                        $provenDocumentOwners[$document->company_db.'|'.$document->transaction_uuid] = $owner;
                    }

                    if ($apply) {
                        $document->update(['web_created_by' => $owner]);
                    }
                }
            });

        $commandsAttributed = 0;
        $commandsUnattributed = 0;

        SapCommand::query()
            ->where('command_type', SapCommand::CREATE_GOODS_RECEIPT_PO)
            ->whereNull('requested_by')
            ->when($companyDb !== null, fn ($query) => $query->where('company_db', $companyDb))
            ->orderBy('id')
            ->chunkById(200, function (Collection $commands) use ($apply, $provenDocumentOwners, &$commandsAttributed, &$commandsUnattributed): void {
                foreach ($commands as $command) {
                    $owner = $provenDocumentOwners[$command->company_db.'|'.$command->transaction_uuid]
                        ?? $this->storedDocumentCreator($command)
                        ?? $this->senderOfUncertainPost($command);

                    if ($owner === null) {
                        $commandsUnattributed++;

                        continue;
                    }

                    $commandsAttributed++;

                    if ($apply) {
                        $command->update(['requested_by' => $owner]);
                    }
                }
            });

        return [
            'documents_attributed' => $documentsAttributed,
            'documents_unattributed' => $documentsUnattributed,
            'commands_attributed' => $commandsAttributed,
            'commands_unattributed' => $commandsUnattributed,
        ];
    }

    private function documentCreator(SapDocument $document): ?int
    {
        $postedBy = AuditLog::query()
            ->where('event', 'goods_receipt_po.posted')
            ->where('auditable_type', SapDocument::class)
            ->where('auditable_id', $document->id)
            ->whereNotNull('actor_id')
            ->get()
            ->filter(fn (AuditLog $audit): bool => data_get($audit->metadata, 'company_db') === $document->company_db
                && (int) data_get($audit->metadata, 'sap_doc_entry') === (int) $document->sap_doc_entry)
            ->pluck('actor_id')
            ->unique();

        if ($postedBy->count() === 1) {
            return (int) $postedBy->first();
        }

        if ($postedBy->count() > 1 || blank($document->transaction_uuid)) {
            return null;
        }

        $command = SapCommand::query()
            ->where('company_db', $document->company_db)
            ->where('command_type', SapCommand::CREATE_GOODS_RECEIPT_PO)
            ->where('transaction_uuid', $document->transaction_uuid)
            ->first();

        if ($command === null || (int) $command->sap_doc_entry !== (int) $document->sap_doc_entry) {
            return null;
        }

        return $command->requested_by !== null
            ? (int) $command->requested_by
            : $this->senderOfUncertainPost($command);
    }

    private function storedDocumentCreator(SapCommand $command): ?int
    {
        if ($command->sap_doc_entry === null) {
            return null;
        }

        $owner = SapDocument::query()
            ->forCompany($command->company_db)
            ->where('document_type', 'GOODS_RECEIPT_PO')
            ->where('created_from_web', true)
            ->where('transaction_uuid', $command->transaction_uuid)
            ->where('sap_doc_entry', $command->sap_doc_entry)
            ->value('web_created_by');

        return $owner !== null ? (int) $owner : null;
    }

    /**
     * `posting_unknown` is written in the sender's own request, so it names the sender, but only when every entry
     * for this command and UUID names the same user.
     */
    private function senderOfUncertainPost(SapCommand $command): ?int
    {
        $senders = AuditLog::query()
            ->where('event', 'goods_receipt_po.posting_unknown')
            ->where('metadata->request_uuid', $command->transaction_uuid)
            ->whereNotNull('actor_id')
            ->get()
            ->filter(fn (AuditLog $audit): bool => (int) data_get($audit->metadata, 'command_id') === (int) $command->id
                && data_get($audit->metadata, 'company_db') === $command->company_db)
            ->pluck('actor_id')
            ->unique();

        return $senders->count() === 1 ? (int) $senders->first() : null;
    }
}
