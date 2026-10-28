<?php

namespace App\Mail;

use App\Models\DocumentApprovalStep;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApprovalRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly DocumentApprovalStep $step) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $document = $this->step->approval->document;
        $documentLabel = $document->document_type === 'PURCHASE_REQUEST' ? 'Purchase Request' : 'Goods Issue';
        $reference = $document->sap_doc_num ?: $document->fpk_number ?: 'Portal #'.$document->id;

        return new Envelope(
            subject: "Approval {$documentLabel} {$reference}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $document = $this->step->approval->document;

        return new Content(
            markdown: 'mail.approvals.requested',
            with: [
                'approval' => $this->step->approval,
                'document' => $document,
                'itemLines' => $this->itemLines($document),
                'approvalUrl' => $this->step->approvalUrl(),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    /** @return list<array{code: string, name: string, quantity: string}> */
    private function itemLines(SapDocument $document): array
    {
        $lines = collect(data_get($document->normalized_data, 'lines', []));
        $items = SapItemCache::query()
            ->forCompany($document->company_db)
            ->whereIn('id', $lines->pluck('item_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return $lines->map(function (array $line) use ($items): array {
            $item = $items->get((int) ($line['item_id'] ?? 0));

            return [
                'code' => $this->markdownTableCell((string) ($line['item_code'] ?? $item?->item_code ?? '—')),
                'name' => $this->markdownTableCell((string) ($line['item_name'] ?? $item?->item_name ?? '—')),
                'quantity' => $this->formatQuantity($line['quantity'] ?? $line['requested_quantity'] ?? 0),
            ];
        })->values()->all();
    }

    private function markdownTableCell(string $value): string
    {
        return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], trim($value));
    }

    private function formatQuantity(mixed $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 4, ',', '.'), '0'), ',');
    }
}
