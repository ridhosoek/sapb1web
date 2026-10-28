<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocumentAttachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentAttachmentController extends Controller
{
    public function __invoke(int $attachment, SapCompanyContext $companies): StreamedResponse
    {
        $documentAttachment = SapDocumentAttachment::query()
            ->whereHas('document', fn ($query) => $query->forCompany($companies->current()))
            ->with('document')
            ->whereKey($attachment)
            ->firstOrFail();

        Gate::authorize('view', $documentAttachment->document);
        $disk = Storage::disk($documentAttachment->disk);
        abort_unless($disk->exists($documentAttachment->path), 404);

        return $disk->response(
            $documentAttachment->path,
            $documentAttachment->original_name,
            [
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
