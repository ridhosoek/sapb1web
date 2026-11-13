<?php

namespace App\Console\Commands;

use App\Application\Receiving\AttributeGoodsReceiptCreators;
use App\Integration\Sap\SapCompanyContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('sap:goods-receipts:attribute-creators
    {--company-db= : Limit to one allowed SAP company database; defaults to every database}
    {--apply : Store the proven sender; without it the command only reports}')]
#[Description('Attribute older web Goods Receipt POs to their first sender from exact audit evidence (portal database only; never calls SAP)')]
class AttributeGoodsReceiptCreatorsCommand extends Command
{
    public function handle(AttributeGoodsReceiptCreators $attributeCreators, SapCompanyContext $companies): int
    {
        $companyDb = $this->option('company-db') ?: null;

        if ($companyDb !== null && ! $companies->isAllowed($companyDb)) {
            $this->error('The --company-db option must be one of: '.implode(', ', $companies->available()));

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        $connection = DB::connection();
        $this->line('Portal database: '.$connection->getDatabaseName().' on '.($connection->getConfig('host') ?? '-').' (connection '.$connection->getName().'); '.($apply ? 'WRITING proven senders.' : 'read only.'));

        $result = $attributeCreators->handle($companyDb, $apply);
        $verb = $apply ? 'attributed' : 'can be attributed';

        $this->info("Goods Receipt PO documents: {$result['documents_attributed']} {$verb}; ".count($result['documents_unattributed']).' without a provable sender.');
        $this->info("Goods Receipt PO commands: {$result['commands_attributed']} {$verb}; {$result['commands_unattributed']} without a provable sender.");

        foreach ($result['documents_by_company'] as $company => $outcomes) {
            $this->line("{$company}: {$outcomes[AttributeGoodsReceiptCreators::PROVEN]} one proven sender; "
                ."{$outcomes[AttributeGoodsReceiptCreators::MULTIPLE_SENDERS]} more than one sender; "
                ."{$outcomes[AttributeGoodsReceiptCreators::NO_EVIDENCE]} no evidence; "
                ."{$outcomes[AttributeGoodsReceiptCreators::INCONSISTENT_COMMAND]} inconsistent command/UUID.");
        }

        foreach ($result['attributed_document_ids'] as $company => $documentIds) {
            $this->line("{$company} sap_documents.id {$verb}: ".implode(',', $documentIds));
        }

        foreach ($result['documents_unattributed'] as $document) {
            $this->warn("Document {$document['id']} ({$document['company_db']}, GRPO ".($document['sap_doc_num'] ?? '-')."): {$document['reason']}");
        }

        if (! $apply) {
            $this->comment('Report only. Run again with --apply to store the proven senders.');
        }

        return self::SUCCESS;
    }
}
