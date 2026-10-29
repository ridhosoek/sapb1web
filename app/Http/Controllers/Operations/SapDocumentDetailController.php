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
            ->with(['pool', 'department', 'watcher', 'approval.submitter', 'approval.steps.approver', 'attachments.uploader'])
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

        return view('operations.documents.show', [
            'document' => $sapDocument,
            'command' => $command,
            'normalizedData' => $normalizedData,
            'detailFields' => $this->detailFields($normalizedData),
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
        $ignored = ['lines', 'invoice_lines', 'account_lines', 'transaction_uuid', 'vehicle', 'vehicle_cost_center', 'issue_type'];
        $fields = collect($data)
            ->reject(fn (mixed $value, string $key): bool => in_array($key, $ignored, true) || is_array($value))
            ->all();

        foreach ([
            'vehicle_cost_center.center_code' => 'vehicle_cost_center',
            'vehicle.license_plate' => 'vehicle',
            'issue_type.code' => 'issue_type',
        ] as $path => $key) {
            $value = data_get($data, $path);

            if (filled($value)) {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    private function backRoute(Request $request, SapDocument $document): string
    {
        return match ($document->document_type) {
            'PURCHASE_REQUEST' => 'purchase-requests.index',
            'PURCHASE_ORDER' => 'ready-to-receive.index',
            'GOODS_ISSUE' => 'goods-issues.index',
            'PAYMENT_REQUEST' => 'payment-requests.index',
            default => 'dashboard',
        };
    }
}
