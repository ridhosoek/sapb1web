<?php

namespace App\Application\Receiving;

use App\Domain\Sap\Enums\IntegrationStatus;
use App\Integration\Sap\Documents\GoodsReceiptGateway;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\SapCommand;
use App\Models\SapDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual recovery of an uncertain Goods Receipt PO command by a Super Admin (TD-05).
 *
 * Time passing and empty reads never release a command. A release needs a written statement of the authoritative
 * investigation, two successful SAP reads, and it is audited. It only lifts the block: a new receipt still goes
 * through the full live validation (PO status, approval status, open quantity) with a new request UUID.
 */
class ReleaseUncertainGoodsReceipt
{
    public function __construct(
        private readonly ReconcileGoodsReceipt $reconcile,
        private readonly GoodsReceiptGateway $goodsReceipts,
        private readonly SapCompanyContext $companies,
    ) {}

    /**
     * @return array{outcome: 'reconciled'|'released', goods_receipt: SapDocument|null, unlinked_goods_receipts: list<string>}
     */
    public function handle(SapCommand $command, User $actor, string $evidence, ?string $ipAddress = null): array
    {
        $evidence = trim($evidence);

        if (mb_strlen($evidence) < 20) {
            throw ValidationException::withMessages(['evidence' => 'Tuliskan bukti investigasi otoritatif (minimal 20 karakter): siapa yang memeriksa, di mana, dan apa hasilnya.']);
        }

        if ($command->command_type !== SapCommand::CREATE_GOODS_RECEIPT_PO
            || ! in_array($command->status, [IntegrationStatus::Posting, IntegrationStatus::PostingUnknown], true)
            || $command->base_document_entry === null) {
            throw ValidationException::withMessages(['command' => 'Command ini bukan GRPO yang tertahan dengan hasil belum pasti.']);
        }

        $this->companies->select($command->company_db);
        $previousStatus = $command->status->value;

        try {
            $goodsReceipt = $this->reconcile->handle($command, $actor, $ipAddress);
        } catch (\Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['command' => 'Pencarian GRPO berdasarkan UUID gagal atau ambigu, sehingga command tidak dilepas. '.$exception->getMessage()]);
        }

        if ($goodsReceipt !== null) {
            return ['outcome' => 'reconciled', 'goods_receipt' => $goodsReceipt, 'unlinked_goods_receipts' => []];
        }

        try {
            $sapReceipts = collect($this->goodsReceipts->findAllByPurchaseOrder((int) $command->base_document_entry));
        } catch (\Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['command' => 'Daftar GRPO untuk PO ini tidak dapat dibaca dari SAP, sehingga command tidak dilepas. Coba lagi saat koneksi SAP normal.']);
        }

        $knownEntries = SapDocument::query()
            ->forCompany($command->company_db)
            ->where('sap_entity', 'PurchaseDeliveryNotes')
            ->pluck('sap_doc_entry')
            ->map(fn (mixed $entry): int => (int) $entry);
        $unlinked = $sapReceipts
            ->reject(fn (array $receipt): bool => in_array(strtoupper((string) ($receipt['document']['Cancelled'] ?? '')), ['Y', 'C', 'TYES'], true))
            ->reject(fn (array $receipt): bool => $knownEntries->contains((int) $receipt['document']['DocEntry']))
            ->map(fn (array $receipt): string => (string) ($receipt['document']['DocNum'] ?? $receipt['document']['DocEntry']))
            ->values()
            ->all();

        DB::transaction(function () use ($command, $actor, $evidence, $ipAddress, $previousStatus, $unlinked): void {
            $command->update([
                'status' => IntegrationStatus::SapError,
                'last_error' => 'Dilepas Super Admin '.$actor->name.' pada '.now()->toDateTimeString().' setelah investigasi: '.mb_substr($evidence, 0, 500),
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'event' => 'goods_receipt_po.recovery_released',
                'auditable_type' => SapCommand::class,
                'auditable_id' => $command->id,
                'metadata' => [
                    'company_db' => $command->company_db,
                    'purchase_order_doc_entry' => $command->base_document_entry,
                    'request_uuid' => $command->transaction_uuid,
                    'previous_status' => $previousStatus,
                    'attempts' => $command->attempts,
                    'uuid_lookup_possible' => $this->reconcile->canLookUp($command),
                    'evidence' => $evidence,
                    'unlinked_goods_receipts' => $unlinked,
                ],
                'ip_address' => $ipAddress,
            ]);
        });

        return ['outcome' => 'released', 'goods_receipt' => null, 'unlinked_goods_receipts' => $unlinked];
    }
}
