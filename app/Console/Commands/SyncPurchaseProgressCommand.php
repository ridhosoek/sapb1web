<?php

namespace App\Console\Commands;

use App\Application\Purchasing\SyncPurchaseRequestProgress;
use App\Domain\Sap\Enums\BusinessStatus;
use App\Domain\Sap\Enums\WatchStatus;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('sap:purchase-progress:sync
    {--company-db= : Allowed SAP company database; defaults to SAP_COMPANY_DB}
    {--doc-entry= : Synchronize one Purchase Request DocEntry immediately}
    {--limit= : Maximum number of due Purchase Requests to synchronize}')]
#[Description('Discover Purchase Quotations and Purchase Orders in the SAP B1 purchasing lifecycle')]
class SyncPurchaseProgressCommand extends Command
{
    public function handle(SyncPurchaseRequestProgress $sync, SapCompanyContext $companies): int
    {
        $limit = filter_var($this->option('limit') ?: config('sap.watchers.batch_size', 50), FILTER_VALIDATE_INT);
        $docEntryOption = $this->option('doc-entry');
        $docEntry = filled($docEntryOption) ? filter_var($docEntryOption, FILTER_VALIDATE_INT) : null;

        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('The --limit option must be between 1 and 500.');

            return self::INVALID;
        }

        if ($docEntryOption !== null && ($docEntry === false || $docEntry < 1)) {
            $this->error('The --doc-entry option must be a positive integer.');

            return self::INVALID;
        }

        $companyDb = $this->option('company-db') ?: $companies->current();

        if (! $companies->isAllowed($companyDb)) {
            $this->error('The --company-db option must be one of: '.implode(', ', $companies->available()));

            return self::INVALID;
        }

        $companies->select($companyDb);
        $documents = SapDocument::query()
            ->forCompany($companyDb)
            ->where('document_type', 'PURCHASE_REQUEST')
            ->where('sap_business_status', BusinessStatus::Open)
            ->whereNotNull('sap_doc_entry')
            ->when(
                $docEntry !== null,
                fn (Builder $query): Builder => $query->where('sap_doc_entry', $docEntry),
                function (Builder $query): void {
                    $query->whereDoesntHave('childRelations', fn (Builder $quotationRelation): Builder => $quotationRelation
                        ->where('relation_type', 'PURCHASE_QUOTATION')
                        ->whereHas('childDocument.childRelations', fn (Builder $purchaseOrderRelation): Builder => $purchaseOrderRelation
                            ->where('relation_type', 'PURCHASE_ORDER')))
                        ->where(function (Builder $watchers): void {
                            $watchers->whereDoesntHave('watcher')
                                ->orWhereHas('watcher', fn (Builder $watcher): Builder => $watcher
                                    ->where('watch_status', WatchStatus::Active)
                                    ->where(function (Builder $due): void {
                                        $due->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now());
                                    }))
                                ->orWhereHas('watcher', fn (Builder $watcher): Builder => $watcher
                                    ->where('watch_status', WatchStatus::Stopped));
                        });
                },
            )
            ->oldest('updated_at')
            ->limit($limit)
            ->get();

        if ($documents->isEmpty()) {
            $this->info('No matching Purchase Requests require synchronization.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($documents as $document) {
            try {
                $result = $sync->handle($document);

                if ($result['purchase_order_found']) {
                    $numbers = $result['purchase_order_doc_nums'] === [] ? '(DocNum unavailable)' : implode(', ', $result['purchase_order_doc_nums']);
                    $this->info("PR {$document->sap_doc_num}: found {$result['purchase_order_count']} Purchase Order(s): {$numbers}.");
                } elseif ($result['quotation_found']) {
                    $numbers = $result['quotation_doc_nums'] === [] ? '(DocNum unavailable)' : implode(', ', $result['quotation_doc_nums']);
                    $this->line("PR {$document->sap_doc_num}: Purchase Quotation {$numbers} found; Purchase Order not found.");
                } else {
                    $this->line("PR {$document->sap_doc_num}: no Purchase Quotation found.");
                }
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
                $this->error("PR {$document->sap_doc_num}: {$exception->getMessage()}");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
