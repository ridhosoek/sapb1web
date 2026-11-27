<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapCommand;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SapDocumentDetailController extends Controller
{
    public function __invoke(Request $request, int $document, SapCompanyContext $companies): View
    {
        $sapDocument = SapDocument::query()
            ->forCompany($companies->current())
            ->with(['pool', 'department', 'watcher', 'approval.submitter', 'approval.steps.approver', 'adminHoChecker', 'attachments.uploader'])
            ->whereKey($document)
            ->firstOrFail();

        Gate::authorize('view', $sapDocument);

        if (str_starts_with($sapDocument->document_type, 'PAYMENT_') && ! $request->user()->can('view-payment-requests')) {
            abort(403);
        }

        $normalizedData = $sapDocument->normalized_data ?? [];
        $lines = collect($normalizedData['lines'] ?? $normalizedData['invoice_lines'] ?? $normalizedData['account_lines'] ?? [])
            ->filter(fn (mixed $line): bool => is_array($line))
            ->values();
        $itemIds = $lines->pluck('item_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique();
        $items = SapItemCache::query()
            ->forCompany($companies->current())
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');
        $command = filled($sapDocument->transaction_uuid)
            ? SapCommand::query()
                ->where('company_db', $companies->current())
                ->where('transaction_uuid', $sapDocument->transaction_uuid)
                ->first()
            : null;

        $detailFields = $this->detailFields($normalizedData);

        if ($sapDocument->document_type === 'PURCHASE_REQUEST'
            && in_array($normalizedData['progress_status'] ?? null, ['PENDING_APPROVAL', 'WAITING_SAP_SYNC'], true)) {
            $detailFields['progress_status'] = $sapDocument->purchaseProgressLabel();
        }

        return view('operations.documents.show', [
            'document' => $sapDocument,
            'command' => $command,
            'normalizedData' => $normalizedData,
            'detailFields' => $detailFields,
            'lines' => $lines,
            'items' => $items,
            'backRoute' => $this->backRoute($request, $sapDocument),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function detailFields(array $data): array
    {
        $ignored = [
            'lines', 'invoice_lines', 'account_lines', 'document', 'transaction_uuid', 'request_uuid',
            'vehicle', 'vehicle_cost_center', 'issue_type', 'ending_km', 'sap_context',
            'fpk_number', 'fpk_date', 'pool_code', 'department_code', 'approval_status',
            'requester_email', 'created_by_web_user', 'base_document_type', 'base_document_entry',
        ];
        $fields = collect($data)
            ->reject(fn (mixed $value, string $key): bool => in_array($key, $ignored, true)
                || is_array($value)
                || str_ends_with($key, '_synced_at')
                || str_starts_with($key, 'receipt_state_'))
            ->all();

        foreach ([
            'issue_type.code' => 'issue_type',
        ] as $path => $key) {
            $value = data_get($data, $path);

            if (filled($value)) {
                $fields[$key] = $value;
            }
        }

        $ordered = [];

        foreach (['document_date', 'required_date', 'receipt_date', 'priority', 'progress_status', 'authorization_status', 'series', 'vendor_name', 'vendor_code', 'base_document_num', 'warehouse_code', 'open_lines', 'complaint', 'damage_analysis', 'notes', 'remarks'] as $key) {
            if (array_key_exists($key, $fields)) {
                $ordered[$key] = $fields[$key];
                unset($fields[$key]);
            }
        }

        return [...$ordered, ...$fields];
    }

    private function backRoute(Request $request, SapDocument $document): string
    {
        return match ($document->document_type) {
            'PURCHASE_REQUEST' => 'purchase-requests.index',
            'PURCHASE_ORDER' => 'ready-to-receive.index',
            'GOODS_RECEIPT_PO' => 'ready-to-receive.history',
            'GOODS_ISSUE' => 'goods-issues.index',
            'PAYMENT_REQUEST' => 'payment-requests.index',
            default => 'dashboard',
        };
    }
}
