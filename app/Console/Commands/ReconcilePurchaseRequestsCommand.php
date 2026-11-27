<?php

namespace App\Console\Commands;

use App\Application\Purchasing\ReconcilePurchaseRequest;
use App\Domain\Sap\Enums\IntegrationStatus;
use App\Domain\Sap\Enums\PurchaseRequestReconciliationOutcome as Outcome;
use App\Integration\Sap\Mapping\SapFieldMapper;
use App\Integration\Sap\SapCompanyContext;
use App\Integration\Sap\SapMetadataInspector;
use App\Models\SapCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

#[Signature('sap:purchase-requests:reconcile
    {--company-db= : Allowed SAP company database; defaults to SAP_COMPANY_DB}
    {--limit= : Maximum number of uncertain commands to check (1-50)}
    {--check-field : Only report whether PurchaseRequests exposes the transaction UUID field}')]
#[Description('Match uncertain Purchase Request commands with SAP by request UUID (read-only in SAP; never posts or retries)')]
class ReconcilePurchaseRequestsCommand extends Command
{
    public function handle(
        ReconcilePurchaseRequest $reconcile,
        SapCompanyContext $companies,
        SapMetadataInspector $metadata,
        SapFieldMapper $fields,
    ): int {
        $limit = filter_var($this->option('limit') ?? config('sap.purchase_request.reconcile.batch_size', 5), FILTER_VALIDATE_INT);

        if ($limit === false || $limit < 1 || $limit > 50) {
            $this->error('The --limit option must be between 1 and 50.');

            return self::INVALID;
        }

        $companyDb = $this->option('company-db') ?: $companies->current();

        if (! $companies->isAllowed($companyDb)) {
            $this->error('The --company-db option must be one of: '.implode(', ', $companies->available()));

            return self::INVALID;
        }

        $companies->select($companyDb);

        if ($this->option('check-field')) {
            return $this->reportFieldReadiness($companyDb, $metadata, $fields->optionalField('web_transaction_id'));
        }

        $commands = $this->dueCommands($companyDb, $limit);

        if ($commands->isEmpty()) {
            $this->info("No uncertain Purchase Request commands are due for {$companyDb}.");

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($commands as $command) {
            $outcome = $reconcile->handle($command);
            $this->line("{$companyDb} command {$command->id}: {$outcome->value}. {$outcome->message()}");

            if (in_array($outcome, [Outcome::LookupFailed, Outcome::InvalidResponse, Outcome::RecordingFailed], true)) {
                $failures++;
            }

            if ($outcome === Outcome::LookupFailed) {
                // SAP may be struggling; the rest of the batch waits for the next run instead of adding load.
                $this->warn('SAP could not be read; the remaining commands were left for a later run.');
                break;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return Collection<int, SapCommand> */
    private function dueCommands(string $companyDb, int $limit): Collection
    {
        $inFlightCutoff = now()->subSeconds(max(0, (int) config('sap.purchase_request.reconcile.min_age_seconds', 300)));

        return SapCommand::query()
            ->where('company_db', $companyDb)
            ->where('command_type', ReconcilePurchaseRequest::COMMAND_TYPE)
            ->where('sap_entity', 'PurchaseRequests')
            ->where(fn (Builder $status): Builder => $status
                ->where('status', IntegrationStatus::PostingUnknown->value)
                ->orWhere(fn (Builder $posting): Builder => $posting
                    ->where('status', IntegrationStatus::Posting->value)
                    ->where('last_attempt_at', '<=', $inFlightCutoff)))
            ->where('reconcile_attempts', '<', (int) config('sap.purchase_request.reconcile.max_attempts', 8))
            ->where(fn (Builder $due): Builder => $due
                ->where('reconcile_attempts', 0)
                ->orWhere('next_reconcile_at', '<=', now()))
            // Never checked first, then the ones that have waited longest.
            ->orderBy('last_reconciled_at')
            ->orderBy('last_attempt_at')
            ->limit($limit)
            ->get();
    }

    private function reportFieldReadiness(string $companyDb, SapMetadataInspector $metadata, ?string $field): int
    {
        if ($field === null) {
            $this->error("{$companyDb}: SAP_FIELD_WEB_TRANSACTION_ID is empty; Purchase Requests cannot be reconciled.");

            return self::FAILURE;
        }

        try {
            $supported = $metadata->supportsEntityProperty('PurchaseRequests', $field);
        } catch (Throwable $exception) {
            report($exception);
            $this->error("{$companyDb}: SAP metadata could not be read; readiness of {$field} is unknown.");

            return self::FAILURE;
        }

        if (! $supported) {
            $this->error("{$companyDb}: NOT READY. PurchaseRequests does not expose {$field}.");

            return self::FAILURE;
        }

        $this->info("{$companyDb}: READY. PurchaseRequests exposes {$field}. A write and read back still has to be proven on SAP.");

        return self::SUCCESS;
    }
}
