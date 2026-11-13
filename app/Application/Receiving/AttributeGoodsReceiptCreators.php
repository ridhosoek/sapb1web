<?php

namespace App\Application\Receiving;

use App\Models\AuditLog;
use App\Models\SapCommand;
use App\Models\SapDocument;
use Illuminate\Support\Collection;

/**
 * Finds the first sender of Goods Receipt POs and commands stored before `web_created_by` / `requested_by` existed
 * (TD-11). Only exact evidence counts, and every piece of it must name the same user:
 *
 * - a `goods_receipt_po.posted` audit entry written for that very document, company and SAP DocEntry,
 * - the sender already stored on the command with the same request UUID,
 * - a `goods_receipt_po.posting_unknown` audit entry for that very command and UUID.
 *
 * The portal stores a command with the request UUID before every POST and never deletes it. A document whose UUID
 * has no command, or whose command belongs to another company or another SAP DocEntry, is therefore inconsistent
 * and stays without an owner, even when an audit entry names a user.
 *
 * The actor of `goods_receipt_po.reconciled`, a photo uploader, an approver and the Purchase Order are never
 * evidence. A row whose evidence is missing, inconsistent or names more than one user is reported, not attributed.
 */
class AttributeGoodsReceiptCreators
{
    public const PROVEN = 'proven';

    public const MULTIPLE_SENDERS = 'multiple_senders';

    public const NO_EVIDENCE = 'no_evidence';

    public const INCONSISTENT_COMMAND = 'inconsistent_command';

    private const REASONS = [
        self::MULTIPLE_SENDERS => 'Bukti menyebut lebih dari satu pengguna (audit posted, pengirim pada command, atau audit posting_unknown).',
        self::NO_EVIDENCE => 'Tidak ada audit posted yang cocok tepat dengan dokumen, dan tidak ada pengirim pada command/UUID yang sama.',
        self::INCONSISTENT_COMMAND => 'Command dengan UUID dokumen ini tidak ditemukan, atau company/DocEntry SAP-nya berbeda dari dokumen.',
    ];

    /**
     * @return array{
     *     documents_attributed: int,
     *     documents_unattributed: list<array{id: int, company_db: string, sap_doc_num: string|null, outcome: string, reason: string}>,
     *     documents_by_company: array<string, array{proven: int, multiple_senders: int, no_evidence: int, inconsistent_command: int}>,
     *     attributed_document_ids: array<string, list<int>>,
     *     commands_attributed: int,
     *     commands_unattributed: int,
     * }
     */
    public function handle(?string $companyDb = null, bool $apply = false): array
    {
        $documentsAttributed = 0;
        $documentsUnattributed = [];
        $documentsByCompany = [];
        $attributedDocumentIds = [];
        $provenDocumentOwners = [];
        $unprovenRequests = [];

        SapDocument::query()
            ->where('document_type', 'GOODS_RECEIPT_PO')
            ->where('created_from_web', true)
            ->whereNull('web_created_by')
            ->when($companyDb !== null, fn ($query) => $query->forCompany($companyDb))
            ->orderBy('id')
            ->chunkById(200, function (Collection $documents) use ($apply, &$documentsAttributed, &$documentsUnattributed, &$documentsByCompany, &$attributedDocumentIds, &$provenDocumentOwners, &$unprovenRequests): void {
                foreach ($documents as $document) {
                    $evidence = $this->documentEvidence($document);
                    $requestKey = $document->company_db.'|'.$document->transaction_uuid;
                    $documentsByCompany[$document->company_db] ??= [
                        self::PROVEN => 0,
                        self::MULTIPLE_SENDERS => 0,
                        self::NO_EVIDENCE => 0,
                        self::INCONSISTENT_COMMAND => 0,
                    ];
                    $documentsByCompany[$document->company_db][$evidence['outcome']]++;

                    if ($evidence['owner'] === null) {
                        $documentsUnattributed[] = [
                            'id' => $document->id,
                            'company_db' => $document->company_db,
                            'sap_doc_num' => $document->sap_doc_num,
                            'outcome' => $evidence['outcome'],
                            'reason' => self::REASONS[$evidence['outcome']],
                        ];

                        if (filled($document->transaction_uuid)) {
                            $unprovenRequests[$requestKey] = true;
                        }

                        continue;
                    }

                    $documentsAttributed++;
                    $attributedDocumentIds[$document->company_db][] = $document->id;

                    if (filled($document->transaction_uuid)) {
                        $provenDocumentOwners[$requestKey] = $evidence['owner'];
                    }

                    if ($apply) {
                        $document->update(['web_created_by' => $evidence['owner']]);
                    }
                }
            });

        ksort($documentsByCompany);
        $commandsAttributed = 0;
        $commandsUnattributed = 0;

        SapCommand::query()
            ->where('command_type', SapCommand::CREATE_GOODS_RECEIPT_PO)
            ->whereNull('requested_by')
            ->when($companyDb !== null, fn ($query) => $query->where('company_db', $companyDb))
            ->orderBy('id')
            ->chunkById(200, function (Collection $commands) use ($apply, $provenDocumentOwners, $unprovenRequests, &$commandsAttributed, &$commandsUnattributed): void {
                foreach ($commands as $command) {
                    $requestKey = $command->company_db.'|'.$command->transaction_uuid;
                    // A command never gets a sender that its own document was refused.
                    $owner = isset($unprovenRequests[$requestKey])
                        ? null
                        : ($provenDocumentOwners[$requestKey]
                            ?? $this->storedDocumentCreator($command)
                            ?? $this->onlySender($this->sendersOfUncertainPost($command)));

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
            'documents_by_company' => $documentsByCompany,
            'attributed_document_ids' => $attributedDocumentIds,
            'commands_attributed' => $commandsAttributed,
            'commands_unattributed' => $commandsUnattributed,
        ];
    }

    /**
     * @return array{owner: int|null, outcome: string}
     */
    private function documentEvidence(SapDocument $document): array
    {
        $senders = $this->actorsOfPostedAudit($document);

        if (filled($document->transaction_uuid)) {
            $command = SapCommand::query()->where('transaction_uuid', $document->transaction_uuid)->first();

            if ($command === null || ! $this->commandCreatedDocument($command, $document)) {
                return ['owner' => null, 'outcome' => self::INCONSISTENT_COMMAND];
            }

            $senders = $senders
                ->merge($this->sendersOfUncertainPost($command))
                ->when($command->requested_by !== null, fn (Collection $senders) => $senders->push((int) $command->requested_by))
                ->unique()
                ->values();
        }

        return match (true) {
            $senders->isEmpty() => ['owner' => null, 'outcome' => self::NO_EVIDENCE],
            $senders->count() > 1 => ['owner' => null, 'outcome' => self::MULTIPLE_SENDERS],
            default => ['owner' => $senders->first(), 'outcome' => self::PROVEN],
        };
    }

    /**
     * `posted` is written in the sender's own request, inside the transaction that stores the document.
     *
     * @return Collection<int, int>
     */
    private function actorsOfPostedAudit(SapDocument $document): Collection
    {
        if ($document->sap_doc_entry === null) {
            return collect();
        }

        return AuditLog::query()
            ->where('event', 'goods_receipt_po.posted')
            ->where('auditable_type', SapDocument::class)
            ->where('auditable_id', $document->id)
            ->whereNotNull('actor_id')
            ->get()
            ->toBase()
            ->filter(fn (AuditLog $audit): bool => data_get($audit->metadata, 'company_db') === $document->company_db
                && (int) data_get($audit->metadata, 'sap_doc_entry') === (int) $document->sap_doc_entry)
            ->map(fn (AuditLog $audit): int => (int) $audit->actor_id)
            ->unique()
            ->values();
    }

    private function commandCreatedDocument(SapCommand $command, SapDocument $document): bool
    {
        return $command->company_db === $document->company_db
            && $command->command_type === SapCommand::CREATE_GOODS_RECEIPT_PO
            && $command->sap_doc_entry !== null
            && $document->sap_doc_entry !== null
            && (int) $command->sap_doc_entry === (int) $document->sap_doc_entry;
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
     * `posting_unknown` is written in the sender's own request, so it names the sender of that command and UUID.
     *
     * @return Collection<int, int>
     */
    private function sendersOfUncertainPost(SapCommand $command): Collection
    {
        return AuditLog::query()
            ->where('event', 'goods_receipt_po.posting_unknown')
            ->where('metadata->request_uuid', $command->transaction_uuid)
            ->whereNotNull('actor_id')
            ->get()
            ->toBase()
            ->filter(fn (AuditLog $audit): bool => (int) data_get($audit->metadata, 'command_id') === (int) $command->id
                && data_get($audit->metadata, 'company_db') === $command->company_db)
            ->map(fn (AuditLog $audit): int => (int) $audit->actor_id)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, int>  $senders
     */
    private function onlySender(Collection $senders): ?int
    {
        return $senders->count() === 1 ? $senders->first() : null;
    }
}
