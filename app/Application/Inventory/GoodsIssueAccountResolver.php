<?php

namespace App\Application\Inventory;

use App\Exceptions\SapSubmissionException;

class GoodsIssueAccountResolver
{
    public function resolve(string $companyDb, string $issueTypeCode, ?string $itemCode = null, ?string $warehouseCode = null): ?string
    {
        $itemWarehouseCodes = config('sap.goods_issue.account_codes_by_company_item_warehouse', []);
        $itemWarehouseCode = is_array($itemWarehouseCodes) && $itemCode !== null && $warehouseCode !== null
            ? ($itemWarehouseCodes[$companyDb][$issueTypeCode][$itemCode][$warehouseCode] ?? null)
            : null;
        $companyCodes = config('sap.goods_issue.account_codes_by_company', []);
        $companyCode = is_array($companyCodes) ? ($companyCodes[$companyDb][$issueTypeCode] ?? null) : null;
        $legacyCode = in_array($issueTypeCode, ['SPR-IK', 'BBM-OU'], true) ? null : config("sap.goods_issue.account_codes.{$issueTypeCode}");
        $accountCode = trim((string) ($itemWarehouseCode ?? $companyCode ?? $legacyCode));

        return $accountCode !== '' ? $accountCode : null;
    }

    public function require(string $companyDb, string $issueTypeCode, int $lineNumber): string
    {
        $accountCode = $this->resolve($companyDb, $issueTypeCode);

        if ($accountCode === null) {
            throw new SapSubmissionException("Akun G/L tipe pengeluaran {$issueTypeCode} untuk database {$companyDb} belum dipetakan (baris {$lineNumber}). Goods Issue ditahan sebelum dikirim ke SAP; hubungi Admin HO/Accounting.");
        }

        return $accountCode;
    }
}
