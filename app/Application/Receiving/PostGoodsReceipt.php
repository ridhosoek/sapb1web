<?php

namespace App\Application\Receiving;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Domain\Sap\Enums\WatchStatus;
use App\Exceptions\SapSubmissionException;
use App\Integration\Sap\Documents\GoodsReceiptGateway;
use App\Integration\Sap\Documents\GoodsReceiptPayloadBuilder;
use App\Integration\Sap\Documents\PurchaseOrderGateway;
use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\SapCommand;
use App\Models\SapDocument;
use App\Models\SapDocumentRelation;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PostGoodsReceipt
{
    public function __construct(
        private readonly GoodsReceiptGateway $goodsReceipts,
        private readonly PurchaseOrderGateway $purchaseOrders,
        private readonly GoodsReceiptPayloadBuilder $payloads,
        private readonly SapCompanyContext $companies,
    ) {}

    /**
     * @param  array{receipt_date: string, lines: list<array{line_num: int, quantity: int|float|string}>}  $data
     */
    public function handle(SapDocument $purchaseOrder, array $data, User $user, ?string $ipAddress = null): SapDocument
    {
        $lockName = 'sap:goods-receipt:'.hash('sha256', $purchaseOrder->company_db.':'.$purchaseOrder->sap_doc_entry);

        return Cache::lock($lockName, 60)->block(10, fn (): SapDocument => $this->post(
            purchaseOrder: $purchaseOrder->fresh(),
            data: $data,
            user: $user,
            ipAddress: $ipAddress,
        ));
    }

    /**
     * @param  array{receipt_date: string, lines: list<array{line_num: int, quantity: int|float|string}>}  $data
     */
    private function post(SapDocument $purchaseOrder, array $data, User $user, ?string $ipAddress): SapDocument
    {
        $existingReceipt = $this->existingReceipt($purchaseOrder);

        if ($existingReceipt) {
            return $existingReceipt;
        }

        if ($purchaseOrder->document_type !== 'PURCHASE_ORDER' || ! $purchaseOrder->sap_doc_entry) {
            throw ValidationException::withMessages([
                'lines' => 'Purchase Order belum memiliki referensi SAP yang valid.',
            ]);
        }

        $this->companies->select($purchaseOrder->company_db);
        $livePurchaseOrder = $this->purchaseOrders->find((int) $purchaseOrder->sap_doc_entry);
        $this->ensurePurchaseOrderIsOpen($livePurchaseOrder);
        $receiptLines = $this->receiptLines($purchaseOrder, $livePurchaseOrder, $data['lines']);
        $payload = $this->payloads->build(
            purchaseOrder: $purchaseOrder,
            purchaseOrderData: $livePurchaseOrder,
            receiptDate: $data['receipt_date'],
            receiptLines: $receiptLines,
            user: $user,
        );
        $transactionUuid = (string) Str::uuid();
        $command = SapCommand::query()->create([
            'company_db' => $purchaseOrder->company_db,
            'transaction_uuid' => $transactionUuid,
            'command_type' => 'CREATE_GOODS_RECEIPT_PO',
            'sap_entity' => 'PurchaseDeliveryNotes',
            'status' => IntegrationStatus::Posting,
            'request_payload' => $payload,
            'attempts' => 1,
            'last_attempt_at' => now(),
        ]);

        try {
            $response = $this->goodsReceipts->create($payload);
            $docEntry = $this->requiredDocEntry($response);

            return DB::transaction(function () use ($purchaseOrder, $livePurchaseOrder, $receiptLines, $data, $user, $ipAddress, $transactionUuid, $command, $response, $docEntry): SapDocument {
                $goodsReceipt = SapDocument::query()->updateOrCreate(
                    [
                        'company_db' => $purchaseOrder->company_db,
                        'sap_entity' => 'PurchaseDeliveryNotes',
                        'sap_doc_entry' => $docEntry,
                    ],
                    [
                        'pool_id' => $purchaseOrder->pool_id,
                        'department_id' => $purchaseOrder->department_id,
                        'document_type' => 'GOODS_RECEIPT_PO',
                        'sap_doc_num' => filled($response['DocNum'] ?? null) ? (string) $response['DocNum'] : null,
                        'source' => 'WEB',
                        'fpk_number' => $purchaseOrder->fpk_number,
                        'transaction_uuid' => $transactionUuid,
                        'sap_business_status' => BusinessStatus::Open,
                        'integration_status' => IntegrationStatus::InSync,
                        'created_from_web' => true,
                        'normalized_data' => [
                            'base_document_type' => 'PURCHASE_ORDER',
                            'base_document_entry' => $purchaseOrder->sap_doc_entry,
                            'base_document_num' => $purchaseOrder->sap_doc_num,
                            'receipt_date' => $data['receipt_date'],
                            'vendor_code' => $livePurchaseOrder['CardCode'] ?? null,
                            'vendor_name' => $livePurchaseOrder['CardName'] ?? null,
                            'document' => $response,
                            'lines' => $receiptLines,
                        ],
                    ],
                );

                SapDocumentRelation::query()->updateOrCreate(
                    [
                        'parent_document_id' => $purchaseOrder->id,
                        'child_document_id' => $goodsReceipt->id,
                        'relation_type' => 'GOODS_RECEIPT_PO',
                    ],
                    [
                        'discovered_by' => 'WEB_POST',
                        'relation_metadata' => [
                            'base_type' => (int) config('sap.object_types.purchase_order', 22),
                            'base_entry' => $purchaseOrder->sap_doc_entry,
                            'line_count' => count($receiptLines),
                        ],
                    ],
                );

                $this->updatePurchaseOrder($purchaseOrder, $livePurchaseOrder, $receiptLines);
                $this->updatePurchaseRequestProgress($purchaseOrder, $goodsReceipt);
                $command->update([
                    'status' => IntegrationStatus::InSync,
                    'sap_doc_entry' => $docEntry,
                    'sap_doc_num' => $goodsReceipt->sap_doc_num,
                    'last_error' => null,
                ]);
                AuditLog::query()->create([
                    'actor_id' => $user->id,
                    'event' => 'goods_receipt_po.posted',
                    'auditable_type' => SapDocument::class,
                    'auditable_id' => $goodsReceipt->id,
                    'metadata' => [
                        'company_db' => $purchaseOrder->company_db,
                        'purchase_order_id' => $purchaseOrder->id,
                        'purchase_order_doc_num' => $purchaseOrder->sap_doc_num,
                        'sap_doc_entry' => $docEntry,
                        'sap_doc_num' => $goodsReceipt->sap_doc_num,
                    ],
                    'ip_address' => $ipAddress,
                ]);

                return $goodsReceipt->fresh();
            });
        } catch (SapBusinessException $exception) {
            $command->update([
                'status' => IntegrationStatus::SapError,
                'last_error' => $exception->technicalMessage ?? $exception->getMessage(),
            ]);

            throw $exception;
        } catch (ConnectionException $exception) {
            $command->update([
                'status' => IntegrationStatus::PostingUnknown,
                'last_error' => 'Koneksi terputus saat POST GRPO; status SAP perlu direkonsiliasi sebelum mencoba ulang.',
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            $command->update([
                'status' => IntegrationStatus::PostingUnknown,
                'last_error' => 'Hasil POST GRPO tidak dapat dipastikan; status SAP perlu direkonsiliasi sebelum mencoba ulang.',
            ]);

            throw $exception;
        }
    }

    private function existingReceipt(SapDocument $purchaseOrder): ?SapDocument
    {
        return $purchaseOrder->childRelations()
            ->where('relation_type', 'GOODS_RECEIPT_PO')
            ->with('childDocument')
            ->first()?->childDocument;
    }

    /** @param array<string, mixed> $purchaseOrderData */
    private function ensurePurchaseOrderIsOpen(array $purchaseOrderData): void
    {
        if (in_array($purchaseOrderData['Cancelled'] ?? 'N', ['Y', 'tYES', 'Yes'], true)) {
            throw ValidationException::withMessages(['lines' => 'Purchase Order telah dibatalkan di SAP.']);
        }

        if (! in_array($purchaseOrderData['DocumentStatus'] ?? 'O', ['O', 'bost_Open'], true)) {
            throw ValidationException::withMessages(['lines' => 'Purchase Order sudah ditutup atau seluruh barang telah diterima di SAP.']);
        }
    }

    /**
     * @param  array<string, mixed>  $purchaseOrderData
     * @param  list<array{line_num: int, quantity: int|float|string}>  $requestedLines
     * @return list<array{line_num: int, quantity: float, open_quantity: float, item_code: string, item_name: string, warehouse_code: string}>
     */
    private function receiptLines(SapDocument $purchaseOrder, array $purchaseOrderData, array $requestedLines): array
    {
        $liveLines = collect($purchaseOrderData['DocumentLines'] ?? []);

        if ($liveLines->isEmpty()) {
            $liveLines = collect(data_get($purchaseOrder->normalized_data, 'lines', []));
        }

        $liveLines = $liveLines
            ->filter(fn (mixed $line): bool => is_array($line))
            ->keyBy(fn (array $line): int => (int) ($line['LineNum'] ?? $line['line_num'] ?? -1));
        $resolved = [];

        foreach ($requestedLines as $index => $requestedLine) {
            $lineNumber = (int) $requestedLine['line_num'];
            $line = $liveLines->get($lineNumber);

            if (! is_array($line)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.line_num" => "Baris PO {$lineNumber} tidak ditemukan di SAP.",
                ]);
            }

            if (($line['LineStatus'] ?? null) === 'bost_Close') {
                throw ValidationException::withMessages([
                    "lines.{$index}.quantity" => "Baris PO {$lineNumber} sudah ditutup di SAP.",
                ]);
            }

            $openQuantity = $this->openQuantity($line);
            $quantity = (float) $requestedLine['quantity'];

            if ($openQuantity <= 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.quantity" => "Baris PO {$lineNumber} tidak memiliki sisa qty terbuka.",
                ]);
            }

            if ($quantity > $openQuantity + 0.000001) {
                $formattedOpenQuantity = number_format($openQuantity, 2, ',', '.');
                throw ValidationException::withMessages([
                    "lines.{$index}.quantity" => "Qty diterima melebihi sisa PO ({$formattedOpenQuantity}).",
                ]);
            }

            $warehouseCode = trim((string) ($line['WarehouseCode'] ?? $line['warehouse_code'] ?? ''));

            if ($warehouseCode === '') {
                throw ValidationException::withMessages([
                    "lines.{$index}.line_num" => "Warehouse baris PO {$lineNumber} tidak tersedia.",
                ]);
            }

            $resolved[] = [
                'line_num' => $lineNumber,
                'quantity' => $quantity,
                'open_quantity' => $openQuantity,
                'item_code' => (string) ($line['ItemCode'] ?? $line['item_code'] ?? ''),
                'item_name' => (string) ($line['ItemDescription'] ?? $line['item_name'] ?? $line['description'] ?? ''),
                'warehouse_code' => $warehouseCode,
            ];
        }

        return $resolved;
    }

    /** @param array<string, mixed> $line */
    private function openQuantity(array $line): float
    {
        return max(0, (float) ($line['RemainingOpenQuantity']
            ?? $line['OpenQuantity']
            ?? $line['OpenQty']
            ?? $line['open_quantity']
            ?? $line['Quantity']
            ?? $line['quantity']
            ?? 0));
    }

    /**
     * @param  array<string, mixed>  $livePurchaseOrder
     * @param  list<array{line_num: int, quantity: float, open_quantity: float, item_code: string, item_name: string, warehouse_code: string}>  $receiptLines
     */
    private function updatePurchaseOrder(SapDocument $purchaseOrder, array $livePurchaseOrder, array $receiptLines): void
    {
        $receivedByLine = collect($receiptLines)->keyBy('line_num');
        $sourceLines = collect($livePurchaseOrder['DocumentLines'] ?? data_get($purchaseOrder->normalized_data, 'lines', []));
        $remainingLines = $sourceLines->map(function (array $line) use ($receivedByLine): array {
            $lineNumber = (int) ($line['LineNum'] ?? $line['line_num'] ?? -1);
            $received = (float) data_get($receivedByLine->get($lineNumber), 'quantity', 0);
            $remaining = max(0, $this->openQuantity($line) - $received);
            $line['RemainingOpenQuantity'] = $remaining;
            $line['open_quantity'] = $remaining;

            return $line;
        })->values();
        $openLineCount = $remainingLines->filter(fn (array $line): bool => $this->openQuantity($line) > 0.000001)->count();
        $normalizedData = $purchaseOrder->normalized_data ?? [];
        $documentData = $livePurchaseOrder;
        unset($documentData['DocumentLines']);
        $normalizedData['document'] = $documentData;
        $normalizedData['lines'] = $remainingLines->all();
        $normalizedData['open_lines'] = $openLineCount;
        $normalizedData['progress_status'] = $openLineCount === 0 ? 'RECEIVED' : 'PARTIALLY_RECEIVED';

        $purchaseOrder->update([
            'sap_business_status' => $openLineCount === 0 ? BusinessStatus::Closed : BusinessStatus::Open,
            'integration_status' => IntegrationStatus::InSync,
            'normalized_data' => $normalizedData,
        ]);
    }

    private function updatePurchaseRequestProgress(SapDocument $purchaseOrder, SapDocument $goodsReceipt): void
    {
        $purchaseQuotation = $purchaseOrder->parentRelations()
            ->where('relation_type', 'PURCHASE_ORDER')
            ->with('parentDocument')
            ->first()
            ?->parentDocument;
        $purchaseRequest = $purchaseQuotation?->parentRelations()
            ->where('relation_type', 'PURCHASE_QUOTATION')
            ->with('parentDocument.watcher')
            ->first()
            ?->parentDocument;

        if (! $purchaseRequest) {
            return;
        }

        $purchaseOrder->refresh();
        $isFullyReceived = $purchaseOrder->sap_business_status === BusinessStatus::Closed;
        $normalizedData = $purchaseRequest->normalized_data ?? [];
        $normalizedData['progress_status'] = $isFullyReceived ? 'FULLY_RECEIVED' : 'PARTIALLY_RECEIVED';
        $normalizedData['goods_receipt_doc_entry'] = $goodsReceipt->sap_doc_entry;
        $normalizedData['goods_receipt_doc_num'] = $goodsReceipt->sap_doc_num;
        $normalizedData['goods_receipt_synced_at'] = now()->toIso8601String();
        $purchaseRequest->update(['normalized_data' => $normalizedData]);

        $purchaseRequest->watcher?->update([
            'watch_status' => $isFullyReceived ? WatchStatus::Stopped : WatchStatus::Active,
            'last_fingerprint' => hash('sha256', 'GRPO:'.$goodsReceipt->sap_doc_entry),
            'last_polled_at' => now(),
            'next_poll_at' => $isFullyReceived ? null : now()->addSeconds(max(30, (int) config('sap.watchers.interval_seconds', 120))),
            'consecutive_failures' => 0,
            'last_error' => null,
        ]);
    }

    /** @param array<string, mixed> $response */
    private function requiredDocEntry(array $response): int
    {
        $docEntry = $response['DocEntry'] ?? null;

        if (! is_int($docEntry) && ! ctype_digit((string) $docEntry)) {
            throw new SapSubmissionException('SAP tidak mengembalikan DocEntry untuk Goods Receipt PO.');
        }

        return (int) $docEntry;
    }
}
