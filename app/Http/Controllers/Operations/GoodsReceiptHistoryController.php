<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Sap\Enums\BusinessStatus;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapCommand;
use App\Models\SapDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * BP-10 / TD-11: Goods Receipt POs that the signed-in user posted from the portal. The list reads only stored
 * Goods Receipt PO documents, so it does not depend on the Ready list, the Purchase Order status, its Series or
 * its date, and it never calls SAP.
 */
class GoodsReceiptHistoryController extends Controller
{
    private const SEARCHABLE_DATA_KEYS = ['base_document_num', 'vendor_name', 'vendor_code', 'warehouse_code'];

    public function index(Request $request, SapCompanyContext $companies): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:ACTIVE,CANCELLED'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $status = $filters['status'] ?? null;
        $user = $request->user();
        $companyDb = $companies->current();
        $uncertainCommands = $this->ownUncertainCommands($user, $companyDb);

        return view('operations.ready-to-receive.history', [
            'goodsReceipts' => $this->ownGoodsReceipts($user, $companyDb)
                ->when($status === 'CANCELLED', fn (Builder $query) => $query->where('sap_business_status', BusinessStatus::Cancelled->value))
                ->when($status === 'ACTIVE', fn (Builder $query) => $query->where('sap_business_status', '<>', BusinessStatus::Cancelled->value))
                ->when($search !== '', fn (Builder $query) => $this->search($query, $search))
                ->withCount('attachments')
                ->orderByDesc('normalized_data->receipt_date')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $filters,
            'isFiltered' => $search !== '' || $status !== null,
            'uncertainCommands' => $uncertainCommands,
            'uncertainPurchaseOrderNumbers' => SapDocument::query()
                ->forCompany($companyDb)
                ->where('document_type', 'PURCHASE_ORDER')
                ->whereIn('sap_doc_entry', $uncertainCommands->map(fn (SapCommand $command): int => $this->purchaseOrderDocEntry($command))->filter()->unique()->all())
                ->pluck('sap_doc_num', 'sap_doc_entry'),
            'companyDb' => $companyDb,
            'warehouseCode' => trim((string) $user->operationalProfile?->warehouse_code),
            'seesAllWarehouses' => $user->role->canViewAllDocuments(),
        ]);
    }

    public function show(Request $request, int $goodsReceipt, SapCompanyContext $companies): View
    {
        // Someone else's receipt is reported as missing, so its existence is not confirmed either.
        $document = $this->ownGoodsReceipts($request->user(), $companies->current(), restrictWarehouse: false)
            ->with(['pool', 'department', 'attachments.uploader'])
            ->whereKey($goodsReceipt)
            ->firstOrFail();

        Gate::authorize('view', $document);

        $purchaseOrder = $document->parentRelations()
            ->where('relation_type', 'GOODS_RECEIPT_PO')
            ->with('parentDocument')
            ->first()
            ?->parentDocument;

        return view('operations.ready-to-receive.history-show', [
            'goodsReceipt' => $document,
            'normalizedData' => $document->normalized_data ?? [],
            'lines' => collect(data_get($document->normalized_data, 'lines', []))
                ->filter(fn (mixed $line): bool => is_array($line))
                ->values(),
            'purchaseOrder' => $purchaseOrder !== null && $request->user()->can('view', $purchaseOrder) ? $purchaseOrder : null,
        ]);
    }

    private function ownGoodsReceipts(User $user, string $companyDb, bool $restrictWarehouse = true): Builder
    {
        return SapDocument::query()
            ->forCompany($companyDb)
            ->webGoodsReceiptsCreatedBy($user)
            ->when($restrictWarehouse, fn (Builder $query) => $query->visibleTo($user));
    }

    private function search(Builder $query, string $search): Builder
    {
        $term = '%'.mb_strtolower($search).'%';
        $grammar = $query->getQuery()->getGrammar();

        return $query->where(function (Builder $query) use ($term, $grammar): void {
            $query->whereRaw('LOWER(sap_doc_num) like ?', [$term])
                ->orWhereRaw('LOWER(fpk_number) like ?', [$term]);

            foreach (self::SEARCHABLE_DATA_KEYS as $key) {
                $query->orWhereRaw('LOWER('.$grammar->wrap("normalized_data->{$key}").') like ?', [$term]);
            }
        });
    }

    /**
     * The user's own postings whose outcome SAP has not confirmed. They are not receipts yet, so they are listed
     * apart from the history and pointed to reconciliation.
     *
     * @return Collection<int, SapCommand>
     */
    private function ownUncertainCommands(User $user, string $companyDb): Collection
    {
        return SapCommand::query()
            ->uncertainGoodsReceipts($companyDb)
            ->where('requested_by', $user->id)
            ->latest('last_attempt_at')
            ->limit(20)
            ->get()
            ->each(fn (SapCommand $command) => $command->setAttribute('purchase_order_doc_entry', $this->purchaseOrderDocEntry($command)));
    }

    /** Commands of the single-receipt path do not store `base_document_entry`; their payload still names the PO. */
    private function purchaseOrderDocEntry(SapCommand $command): int
    {
        return (int) ($command->base_document_entry ?? data_get($command->request_payload, 'DocumentLines.0.BaseEntry') ?? 0);
    }
}
