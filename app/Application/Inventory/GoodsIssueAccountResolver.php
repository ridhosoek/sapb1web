<?php

namespace App\Application\Inventory;

use App\Exceptions\SapSubmissionException;

class GoodsIssueAccountResolver
{
    public function resolve(
        string $companyDb,
        string $issueTypeCode,
        ?string $itemCode = null,
        ?string $warehouseCode = null,
        ?string $departmentCode = null,
        ?string $poolCode = null,
        ?string $vehicleCode = null,
    ): ?string {
        $costCenterCodes = config('sap.goods_issue.account_codes_by_company_item_warehouse_cost_centers', []);
        $costCenterCode = is_array($costCenterCodes) && $itemCode !== null && $warehouseCode !== null
            && $departmentCode !== null && $poolCode !== null && $vehicleCode !== null
            ? ($costCenterCodes[$companyDb][$issueTypeCode][$itemCode][$warehouseCode][$departmentCode][$poolCode][$vehicleCode] ?? null)
            : null;
        $itemWarehouseCodes = config('sap.goods_issue.account_codes_by_company_item_warehouse', []);
        $itemWarehouseCode = is_array($itemWarehouseCodes) && $itemCode !== null && $warehouseCode !== null
            ? ($itemWarehouseCodes[$companyDb][$issueTypeCode][$itemCode][$warehouseCode] ?? null)
            : null;
        $companyCodes = config('sap.goods_issue.account_codes_by_company', []);
        $companyCode = is_array($companyCodes) ? ($companyCodes[$companyDb][$issueTypeCode] ?? null) : null;
        $legacyCode = in_array($issueTypeCode, ['SPR-IK', 'BBM-OU'], true) ? null : config("sap.goods_issue.account_codes.{$issueTypeCode}");
        $accountCode = trim((string) ($costCenterCode ?? $itemWarehouseCode ?? $companyCode ?? $legacyCode));

        return $accountCode !== '' ? $accountCode : null;
    }

    public function require(
        string $companyDb,
        string $issueTypeCode,
        int $lineNumber,
        ?string $itemCode = null,
        ?string $warehouseCode = null,
        ?string $departmentCode = null,
        ?string $poolCode = null,
        ?string $vehicleCode = null,
    ): string {
        $accountCode = $this->resolve($companyDb, $issueTypeCode, $itemCode, $warehouseCode, $departmentCode, $poolCode, $vehicleCode);

        if ($accountCode === null) {
            $context = $itemCode !== null && $warehouseCode !== null
                ? " item {$itemCode} di gudang {$warehouseCode}"
                : '';

            throw new SapSubmissionException("Akun G/L tipe pengeluaran {$issueTypeCode}{$context} untuk database {$companyDb} belum dipetakan (baris {$lineNumber}). Goods Issue ditahan sebelum dikirim ke SAP; hubungi Admin HO/Accounting.");
        }

        return $accountCode;
    }
}
