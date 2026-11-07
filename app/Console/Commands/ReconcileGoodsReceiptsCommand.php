<?php

namespace App\Console\Commands;

use App\Application\Receiving\ReconcileGoodsReceipt;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('sap:goods-receipts:reconcile
    {--company-db= : Allowed SAP company database; defaults to SAP_COMPANY_DB}
    {--limit=50 : Maximum number of uncertain commands to check}')]
#[Description('Match uncertain Goods Receipt PO commands with SAP by request UUID (read-only; never posts or retries)')]
class ReconcileGoodsReceiptsCommand extends Command
{
    public function handle(ReconcileGoodsReceipt $reconcile, SapCompanyContext $companies): int
    {
        if (! config('sap.goods_receipt.partial_receipts_enabled')) {
            $this->info('Partial Goods Receipt PO is disabled; nothing to reconcile.');

            return self::SUCCESS;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('The --limit option must be between 1 and 500.');

            return self::INVALID;
        }

        $companyDb = $this->option('company-db') ?: $companies->current();

        if (! $companies->isAllowed($companyDb)) {
            $this->error('The --company-db option must be one of: '.implode(', ', $companies->available()));

            return self::INVALID;
        }

        $companies->select($companyDb);
        $inFlightCutoff = now()->subSeconds(max(0, (int) config('sap.goods_receipt.reconcile_min_age_seconds', 120)));
        $commands = SapCommand::query()
            ->uncertainGoodsReceipts($companyDb)
            ->whereNotNull('base_document_entry')
            ->where(function (Builder $query) use ($inFlightCutoff): void {
                $query->where('status', IntegrationStatus::PostingUnknown->value)
                    ->orWhere(fn (Builder $posting): Builder => $posting
                        ->where('status', IntegrationStatus::Posting->value)
                        ->where('last_attempt_at', '<=', $inFlightCutoff));
            })
            ->oldest('last_attempt_at')
            ->limit($limit)
            ->get();

        if ($commands->isEmpty()) {
            $this->info('No uncertain Goods Receipt PO commands need reconciliation.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($commands as $command) {
            try {
                $goodsReceipt = $reconcile->handle($command);

                if ($goodsReceipt !== null) {
                    $this->info("Command {$command->id}: found Goods Receipt PO {$goodsReceipt->sap_doc_num} in SAP; resolved.");
                } elseif (! $reconcile->canLookUp($command)) {
                    $this->warn("Command {$command->id}: sent without the UUID UDF, cannot be matched automatically; a Super Admin must investigate.");
                } else {
                    $this->warn("Command {$command->id}: nothing found by UUID; still uncertain (an empty answer does not prove the POST did not happen).");
                }
            } catch (Throwable $exception) {
                $failures++;
                report($exception);
                $this->error("Command {$command->id}: reconciliation failed and the command stays uncertain.");
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
