<?php

namespace App\Application\Purchasing;

use App\Domain\Sap\Enums\IntegrationStatus;
use App\Domain\Sap\Enums\PurchaseRequestReconciliationOutcome as Outcome;
use App\Integration\Sap\Documents\PurchaseRequestGateway;
use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Integration\Sap\Mapping\SapFieldMapper;
use App\Integration\Sap\SapCompanyContext;
use App\Integration\Sap\SapMetadataInspector;
use App\Models\SapCommand;
use App\Models\SapDocument;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * TD-14: matches a Purchase Request whose POST outcome is not certain with SAP, by the request UUID that was really
 * sent. It only reads SAP. The portal changes status only when exactly one consistent Purchase Request is found;
 * nothing here posts, retries a POST or releases a document.
 */
class ReconcilePurchaseRequest
{
    public const COMMAND_TYPE = 'CREATE_PURCHASE_REQUEST';

    public const LOCK_SECONDS = 180;

    /** One lock per command, shared with the manual link, so that only one of them works on it at a time. */
    public static function lockKey(SapCommand $command): string
    {
        return "sap:purchase-request-reconcile:{$command->id}";
    }

    public function __construct(
        private readonly PurchaseRequestGateway $gateway,
        private readonly RecordPostedPurchaseRequest $recordPostedPurchaseRequest,
        private readonly SapCompanyContext $companies,
        private readonly SapMetadataInspector $metadata,
        private readonly SapFieldMapper $fields,
    ) {}

    /** The configured UUID field, only when the stored request really carried this command's UUID in it. */
    public function sentUuidField(SapCommand $command): ?string
    {
        $field = $this->fields->optionalField('web_transaction_id');

        return $field !== null
            && Str::isUuid($command->transaction_uuid)
            && data_get($command->request_payload, $field) === $command->transaction_uuid
                ? $field
                : null;
    }

    public function handle(SapCommand $command, ?User $actor = null, ?string $ipAddress = null): Outcome
    {
        $lock = Cache::lock(self::lockKey($command), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return Outcome::Busy;
        }

        try {
            return $this->reconcile($command->refresh(), $actor, $ipAddress);
        } finally {
            $lock->release();
        }
    }

    private function reconcile(SapCommand $command, ?User $actor, ?string $ipAddress): Outcome
    {
        if ($command->command_type !== self::COMMAND_TYPE
            || $command->sap_entity !== 'PurchaseRequests'
            || ! $this->companies->isAllowed($command->company_db)) {
            return Outcome::NotEligible;
        }

        $document = SapDocument::query()
            ->forCompany($command->company_db)
            ->where('transaction_uuid', $command->transaction_uuid)
            ->where('document_type', 'PURCHASE_REQUEST')
            ->where('sap_entity', 'PurchaseRequests')
            ->first();

        if ($document?->integration_status === IntegrationStatus::InSync
            && $command->status === IntegrationStatus::InSync
            && $document->sap_doc_entry !== null) {
            return Outcome::AlreadySynced;
        }

        $uncertain = [IntegrationStatus::Posting, IntegrationStatus::PostingUnknown];

        if (! in_array($command->status, $uncertain, true)) {
            return Outcome::NotEligible;
        }

        if ($command->status === IntegrationStatus::Posting
            && ($command->last_attempt_at === null || $command->last_attempt_at->isAfter($this->inFlightCutoff()))) {
            return Outcome::StillInFlight;
        }

        if ($document === null || ! in_array($document->integration_status, $uncertain, true)) {
            return $this->remember($command, Outcome::NotEligible, null, 'Dokumen Purchase Request yang cocok dengan command ini tidak ada atau statusnya berbeda.');
        }

        $field = $this->sentUuidField($command);

        if ($field === null) {
            return $this->remember($command, Outcome::UuidNotSent);
        }

        $this->companies->select($command->company_db);

        try {
            if (! $this->metadata->supportsEntityProperty('PurchaseRequests', $field)) {
                return $this->remember($command, Outcome::FieldUnsupported, null, "Field {$field} tidak ada pada metadata PurchaseRequests.");
            }

            $matches = $this->gateway->findByTransactionUuid($command->transaction_uuid, $field);

            if ($matches === []) {
                return $this->remember($command, Outcome::NotFound, 0);
            }

            if (count($matches) > 1) {
                return $this->remember($command, Outcome::MultipleMatches, count($matches), 'DocEntry: '.implode(', ', array_column($matches, 'DocEntry')));
            }

            $docEntry = (int) $matches[0]['DocEntry'];
            $detail = $this->gateway->find($docEntry, $command->transaction_uuid);
        } catch (UnexpectedValueException $exception) {
            report($exception);

            return $this->remember($command, Outcome::InvalidResponse, null, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return $this->remember($command, Outcome::LookupFailed, null, $this->describe($exception));
        }

        if ((int) ($detail['DocEntry'] ?? 0) !== $docEntry || ! is_array($detail['DocumentLines'] ?? null)) {
            return $this->remember($command, Outcome::InvalidResponse, 1, "Detail PurchaseRequests({$docEntry}) tidak lengkap.");
        }

        $differences = $this->differences($command, $document, $field, $matches[0], $detail, $docEntry);

        if ($differences !== []) {
            return $this->remember($command, Outcome::DataMismatch, 1, "DocEntry {$docEntry}: ".implode('; ', $differences).'.');
        }

        $documentStatus = $detail['DocumentStatus'] ?? null;
        $isClosedInSap = PurchaseRequestSapMatch::closedInSap($documentStatus);

        if ($isClosedInSap === null) {
            return $this->remember($command, Outcome::InvalidResponse, 1, "DocEntry {$docEntry}: DocumentStatus ".(is_string($documentStatus) && $documentStatus !== '' ? "\"{$documentStatus}\" tidak dikenal." : 'kosong.'));
        }

        $docNum = filled($detail['DocNum'] ?? null) ? (string) $detail['DocNum'] : null;

        try {
            $this->recordPostedPurchaseRequest->handle(
                document: $document,
                command: $command,
                docEntry: $docEntry,
                docNum: $docNum,
                actorId: $actor?->id,
                auditEvent: 'purchase_request.reconciled',
                auditMetadata: [
                    'request_uuid' => $command->transaction_uuid,
                    'sap_doc_num' => $docNum,
                    'sap_document_status' => $documentStatus,
                    'reconciled_from_status' => $command->status->value,
                    'trigger' => $actor === null ? 'SCHEDULER' : 'ADMIN',
                ],
                ipAddress: $ipAddress,
                isClosedInSap: $isClosedInSap,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->remember($command->refresh(), Outcome::RecordingFailed, 1, "DocEntry {$docEntry}: ".$this->describe($exception));
        }

        return $this->remember($command->refresh(), Outcome::Reconciled, 1);
    }

    /**
     * Everything SAP returns that contradicts what the portal sent. A cancelled request, or one edited in SAP before
     * it was matched, needs a person.
     *
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $detail
     * @return list<string>
     */
    private function differences(SapCommand $command, SapDocument $document, string $field, array $match, array $detail, int $docEntry): array
    {
        $differences = [];
        $payload = $command->request_payload ?? [];

        if (strcasecmp((string) ($detail[$field] ?? ''), $command->transaction_uuid) !== 0) {
            $differences[] = 'UUID pada detail berbeda';
        }

        if (($detail['Cancelled'] ?? null) !== 'tNO') {
            $differences[] = 'dokumen dibatalkan atau status batalnya tidak diketahui';
        }

        if (filled($match['DocNum'] ?? null) && (string) $match['DocNum'] !== (string) ($detail['DocNum'] ?? '')) {
            $differences[] = 'DocNum tidak konsisten';
        }

        if (PurchaseRequestSapMatch::date($detail['DocDate'] ?? null) !== PurchaseRequestSapMatch::date($payload['DocDate'] ?? null)) {
            $differences[] = 'tanggal dokumen berbeda';
        }

        if (PurchaseRequestSapMatch::lineSignature($detail['DocumentLines']) !== PurchaseRequestSapMatch::lineSignature($payload['DocumentLines'] ?? [])) {
            $differences[] = 'item, jumlah, atau gudang pada baris berbeda';
        }

        if (($command->sap_doc_entry !== null && (int) $command->sap_doc_entry !== $docEntry)
            || ($document->sap_doc_entry !== null && (int) $document->sap_doc_entry !== $docEntry)) {
            $differences[] = 'DocEntry berbeda dari yang pernah diterima portal';
        }

        $heldByAnotherDocument = SapDocument::query()
            ->forCompany($command->company_db)
            ->where('sap_entity', 'PurchaseRequests')
            ->where('sap_doc_entry', $docEntry)
            ->whereKeyNot($document->id)
            ->exists();

        if ($heldByAnotherDocument) {
            $differences[] = 'DocEntry sudah tercatat pada dokumen portal lain';
        }

        return $differences;
    }

    /** Stores the check on the command. The status and the POST error stay exactly as they were. */
    private function remember(SapCommand $command, Outcome $outcome, ?int $matchCount = null, ?string $detail = null): Outcome
    {
        $attempts = (int) $command->reconcile_attempts + 1;
        $mayRepeat = $outcome->isRetryable()
            && $attempts < (int) config('sap.purchase_request.reconcile.max_attempts', 8);
        $delay = min(
            (int) config('sap.purchase_request.reconcile.backoff_seconds', 600) * (2 ** min($attempts - 1, 10)),
            (int) config('sap.purchase_request.reconcile.max_backoff_seconds', 21600),
        );

        $command->update([
            'reconcile_attempts' => $attempts,
            'last_reconciled_at' => now(),
            'next_reconcile_at' => $mayRepeat ? now()->addSeconds($delay) : null,
            'reconcile_outcome' => $outcome->value,
            'reconcile_match_count' => $matchCount,
            'reconcile_detail' => $detail === null ? null : Str::limit($detail, 490),
        ]);

        return $outcome;
    }

    private function describe(Throwable $exception): string
    {
        return $exception instanceof SapBusinessException
            ? "HTTP {$exception->httpStatus}: ".($exception->technicalMessage ?? $exception->getMessage())
            : class_basename($exception).': '.$exception->getMessage();
    }

    private function inFlightCutoff(): \DateTimeInterface
    {
        return now()->subSeconds(max(0, (int) config('sap.purchase_request.reconcile.min_age_seconds', 300)));
    }
}
