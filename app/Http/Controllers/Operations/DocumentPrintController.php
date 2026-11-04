<?php

namespace App\Http\Controllers\Operations;

use App\Application\Purchasing\DocumentQrCode;
use App\Domain\Shared\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;

class DocumentPrintController extends Controller
{
    public function __invoke(int $document, SapCompanyContext $companies, DocumentQrCode $qrCodes): View
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
        $authorizationBlocks = $this->authorizationBlocks(
            $sapDocument,
            $qrCodes,
        );

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
    private function authorizationBlocks(SapDocument $document, DocumentQrCode $qrCodes): array
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
        $checkedQrCode = null;
        $approvalQrCode = null;

        if ($document->adminHoChecker && $document->admin_ho_checked_at) {
            $checkedQrCode = $qrCodes->renderLink(
                URL::signedRoute('document-authorizations.show', [
                    'document' => $document->id,
                    'authorization' => 'checked',
                ]),
                'CHECKED',
                $document->id,
            );
        }

        if (
            $operationalApproverStep?->status === 'APPROVED'
            && $operationalApproverStep->approver
            && $operationalApproverStep->decided_at
        ) {
            $approvalQrCode = $qrCodes->renderLink(
                URL::signedRoute('document-authorizations.show', [
                    'document' => $document->id,
                    'authorization' => 'approved',
                ]),
                'APPROVED',
                $document->id,
            );
        }

        return [
            $this->signatory('Requested by', $approval?->submitter, $approval?->submitted_at, 'REQUESTED'),
            $this->signatory(
                'Checked by',
                $document->adminHoChecker,
                $document->admin_ho_checked_at,
                $document->admin_ho_checked_at ? 'CHECKED' : null,
                $checkedQrCode,
            ),
            $this->signatory(
                'Approved by',
                $operationalApproverStep?->approver,
                $operationalApproverStep?->decided_at,
                $operationalApproverStep?->status === 'APPROVED' ? 'APPROVED' : null,
                $approvalQrCode,
            ),
            [
                'label' => 'Verified by',
                'name' => $managerAdminHoStep?->approver?->name ?? '',
                'job_title' => $managerAdminHoStep?->approver?->job_title ?? '—',
                'signed_at' => null,
                'stamp_status' => null,
                'wet_signature' => true,
                'qr_svg' => null,
            ],
        ];
    }

    /** @return array{label: string, name: string, job_title: string, signed_at: mixed, stamp_status: ?string, wet_signature: bool, qr_svg: ?HtmlString} */
    private function signatory(
        string $label,
        ?User $user,
        mixed $signedAt,
        ?string $stampStatus,
        ?HtmlString $qrCode = null,
    ): array {
        return [
            'label' => $label,
            'name' => $user?->name ?? '—',
            'job_title' => $user?->job_title ?: '—',
            'signed_at' => $signedAt,
            'stamp_status' => $stampStatus,
            'wet_signature' => false,
            'qr_svg' => $stampStatus ? $qrCode : null,
        ];
    }
}
