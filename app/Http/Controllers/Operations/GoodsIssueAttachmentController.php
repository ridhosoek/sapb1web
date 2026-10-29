<?php

namespace App\Http\Controllers\Operations;

use App\Application\Inventory\StoreGoodsIssueAttachments;
use App\Domain\Shared\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGoodsIssueAttachmentRequest;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Throwable;

class GoodsIssueAttachmentController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        StoreGoodsIssueAttachmentRequest $request,
        int $document,
        SapCompanyContext $companies,
        StoreGoodsIssueAttachments $storeAttachments,
    ): RedirectResponse {
        $goodsIssue = SapDocument::query()
            ->forCompany($companies->current())
            ->where('document_type', 'GOODS_ISSUE')
            ->with('approval')
            ->whereKey($document)
            ->firstOrFail();

        Gate::authorize('view', $goodsIssue);
        abort_unless(
            $request->user()->hasRole(UserRole::SuperAdmin)
                || $goodsIssue->approval?->submitted_by === $request->user()->id,
            403,
        );

        try {
            $attachments = $storeAttachments->handle(
                document: $goodsIssue,
                files: $request->file('attachments', []),
                user: $request->user(),
                caption: $request->validated('caption'),
                ipAddress: $request->ip(),
            );

            return to_route('documents.show', $goodsIssue)
                ->with('status', $attachments->count().' lampiran scan FPK berhasil disimpan secara privat.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['attachments' => 'Lampiran scan FPK belum dapat disimpan. Silakan coba kembali.']);
        }
    }
}
