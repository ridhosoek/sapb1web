<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Shared\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\SapDocument;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DocumentAuthorizationVerificationController extends Controller
{
    public function __invoke(SapDocument $document, string $authorization): View
    {
        $document->load(['approval.steps.approver', 'adminHoChecker']);
        $status = strtoupper($authorization);
        $authorizedUser = null;
        $authorizedAt = null;

        if ($status === 'CHECKED') {
            $authorizedUser = $document->adminHoChecker;
            $authorizedAt = $document->admin_ho_checked_at;
        } else {
            $approvalStep = $document->approval?->steps->first(
                fn ($step): bool => $step->approver?->role === UserRole::ManagerOperational,
            ) ?? $document->approval?->steps->first(
                fn ($step): bool => $step->approver?->role !== UserRole::ManagerAdminHo,
            );

            if ($approvalStep?->status === 'APPROVED') {
                $authorizedUser = $approvalStep->approver;
                $authorizedAt = $approvalStep->decided_at;
            }
        }

        abort_unless($authorizedUser && $authorizedAt, 404);

        return view('operations.documents.authorization-verification', [
            'document' => $document,
            'status' => $status,
            'authorizedUser' => $authorizedUser,
            'authorizedAt' => $authorizedAt,
            'documentDate' => $this->documentDate($document),
        ]);
    }

    private function documentDate(SapDocument $document): string
    {
        $normalizedData = $document->normalized_data ?? [];
        $date = $normalizedData['document_date']
            ?? $normalizedData['fpk_date']
            ?? data_get($normalizedData, 'document.DocDate')
            ?? $document->created_at;

        return Carbon::parse($date)->format('d/m/Y');
    }
}
