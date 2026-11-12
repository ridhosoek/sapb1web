<?php

namespace App\Application\Receiving;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Domain\Sap\Enums\PurchaseOrderAuthorization;
use App\Domain\Sap\Enums\SerialReceiptRequirement;
use App\Domain\Sap\Enums\WatchStatus;
use App\Exceptions\SapSubmissionException;
use App\Integration\Sap\Documents\GoodsReceiptGateway;
use App\Integration\Sap\Documents\GoodsReceiptPayloadBuilder;
use App\Integration\Sap\Documents\PurchaseOrderGateway;
use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Integration\Sap\MasterData\ItemGateway;
use App\Integration\Sap\MasterData\ItemGroupGateway;
use App\Integration\Sap\MasterData\SerialNumberConfigurationGateway;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\SapCommand;
use App\Models\SapDocument;
use App\Models\SapDocumentRelation;
use App\Models\SapItemCache;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
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
        private readonly ItemGateway $items,
        private readonly ItemGroupGateway $itemGroups,
        private readonly SerialNumberConfigurationGateway $serialNumberConfiguration,
        private readonly RecordGoodsReceipt $recordGoodsReceipt,
        private readonly ReconcileGoodsReceipt $reconcileGoodsReceipt,
    ) {}

    /**
     * @param  array{receipt_date: string, lines: list<array<string, mixed>>, receipt_request_uuid?: string}  $data
     */
    public function handle(SapDocument $purchaseOrder, array $data, User $user, ?string $ipAddress = null): SapDocument
    {
        $lockName = 'sap:goods-receipt:'.hash('sha256', $purchaseOrder->company_db.':'.$purchaseOrder->sap_doc_entry);
        $partialReceipts = (bool) config('sap.goods_receipt.partial_receipts_enabled');

        return Cache::lock($lockName, 60)->block(10, fn (): SapDocument => $partialReceipts
            ? $this->postPartial($purchaseOrder->fresh(), $data, $user, $ipAddress)
            : $this->post($purchaseOrder->fresh(), $data, $user, $ipAddress));
    }

    /**
     * TD-05 path (partial receipts enabled): one request UUID per receipt, several receipts per Purchase Order
     * until the open quantity is gone, and no new POST while an earlier POST for the PO is uncertain.
     *
     * @param  array{receipt_date: string, lines: list<array<string, mixed>>, receipt_request_uuid?: string}  $data
     */
    private function postPartial(SapDocument $purchaseOrder, array $data, User $user, ?string $ipAddress): SapDocument
    {
        $requestUuid = strtolower(trim((string) ($data['receipt_request_uuid'] ?? '')));

        if (! Str::isUuid($requestUuid)) {
            throw ValidationException::withMessages([
                'receipt_request_uuid' => 'ID permintaan penerimaan tidak valid. Muat ulang halaman lalu kirim kembali.',
            ]);
        }

        if ($purchaseOrder->document_type !== 'PURCHASE_ORDER' || ! $purchaseOrder->sap_doc_entry) {
            throw ValidationException::withMessages([
                'lines' => 'Purchase Order belum memiliki referensi SAP yang valid.',
            ]);
        }

        $command = SapCommand::query()->where('transaction_uuid', $requestUuid)->first();

        if ($command !== null) {
            $replayed = $this->replayExistingRequest($command, $purchaseOrder, $user);

            if ($replayed !== null) {
                return $replayed;
            }
        }

        $this->ensureNoUncertainReceipt($purchaseOrder, $command);
        $this->companies->select($purchaseOrder->company_db);
        $livePurchaseOrder = $this->purchaseOrders->find((int) $purchaseOrder->sap_doc_entry);
        $this->ensurePurchaseOrderIsOpen($livePurchaseOrder);
        $this->ensurePurchaseOrderAuthorizationAllowsReceipt($livePurchaseOrder);

        if ($command !== null) {
            // A definite rejection is being retried with the same UUID: make sure SAP did not store it after all.
            $reconciled = $this->reconcileGoodsReceipt->handle($command, $user, $ipAddress);

            if ($reconciled !== null) {
                return $reconciled;
            }
        }

        $receiptLines = $this->resolveReceiptLines($purchaseOrder, $livePurchaseOrder, $data);
        $payload = $this->payloads->build(
            purchaseOrder: $purchaseOrder,
            purchaseOrderData: $livePurchaseOrder,
            receiptDate: $data['receipt_date'],
            receiptLines: $receiptLines,
            user: $user,
            transactionUuid: $requestUuid,
        );

        if ($command === null) {
            $command = SapCommand::query()->create([
                'company_db' => $purchaseOrder->company_db,
                'transaction_uuid' => $requestUuid,
                'command_type' => SapCommand::CREATE_GOODS_RECEIPT_PO,
                'sap_entity' => 'PurchaseDeliveryNotes',
                'base_document_entry' => (int) $purchaseOrder->sap_doc_entry,
                'requested_by' => $user->id,
                'status' => IntegrationStatus::Posting,
                'request_payload' => $payload,
                'attempts' => 1,
                'last_attempt_at' => now(),
            ]);
        } else {
            $command->update([
                'status' => IntegrationStatus::Posting,
                'request_payload' => $payload,
                'attempts' => $command->attempts + 1,
                'last_attempt_at' => now(),
                'last_error' => null,
            ]);
        }

        try {
            $response = $this->goodsReceipts->create($payload);
            $docEntry = $this->requiredDocEntry($response);
        } catch (SapBusinessException $exception) {
            if ($this->isDefiniteRejection($exception)) {
                $command->update([
                    'status' => IntegrationStatus::SapError,
                    'last_error' => $exception->technicalMessage ?? $exception->getMessage(),
                ]);

                throw $exception;
            }

            $this->markPostingUnknown($command, $purchaseOrder, $user, $ipAddress, "SAP menjawab HTTP {$exception->httpStatus} saat POST GRPO; GRPO mungkin sudah tersimpan. Status perlu direkonsiliasi sebelum mencoba ulang.");

            throw new SapSubmissionException('Hasil POST GRPO tidak dapat dipastikan; status SAP perlu direkonsiliasi sebelum mencoba ulang.', 0, $exception);
        } catch (ConnectionException $exception) {
            $this->markPostingUnknown($command, $purchaseOrder, $user, $ipAddress, 'Koneksi terputus saat POST GRPO; status SAP perlu direkonsiliasi sebelum mencoba ulang.');

            throw $exception;
        } catch (Throwable $exception) {
            $this->markPostingUnknown($command, $purchaseOrder, $user, $ipAddress, 'Hasil POST GRPO tidak dapat dipastikan; status SAP perlu direkonsiliasi sebelum mencoba ulang.');

            throw $exception;
        }

        try {
            return $this->recordGoodsReceipt->handle(
                purchaseOrder: $purchaseOrder,
                command: $command,
                sapDocument: $response,
                receiptLines: $receiptLines,
                receiptDate: $data['receipt_date'],
                discoveredBy: 'WEB_POST',
                auditEvent: 'goods_receipt_po.posted',
                actorId: $user->id,
                ipAddress: $ipAddress,
            );
        } catch (Throwable $exception) {
            if ($command->fresh()?->status !== IntegrationStatus::InSync) {
                $this->markPostingUnknown($command, $purchaseOrder, $user, $ipAddress, "SAP menerima GRPO {$docEntry}, tetapi penyimpanannya di portal gagal; status akan direkonsiliasi berdasarkan UUID.");
            }

            throw $exception;
        }
    }

    /**
     * An existing command for the same UUID decides what a repeated submission may do. Only its first sender may
     * repeat it: anyone else gets neither the receipt nor a second POST (BP-10).
     */
    private function replayExistingRequest(SapCommand $command, SapDocument $purchaseOrder, User $user): ?SapDocument
    {
        if ($command->command_type !== SapCommand::CREATE_GOODS_RECEIPT_PO
            || $command->company_db !== $purchaseOrder->company_db
            || (int) $command->base_document_entry !== (int) $purchaseOrder->sap_doc_entry
            || $command->requested_by === null
            || (int) $command->requested_by !== (int) $user->id) {
            throw ValidationException::withMessages([
                'receipt_request_uuid' => 'ID permintaan penerimaan ini sudah dipakai untuk transaksi lain. Muat ulang halaman lalu kirim kembali.',
            ]);
        }

        if ($command->status === IntegrationStatus::InSync) {
            $goodsReceipt = SapDocument::query()
                ->forCompany($command->company_db)
                ->where('sap_entity', 'PurchaseDeliveryNotes')
                ->where('sap_doc_entry', $command->sap_doc_entry)
                ->first();

            if ($goodsReceipt === null) {
                throw ValidationException::withMessages(['lines' => 'GRPO untuk permintaan ini sudah tercatat tetapi dokumennya tidak ditemukan di portal. Hubungi administrator.']);
            }

            return $goodsReceipt;
        }

        if ($command->status === IntegrationStatus::SapError) {
            return null;
        }

        throw ValidationException::withMessages([
            'lines' => 'Pengiriman GRPO untuk permintaan ini belum dapat dipastikan (status '.$command->status->value.'). Jangan kirim ulang; status harus direkonsiliasi di Integration Monitor.',
        ]);
    }

    private function ensureNoUncertainReceipt(SapDocument $purchaseOrder, ?SapCommand $current): void
    {
        $blocked = SapCommand::query()
            ->uncertainGoodsReceipts($purchaseOrder->company_db, (int) $purchaseOrder->sap_doc_entry)
            ->when($current !== null, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->exists();

        if ($blocked) {
            throw ValidationException::withMessages([
                'lines' => 'Ada pengiriman GRPO untuk Purchase Order ini yang hasilnya belum pasti, sehingga sisa Qty belum dapat dipastikan. Penerimaan baru ditahan sampai statusnya direkonsiliasi atau dilepas Super Admin di Integration Monitor.',
            ]);
        }
    }

    /**
     * Only a 4xx answer (other than 408) proves that SAP did not process the document. Anything else, including a
     * 5xx from SAP or its load balancer, may have been preceded by a successful commit.
     */
    private function isDefiniteRejection(SapBusinessException $exception): bool
    {
        $status = $exception->httpStatus;

        return $status !== null && $status >= 400 && $status < 500 && $status !== 408;
    }

    private function markPostingUnknown(SapCommand $command, SapDocument $purchaseOrder, User $user, ?string $ipAddress, string $reason): void
    {
        $command->update(['status' => IntegrationStatus::PostingUnknown, 'last_error' => $reason]);
        AuditLog::query()->create([
            'actor_id' => $user->id,
            'event' => 'goods_receipt_po.posting_unknown',
            'auditable_type' => SapDocument::class,
            'auditable_id' => $purchaseOrder->id,
            'metadata' => [
                'company_db' => $purchaseOrder->company_db,
                'purchase_order_doc_entry' => $purchaseOrder->sap_doc_entry,
                'command_id' => $command->id,
                'request_uuid' => $command->transaction_uuid,
                'reason' => $reason,
            ],
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * @param  array<string, mixed>  $livePurchaseOrder
     * @param  array{receipt_date: string, lines: list<array<string, mixed>>}  $data
     * @return list<array<string, mixed>>
     */
    private function resolveReceiptLines(SapDocument $purchaseOrder, array $livePurchaseOrder, array $data): array
    {
        $itemCodes = collect($livePurchaseOrder['DocumentLines'] ?? data_get($purchaseOrder->normalized_data, 'lines', []))
            ->filter(fn (mixed $line): bool => is_array($line))
            ->map(fn (array $line): string => trim((string) ($line['ItemCode'] ?? $line['item_code'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $itemMasterData = collect($this->items->findByCodes($itemCodes))->keyBy('ItemCode');
        $itemGroupNumbers = $itemMasterData
            ->pluck('ItemsGroupCode')
            ->filter(fn (mixed $number): bool => is_numeric($number))
            ->map(fn (mixed $number): int => (int) $number)
            ->unique()
            ->values()
            ->all();
        $itemGroupNames = $this->itemGroups->namesByNumbers($itemGroupNumbers);
        $this->cacheItemMasterData($purchaseOrder->company_db, $itemMasterData, $itemGroupNames);
        $serialUniqueField = SerialNumberConfigurationGateway::None;

        if ($itemMasterData->contains(fn (array $item): bool => ($item['ManageSerialNumbers'] ?? null) === 'tYES'
            && ($item['SRIAndBatchManageMethod'] ?? null) === 'bomm_OnEveryTransaction')) {
            try {
                $serialUniqueField = $this->serialNumberConfiguration->uniqueField();
            } catch (Throwable $exception) {
                report($exception);
                $serialUniqueField = SerialNumberConfigurationGateway::None;
            }
        }

        return $this->receiptLines(
            $purchaseOrder,
            $livePurchaseOrder,
            $data['lines'],
            $itemMasterData,
            $itemGroupNames,
            $serialUniqueField,
        );
    }

    /**
     * @param  array{receipt_date: string, lines: list<array<string, mixed>>}  $data
     */
    private function post(SapDocument $purchaseOrder, array $data, User $user, ?string $ipAddress): SapDocument
    {
        $existingReceipt = $this->existingReceipt($purchaseOrder);

        if ($existingReceipt?->isWebGoodsReceiptCreatedBy($user)) {
            return $existingReceipt;
        }

        if ($existingReceipt) {
            // Someone else's receipt (or one made in SAP) is neither handed over nor reported as newly created.
            throw ValidationException::withMessages([
                'lines' => 'Purchase Order sudah memiliki Goods Receipt PO dan tidak dapat diproses kembali melalui portal.',
            ]);
        }

        if ($purchaseOrder->document_type !== 'PURCHASE_ORDER' || ! $purchaseOrder->sap_doc_entry) {
            throw ValidationException::withMessages([
                'lines' => 'Purchase Order belum memiliki referensi SAP yang valid.',
            ]);
        }

        $this->companies->select($purchaseOrder->company_db);
        $livePurchaseOrder = $this->purchaseOrders->find((int) $purchaseOrder->sap_doc_entry);
        $this->ensurePurchaseOrderIsOpen($livePurchaseOrder);
        $this->ensurePurchaseOrderAuthorizationAllowsReceipt($livePurchaseOrder);
        $this->ensurePurchaseOrderHasNoGoodsReceipt($purchaseOrder);
        $receiptLines = $this->resolveReceiptLines($purchaseOrder, $livePurchaseOrder, $data);
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
            'requested_by' => $user->id,
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
                        'web_created_by' => $user->id,
                        'normalized_data' => [
                            'base_document_type' => 'PURCHASE_ORDER',
                            'base_document_entry' => $purchaseOrder->sap_doc_entry,
                            'base_document_num' => $purchaseOrder->sap_doc_num,
                            'receipt_date' => $data['receipt_date'],
                            'vendor_code' => $livePurchaseOrder['CardCode'] ?? null,
                            'vendor_name' => $livePurchaseOrder['CardName'] ?? null,
                            'warehouse_code' => data_get($receiptLines, '0.warehouse_code'),
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
                        'warehouse_code' => data_get($receiptLines, '0.warehouse_code'),
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

    /** @param array<string, mixed> $purchaseOrderData */
    private function ensurePurchaseOrderAuthorizationAllowsReceipt(array $purchaseOrderData): void
    {
        $reason = PurchaseOrderAuthorization::holdReason($purchaseOrderData['AuthorizationStatus'] ?? null);

        if ($reason !== null) {
            throw ValidationException::withMessages(['lines' => "GRPO tidak dikirim ke SAP. {$reason}"]);
        }
    }

    private function ensurePurchaseOrderHasNoGoodsReceipt(SapDocument $purchaseOrder): void
    {
        $hasGoodsReceipt = collect($this->goodsReceipts->findByPurchaseOrder((int) $purchaseOrder->sap_doc_entry))
            ->contains(fn (array $goodsReceipt): bool => ! in_array(
                strtoupper((string) data_get($goodsReceipt, 'document.Cancelled')),
                ['Y', 'C', 'TYES'],
                true,
            ));

        if ($hasGoodsReceipt) {
            throw ValidationException::withMessages([
                'lines' => 'Purchase Order sudah memiliki Goods Receipt PO di SAP dan tidak dapat diproses kembali melalui portal.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $purchaseOrderData
     * @param  list<array<string, mixed>>  $requestedLines
     * @param  Collection<string, array<string, mixed>>  $itemMasterData
     * @param  array<int, string>  $itemGroupNames
     * @return list<array<string, mixed>>
     */
    private function receiptLines(
        SapDocument $purchaseOrder,
        array $purchaseOrderData,
        array $requestedLines,
        Collection $itemMasterData,
        array $itemGroupNames,
        string $serialUniqueField,
    ): array {
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

            $itemCode = trim((string) ($line['ItemCode'] ?? $line['item_code'] ?? ''));
            $item = $itemMasterData->get($itemCode);

            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.line_num" => "Master data item {$itemCode} tidak dapat dibaca dari SAP.",
                ]);
            }

            $itemGroupCode = is_numeric($item['ItemsGroupCode'] ?? null) ? (int) $item['ItemsGroupCode'] : null;
            $itemGroupName = $itemGroupCode !== null ? trim((string) ($itemGroupNames[$itemGroupCode] ?? '')) : '';
            $vehicleField = (string) config('sap.purchase_request.vehicle_costing_field', 'CostingCode3');
            $vehicleCode = trim((string) ($line[$vehicleField] ?? $line['vehicle_code'] ?? ''));
            $managesSerialNumbers = ($item['ManageSerialNumbers'] ?? null) === 'tYES';
            $serialManagementMethod = (string) ($item['SRIAndBatchManageMethod'] ?? '');
            $isTireItem = $itemGroupName === '' ? null : mb_strtoupper($itemGroupName) === 'BAN';
            $serialRequirement = SerialReceiptRequirement::resolve($item['ManageSerialNumbers'] ?? null, $serialManagementMethod, $isTireItem);
            $requiresSerialNumbers = $serialRequirement === SerialReceiptRequirement::SerialRequired;
            $serialNumbers = collect($requestedLine['serial_numbers'] ?? [])
                ->filter(fn (mixed $serialNumber): bool => is_array($serialNumber))
                ->map(fn (array $serialNumber): array => $this->normalizeSerialNumber($serialNumber))
                ->values();

            if ($serialRequirement === SerialReceiptRequirement::Undetermined) {
                throw ValidationException::withMessages([
                    "lines.{$index}.serial_numbers" => "Konfigurasi serial item {$itemCode} belum dapat dipastikan dari SAP (Manage Serial Numbers: ".($item['ManageSerialNumbers'] ?? 'tidak terbaca').', metode: '.($serialManagementMethod !== '' ? $serialManagementMethod : 'tidak terbaca').', grup item: '.($itemGroupName !== '' ? $itemGroupName : 'tidak terbaca').'). GRPO tidak diproses.',
                ]);
            }

            // Konfigurasi serial BAN selain tNO dan On Every Transaction belum diputuskan bisnis, jadi tetap ditolak.
            if ($serialRequirement === SerialReceiptRequirement::Unsupported) {
                throw ValidationException::withMessages([
                    "lines.{$index}.serial_numbers" => "Konfigurasi serial item BAN {$itemCode} di SAP (metode: {$serialManagementMethod}) belum didukung portal. Hubungi administrator SAP sebelum GRPO diproses.",
                ]);
            }

            if ($requiresSerialNumbers) {
                $requiredSerialCount = (int) round($quantity);

                if (abs($quantity - $requiredSerialCount) > 0.000001) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.quantity" => "Qty item serial {$itemCode} harus berupa bilangan bulat.",
                    ]);
                }

                if ($serialNumbers->count() !== $requiredSerialCount) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.serial_numbers" => "Item {$itemCode} membutuhkan {$requiredSerialCount} serial number sesuai Qty Diterima.",
                    ]);
                }

                foreach ($serialNumbers as $serialIndex => $serialNumber) {
                    $hasIdentifier = collect([
                        $serialNumber['manufacturer_serial_number'],
                        $serialNumber['internal_serial_number'],
                        $serialNumber['lot_number'],
                    ])->contains(fn (?string $value): bool => filled($value));

                    if (! $hasIdentifier) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.serial_numbers.{$serialIndex}" => 'Setiap baris serial harus memiliki Nomor Seri Pabrikan, Serial Number, atau Lot Number.',
                        ]);
                    }

                    if ($serialUniqueField !== SerialNumberConfigurationGateway::None
                        && blank($serialNumber[$serialUniqueField] ?? null)) {
                        throw ValidationException::withMessages([
                            "lines.{$index}.serial_numbers.{$serialIndex}.{$serialUniqueField}" => SerialNumberConfigurationGateway::label($serialUniqueField).' wajib diisi sesuai konfigurasi Unique Serial Numbers SAP.',
                        ]);
                    }
                }

                $uniqueSerialKeys = $serialNumbers->map(
                    fn (array $serialNumber): string => $this->serialUniqueKey($serialNumber, $serialUniqueField),
                );

                if ($uniqueSerialKeys->unique()->count() !== $serialNumbers->count()) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.serial_numbers" => SerialNumberConfigurationGateway::label($serialUniqueField)." item {$itemCode} tidak boleh duplikat.",
                    ]);
                }
            } elseif ($serialNumbers->isNotEmpty()) {
                throw ValidationException::withMessages([
                    "lines.{$index}.serial_numbers" => "Item {$itemCode} tidak dikelola dengan serial number di SAP.",
                ]);
            }

            $resolved[] = [
                'line_num' => $lineNumber,
                'quantity' => $quantity,
                'open_quantity' => $openQuantity,
                'item_code' => $itemCode,
                'item_name' => (string) ($line['ItemDescription'] ?? $line['item_name'] ?? $line['description'] ?? ''),
                'warehouse_code' => $warehouseCode,
                'vehicle_code' => $vehicleCode !== '' ? $vehicleCode : null,
                'item_group_code' => $itemGroupCode,
                'item_group_name' => $itemGroupName !== '' ? $itemGroupName : null,
                'manages_serial_numbers' => $managesSerialNumbers,
                'serial_management_method' => $serialManagementMethod !== '' ? $serialManagementMethod : null,
                'serial_unique_field' => $requiresSerialNumbers ? $serialUniqueField : null,
                'serial_numbers' => $serialNumbers->all(),
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $serialNumber
     * @return array{manufacturer_serial_number: string|null, internal_serial_number: string|null, lot_number: string|null, expiry_date: string|null, manufacture_date: string|null, reception_date: string|null, location: string|null, notes: string|null}
     */
    private function normalizeSerialNumber(array $serialNumber): array
    {
        return collect([
            'manufacturer_serial_number',
            'internal_serial_number',
            'lot_number',
            'expiry_date',
            'manufacture_date',
            'reception_date',
            'location',
            'notes',
        ])->mapWithKeys(function (string $field) use ($serialNumber): array {
            $value = trim((string) ($serialNumber[$field] ?? ''));

            return [$field => $value !== '' ? $value : null];
        })->all();
    }

    /** @param array<string, string|null> $serialNumber */
    private function serialUniqueKey(array $serialNumber, string $serialUniqueField): string
    {
        if ($serialUniqueField !== SerialNumberConfigurationGateway::None) {
            return mb_strtoupper((string) ($serialNumber[$serialUniqueField] ?? ''));
        }

        return collect([
            $serialNumber['manufacturer_serial_number'] ?? null,
            $serialNumber['internal_serial_number'] ?? null,
            $serialNumber['lot_number'] ?? null,
        ])->map(fn (?string $value): string => mb_strtoupper((string) $value))->implode('|');
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $items
     * @param  array<int, string>  $itemGroupNames
     */
    private function cacheItemMasterData(string $companyDb, Collection $items, array $itemGroupNames): void
    {
        foreach ($items as $item) {
            $itemCode = trim((string) ($item['ItemCode'] ?? ''));

            if ($itemCode === '') {
                continue;
            }

            $itemGroupCode = is_numeric($item['ItemsGroupCode'] ?? null) ? (int) $item['ItemsGroupCode'] : null;
            SapItemCache::query()->updateOrCreate(
                ['company_db' => $companyDb, 'item_code' => $itemCode],
                [
                    'item_name' => trim((string) ($item['ItemName'] ?? '')) ?: '(Tanpa nama)',
                    'inventory_uom' => filled($item['InventoryUOM'] ?? null) ? (string) $item['InventoryUOM'] : null,
                    'item_group_code' => $itemGroupCode,
                    'item_group_name' => $itemGroupCode !== null ? ($itemGroupNames[$itemGroupCode] ?? null) : null,
                    'is_inventory_item' => ($item['InventoryItem'] ?? null) === 'tYES',
                    'is_purchase_item' => ($item['PurchaseItem'] ?? null) === 'tYES',
                    'manages_serial_numbers' => ($item['ManageSerialNumbers'] ?? null) === 'tYES',
                    'serial_management_method' => filled($item['SRIAndBatchManageMethod'] ?? null)
                        ? (string) $item['SRIAndBatchManageMethod']
                        : null,
                    'is_active' => ($item['Frozen'] ?? null) === 'tNO',
                    'source_payload' => $item,
                    'synced_at' => now(),
                ],
            );
        }
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
