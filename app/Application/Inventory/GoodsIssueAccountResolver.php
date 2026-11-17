<?php

namespace App\Application\Inventory;

use App\Exceptions\SapSubmissionException;

class GoodsIssueAccountResolver
{
    public function resolve(string $companyDb, string $issueTypeCode): ?string
    {
        $companyCodes = config('sap.goods_issue.account_codes_by_company', []);
        $companyCode = is_array($companyCodes) ? ($companyCodes[$companyDb][$issueTypeCode] ?? null) : null;
        $legacyCode = $issueTypeCode === 'SPR-IK' ? null : config("sap.goods_issue.account_codes.{$issueTypeCode}");
        $accountCode = trim((string) ($companyCode ?? $legacyCode));

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
