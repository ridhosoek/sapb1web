<?php

namespace App\Application\Purchasing;

use App\Domain\Sap\Enums\IntegrationStatus;
use App\Exceptions\SapSubmissionException;
use App\Integration\Sap\Documents\PurchaseRequestGateway;
use App\Integration\Sap\Mapping\SapFieldMapper;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\SapCommand;
use App\Models\SapDocument;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * TD-14: manual recovery of a Purchase Request that was sent without the transaction UUID. A Super Admin names the
 * SAP DocEntry; the portal reads it again, compares everything it sent, looks for other requests that could be the
 * same one, and records the link only when nothing contradicts it. It only reads SAP and never posts.
 */
class LinkPurchaseRequestToSap
{
    public const MIN_REASON_LENGTH = 20;

    public const CHECK_LABELS = [
        'doc_entry' => 'DocEntry sesuai isian',
        'doc_num' => 'DocNum sesuai isian',
        'unused' => 'DocEntry belum dipakai dokumen portal lain',
        'not_cancelled' => 'Tidak dibatalkan di SAP',
        'document_status' => 'Status buka/tutup dikenal',
        'doc_date' => 'Tanggal dokumen sama',
        'lines' => 'Semua baris sama (item, qty, gudang, jumlah baris)',
        'comments' => 'Komentar sama',
        'created_time' => 'Waktu dibuat cocok dengan percobaan portal',
        'uuid' => 'Tidak membawa UUID transaksi lain',
    ];

    /** What another SAP request has to satisfy to be an equally good answer. */
    private const COMPETING_CHECKS = ['not_cancelled', 'doc_date', 'lines', 'comments', 'created_time', 'uuid'];

    public function __construct(
        private readonly PurchaseRequestGateway $gateway,
        private readonly ReconcilePurchaseRequest $reconcile,
        private readonly RecordPostedPurchaseRequest $recordPostedPurchaseRequest,
        private readonly SapCompanyContext $companies,
        private readonly SapFieldMapper $fields,
    ) {}

    /**
     * Reads SAP and compares. Writes nothing.
     *
     * @return array{
     *     problems: list<string>,
     *     portal: array<string, mixed>|null,
     *     selected: array<string, mixed>|null,
     *     others: list<array<string, mixed>>,
     *     candidate_window: array{from: string, to: string}|null
     * }
     */
    public function review(SapDocument $document, int $docEntry, string $docNum): array
    {
        $command = $this->commandFor($document);
        $review = ['problems' => $this->ineligibility($document, $command), 'portal' => null, 'selected' => null, 'others' => [], 'candidate_window' => null];

        if ($review['problems'] !== []) {
            return $review;
        }

        $payload = $command->request_payload;
        $attemptedAt = $command->last_attempt_at->toImmutable();
        $window = [
            'from' => $attemptedAt->toDateString(),
            'to' => $attemptedAt->addDays(max(0, (int) config('sap.purchase_request.manual_link.candidate_window_days', 7)))->toDateString(),
        ];
        $review['candidate_window'] = $window;
        $review['portal'] = [
            'attempted_at' => $attemptedAt->toDateTimeString(),
            'DocDate' => PurchaseRequestSapMatch::date($payload['DocDate']),
            'Comments' => (string) ($payload['Comments'] ?? ''),
            'DocumentLines' => $this->lines($payload['DocumentLines']),
        ];

        $this->companies->select($document->company_db);

        try {
            $detail = $this->gateway->find($docEntry, $command->transaction_uuid);
            $found = collect($this->gateway->findCreatedBetween($window['from'], $window['to'], $command->transaction_uuid));
        } catch (Throwable $exception) {
            report($exception);
            $review['problems'][] = 'SAP tidak dapat dibaca atau jawabannya tidak lengkap, sehingga tidak ada yang dapat ditautkan.';

            return $review;
        }

        $holder = $this->holder($document, $command, $docEntry);
        $checks = [
            'doc_entry' => (int) ($detail['DocEntry'] ?? 0) === $docEntry,
            'doc_num' => (string) ($detail['DocNum'] ?? '') === $docNum,
            'unused' => $holder === null,
            ...$this->checks($command, $detail),
        ];
        $review['selected'] = [...$this->snapshot($detail), 'checks' => $checks, 'held_by' => $holder];

        foreach (array_keys($checks, false, true) as $failed) {
            $review['problems'][] = "DocEntry {$docEntry} gagal diperiksa: ".self::CHECK_LABELS[$failed].($failed === 'unused' ? " ({$holder})" : '').'.';
        }

        if (! $found->contains(fn (array $row): bool => (int) $row['DocEntry'] === $docEntry)) {
            $review['problems'][] = "Pencarian kandidat {$window['from']} s.d. {$window['to']} tidak memuat DocEntry {$docEntry}, sehingga kandidat lain tidak dapat dipastikan.";
        }

        $review['others'] = $this->otherCandidates($document, $command, $found, $docEntry);

        foreach ($review['others'] as $other) {
            $isCompeting = collect(self::COMPETING_CHECKS)->every(fn (string $check): bool => $other['checks'][$check]);

            if ($isCompeting && $other['held_by'] === null) {
                $review['problems'][] = "Pemetaan ambigu: DocEntry {$other['DocEntry']} juga memenuhi seluruh kriteria.";
            }
        }

        foreach ($this->competingPortalRequests($command, $detail) as $documentId) {
            $review['problems'][] = "Pemetaan ambigu: dokumen portal #{$documentId} dengan isi yang sama juga menunggu hasil SAP.";
        }

        return $review;
    }

    /**
     * Checks everything again against SAP and records the link. Repeating a successful link changes nothing.
     *
     * @param  list<int>  $reviewedDocEntries  every other candidate the administrator confirms having examined
     * @return array{linked: bool, problems: list<string>}
     */
    public function link(SapDocument $document, int $docEntry, string $docNum, array $reviewedDocEntries, string $reason, User $actor, ?string $ipAddress = null): array
    {
        $command = $this->commandFor($document);

        if ($command === null) {
            return ['linked' => false, 'problems' => $this->ineligibility($document, null)];
        }

        $lock = Cache::lock(ReconcilePurchaseRequest::lockKey($command), ReconcilePurchaseRequest::LOCK_SECONDS);

        if (! $lock->get()) {
            return ['linked' => false, 'problems' => ['Dokumen ini sedang diperiksa oleh proses lain. Tunggu beberapa menit, lalu periksa lagi.']];
        }

        try {
            return $this->checkAndRecord($document->refresh(), $command->refresh(), $docEntry, $docNum, $reviewedDocEntries, trim($reason), $actor, $ipAddress);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<int>  $reviewedDocEntries
     * @return array{linked: bool, problems: list<string>}
     */
    private function checkAndRecord(SapDocument $document, SapCommand $command, int $docEntry, string $docNum, array $reviewedDocEntries, string $reason, User $actor, ?string $ipAddress): array
    {
        if ($document->integration_status === IntegrationStatus::InSync
            && $command->status === IntegrationStatus::InSync
            && (int) $document->sap_doc_entry === $docEntry
            && (string) $document->sap_doc_num === $docNum) {
            return ['linked' => true, 'problems' => []];
        }

        $review = $this->review($document, $docEntry, $docNum);
        $problems = $review['problems'];

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            $problems[] = 'Tuliskan alasan memilih DocEntry ini (minimal '.self::MIN_REASON_LENGTH.' karakter).';
        }

        $expected = collect($review['others'])->pluck('DocEntry')->sort()->values()->all();
        $reviewed = collect($reviewedDocEntries)->map(fn (mixed $entry): int => (int) $entry)->unique()->sort()->values()->all();

        if ($review['selected'] !== null && $expected !== $reviewed) {
            $problems[] = $expected === []
                ? 'Daftar kandidat berubah sejak diperiksa. Periksa ulang.'
                : 'Konfirmasikan pemeriksaan setiap kandidat lain (DocEntry '.implode(', ', $expected).'). Daftarnya bisa berubah sejak diperiksa.';
        }

        $evidence = [
            'request_uuid' => $command->transaction_uuid,
            'sap_doc_num' => $docNum,
            'reason' => $reason,
            'candidate_window' => $review['candidate_window'],
            'portal' => $review['portal'],
            'selected' => $review['selected'],
            'other_candidates' => $review['others'],
            'reviewed_candidates' => $reviewed,
        ];

        if ($problems === []) {
            $attempts = $command->attempts;
            $attemptedAt = $command->last_attempt_at;
            $payload = $command->request_payload;

            try {
                $this->recordPostedPurchaseRequest->handle(
                    document: $document,
                    command: $command,
                    docEntry: $docEntry,
                    docNum: $docNum,
                    actorId: $actor->id,
                    auditEvent: 'purchase_request.manually_linked',
                    auditMetadata: $evidence,
                    ipAddress: $ipAddress,
                    isClosedInSap: PurchaseRequestSapMatch::closedInSap($review['selected']['DocumentStatus']) === true,
                    guard: function (SapDocument $lockedDocument, SapCommand $lockedCommand) use ($attempts, $attemptedAt, $payload, $docEntry): void {
                        if ($lockedDocument->integration_status !== IntegrationStatus::PostingUnknown
                            || $lockedCommand->status !== IntegrationStatus::PostingUnknown
                            || $lockedCommand->attempts !== $attempts
                            || ! $lockedCommand->last_attempt_at?->equalTo($attemptedAt)
                            || $lockedCommand->request_payload != $payload) {
                            throw new SapSubmissionException('Status command berubah sejak diperiksa. Periksa ulang.');
                        }

                        if ($this->holder($lockedDocument, $lockedCommand, $docEntry) !== null) {
                            throw new SapSubmissionException('DocEntry sudah dipakai dokumen portal lain.');
                        }
                    },
                );

                return ['linked' => true, 'problems' => []];
            } catch (SapSubmissionException $exception) {
                $problems[] = $exception->getMessage();
            } catch (Throwable $exception) {
                report($exception);
                $problems[] = 'Pencatatan di portal gagal; tidak ada yang berubah. Periksa log aplikasi, lalu coba lagi.';
            }
        }

        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'event' => 'purchase_request.manual_link_rejected',
            'auditable_type' => SapDocument::class,
            'auditable_id' => $document->id,
            'metadata' => ['company_db' => $document->company_db, 'sap_doc_entry' => $docEntry, ...$evidence, 'problems' => $problems],
            'ip_address' => $ipAddress,
        ]);

        return ['linked' => false, 'problems' => $problems];
    }

    private function commandFor(SapDocument $document): ?SapCommand
    {
        return SapCommand::query()
            ->where('company_db', $document->company_db)
            ->where('transaction_uuid', $document->transaction_uuid)
            ->first();
    }

    /** @return list<string> */
    private function ineligibility(SapDocument $document, ?SapCommand $command): array
    {
        if ($document->document_type !== 'PURCHASE_REQUEST'
            || $document->sap_entity !== 'PurchaseRequests'
            || $command === null
            || $command->command_type !== ReconcilePurchaseRequest::COMMAND_TYPE
            || $command->sap_entity !== 'PurchaseRequests') {
            return ['Dokumen ini tidak memiliki command Purchase Request yang cocok.'];
        }

        if ($document->integration_status !== IntegrationStatus::PostingUnknown || $command->status !== IntegrationStatus::PostingUnknown) {
            return ['Tautan manual hanya untuk Purchase Request yang dokumen dan command-nya berstatus POSTING_UNKNOWN.'];
        }

        if ($document->approval()->where('status', 'APPROVED')->doesntExist()) {
            return ['Purchase Request ini belum disetujui.'];
        }

        if ($this->reconcile->sentUuidField($command) !== null) {
            return ['Payload memuat UUID transaksi. Gunakan rekonsiliasi berdasarkan UUID.'];
        }

        if (blank(data_get($command->request_payload, 'DocDate'))
            || blank(data_get($command->request_payload, 'DocumentLines'))
            || $command->last_attempt_at === null) {
            return ['Payload atau waktu percobaan tidak tersimpan, sehingga tidak ada yang dapat dibandingkan.'];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $sapDocument
     * @return array<string, bool>
     */
    private function checks(SapCommand $command, array $sapDocument): array
    {
        $payload = $command->request_payload;
        $field = $this->fields->optionalField('web_transaction_id');
        $sentLines = PurchaseRequestSapMatch::lineSignature($payload['DocumentLines'] ?? null);
        $uuid = $field === null ? null : ($sapDocument[$field] ?? null);

        return [
            'not_cancelled' => ($sapDocument['Cancelled'] ?? null) === 'tNO',
            'document_status' => PurchaseRequestSapMatch::closedInSap($sapDocument['DocumentStatus'] ?? null) !== null,
            'doc_date' => PurchaseRequestSapMatch::date($sapDocument['DocDate'] ?? null) === PurchaseRequestSapMatch::date($payload['DocDate'] ?? null),
            'lines' => PurchaseRequestSapMatch::lineSignature($sapDocument['DocumentLines'] ?? null) === $sentLines,
            'comments' => PurchaseRequestSapMatch::comments($sapDocument['Comments'] ?? null) === PurchaseRequestSapMatch::comments($payload['Comments'] ?? null),
            'created_time' => $this->wasCreatedByTheAttempt($command, $sapDocument),
            'uuid' => blank($uuid) || strcasecmp((string) $uuid, $command->transaction_uuid) === 0,
        ];
    }

    /** SAP stores the creation time to the minute, so it may read slightly earlier than the portal attempt. */
    private function wasCreatedByTheAttempt(SapCommand $command, array $sapDocument): bool
    {
        $createdAt = PurchaseRequestSapMatch::createdAt($sapDocument);
        $attemptedAt = $command->last_attempt_at?->toImmutable();

        return $createdAt !== null
            && $attemptedAt !== null
            && $createdAt->betweenIncluded(
                $attemptedAt->subMinutes((int) config('sap.purchase_request.manual_link.early_tolerance_minutes', 2)),
                $attemptedAt->addMinutes((int) config('sap.purchase_request.manual_link.max_creation_delay_minutes', 10)),
            );
    }

    /**
     * Every other SAP request created in the window with exactly the lines the portal sent, whatever else differs.
     *
     * @param  Collection<int, array<string, mixed>>  $found
     * @return list<array<string, mixed>>
     */
    private function otherCandidates(SapDocument $document, SapCommand $command, Collection $found, int $docEntry): array
    {
        $sentLines = PurchaseRequestSapMatch::lineSignature($command->request_payload['DocumentLines']);

        return $found
            ->reject(fn (array $row): bool => (int) $row['DocEntry'] === $docEntry)
            ->filter(fn (array $row): bool => PurchaseRequestSapMatch::lineSignature($row['DocumentLines'] ?? null) === $sentLines)
            ->map(fn (array $row): array => [
                ...$this->snapshot($row),
                'checks' => $this->checks($command, $row),
                'held_by' => $this->holder($document, $command, (int) $row['DocEntry']),
            ])
            ->sortBy('DocEntry')
            ->values()
            ->all();
    }

    /**
     * Other uncertain portal requests that this SAP document would fit just as well.
     *
     * @param  array<string, mixed>  $sapDocument
     * @return list<int|string> portal document ids
     */
    private function competingPortalRequests(SapCommand $command, array $sapDocument): array
    {
        return SapCommand::query()
            ->where('company_db', $command->company_db)
            ->where('command_type', ReconcilePurchaseRequest::COMMAND_TYPE)
            ->whereIn('status', [IntegrationStatus::Posting->value, IntegrationStatus::PostingUnknown->value])
            ->whereKeyNot($command->id)
            ->get()
            ->filter(function (SapCommand $other) use ($sapDocument): bool {
                if (blank(data_get($other->request_payload, 'DocumentLines'))) {
                    return false;
                }

                $checks = $this->checks($other, $sapDocument);

                return $checks['doc_date'] && $checks['lines'] && $checks['comments'] && $checks['created_time'] && $checks['uuid'];
            })
            ->map(fn (SapCommand $other): int|string => SapDocument::query()
                ->forCompany($other->company_db)
                ->where('transaction_uuid', $other->transaction_uuid)
                ->value('id') ?? "(command {$other->id})")
            ->values()
            ->all();
    }

    /** The portal record, other than this one, that already uses the DocEntry in this company. */
    private function holder(SapDocument $document, SapCommand $command, int $docEntry): ?string
    {
        $documentId = SapDocument::query()
            ->forCompany($document->company_db)
            ->where('sap_entity', 'PurchaseRequests')
            ->where('sap_doc_entry', $docEntry)
            ->whereKeyNot($document->id)
            ->value('id');

        if ($documentId !== null) {
            return "dokumen portal #{$documentId}";
        }

        $commandId = SapCommand::query()
            ->where('company_db', $document->company_db)
            ->where('sap_entity', 'PurchaseRequests')
            ->where('sap_doc_entry', $docEntry)
            ->whereKeyNot($command->id)
            ->value('id');

        return $commandId === null ? null : "command SAP #{$commandId}";
    }

    /**
     * @param  array<string, mixed>  $sapDocument
     * @return array<string, mixed> the evidence kept in the audit trail
     */
    private function snapshot(array $sapDocument): array
    {
        $field = $this->fields->optionalField('web_transaction_id');

        return [
            'DocEntry' => (int) ($sapDocument['DocEntry'] ?? 0),
            'DocNum' => (string) ($sapDocument['DocNum'] ?? ''),
            'DocDate' => PurchaseRequestSapMatch::date($sapDocument['DocDate'] ?? null),
            'CreationDate' => PurchaseRequestSapMatch::date($sapDocument['CreationDate'] ?? null),
            'DocTime' => $sapDocument['DocTime'] ?? null,
            'Comments' => (string) ($sapDocument['Comments'] ?? ''),
            'Cancelled' => $sapDocument['Cancelled'] ?? null,
            'DocumentStatus' => $sapDocument['DocumentStatus'] ?? null,
            'transaction_uuid' => $field === null ? null : ($sapDocument[$field] ?? null),
            'DocumentLines' => $this->lines($sapDocument['DocumentLines'] ?? null),
        ];
    }

    /** @return list<array{ItemCode: mixed, Quantity: mixed, WarehouseCode: mixed}> */
    private function lines(mixed $lines): array
    {
        return collect(is_array($lines) ? $lines : [])
            ->map(fn (mixed $line): array => [
                'ItemCode' => data_get($line, 'ItemCode'),
                'Quantity' => data_get($line, 'Quantity'),
                'WarehouseCode' => data_get($line, 'WarehouseCode'),
            ])
            ->values()
            ->all();
    }
}
