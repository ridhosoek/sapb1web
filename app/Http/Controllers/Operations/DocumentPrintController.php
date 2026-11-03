<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Shared\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DocumentPrintController extends Controller
{
    public function __invoke(int $document, SapCompanyContext $companies): View
    {
        $sapDocument = SapDocument::query()
            ->forCompany($companies->current())
            ->with(['pool', 'department', 'approval.submitter', 'approval.steps.approver', 'adminHoChecker', 'attachments.uploader'])
            ->whereKey($document)
            ->firstOrFail();

        Gate::authorize('view', $sapDocument);
        abort_unless(in_array($sapDocument->document_type, ['PURCHASE_REQUEST', 'GOODS_ISSUE', 'PURCHASE_ORDER'], true), 404);

        $normalizedData = $sapDocument->normalized_data ?? [];
        $lines = collect($normalizedData['lines'] ?? [])
            ->filter(fn (mixed $line): bool => is_array($line))
            ->values();
        $itemIds = $lines->pluck('item_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique();
        $items = SapItemCache::query()
            ->forCompany($companies->current())
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');
        $authorizationBlocks = $this->authorizationBlocks($sapDocument);

        return view('operations.documents.print', [
            'document' => $sapDocument,
            'normalizedData' => $normalizedData,
            'lines' => $lines,
            'items' => $items,
            'documentTitle' => match ($sapDocument->document_type) {
                'PURCHASE_REQUEST' => 'Purchase Request',
                'GOODS_ISSUE' => 'Goods Issue',
                'PURCHASE_ORDER' => 'Form Goods Receipt PO',
            },
            'generatedAt' => now(),
            'authorizationBlocks' => $authorizationBlocks,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function authorizationBlocks(SapDocument $document): array
    {
        $approval = $document->approval;
        $steps = $approval?->steps ?? collect();
        $managerAdminHoStep = $steps->first(
            fn ($step): bool => $step->approver?->role === UserRole::ManagerAdminHo,
        );
        $operationalApproverStep = $steps->first(
            fn ($step): bool => $step->approver?->role === UserRole::ManagerOperational,
        ) ?? $steps->first(
            fn ($step): bool => $step->approver?->role !== UserRole::ManagerAdminHo,
        );

        return [
            $this->signatory('Requested by', $approval?->submitter, $approval?->submitted_at, 'REQUESTED'),
            $this->signatory(
                'Checked by',
                $document->adminHoChecker,
                $document->admin_ho_checked_at,
                $document->admin_ho_checked_at ? 'CHECKED' : null,
            ),
            $this->signatory(
                'Approved by',
                $operationalApproverStep?->approver,
                $operationalApproverStep?->decided_at,
                $operationalApproverStep?->status === 'APPROVED' ? 'APPROVED' : null,
            ),
            [
                'label' => 'Verified by',
                'name' => $managerAdminHoStep?->approver?->name ?? '',
                'role' => UserRole::ManagerAdminHo->label(),
                'signed_at' => null,
                'stamp_status' => null,
                'wet_signature' => true,
            ],
        ];
    }

    /** @return array{label: string, name: string, role: string, signed_at: mixed, stamp_status: ?string, wet_signature: bool} */
    private function signatory(string $label, ?User $user, mixed $signedAt, ?string $stampStatus): array
    {
        return [
            'label' => $label,
            'name' => $user?->name ?? '—',
            'role' => $user?->role?->label() ?? '—',
            'signed_at' => $signedAt,
            'stamp_status' => $stampStatus,
            'wet_signature' => false,
        ];
    }
}
