<?php

namespace App\Integration\Sap\Reports;

class SparepartSqlQueries
{
    public function goodsIssue(bool $filterItem, bool $filterVehicle, bool $filterWarehouse): string
    {
        $itemFilter = $filterItem ? ' AND T1."ItemCode" = :itemCode' : '';
        $vehicleFilter = $filterVehicle ? ' AND T1."OcrCode3" = :nopol' : '';
        $warehouseFilter = $filterWarehouse ? ' AND T1."WhsCode" = :warehouseCode' : '';

        return <<<SQL
SELECT T0."DocDate" AS "RefDate", T0."Ref2" AS "FPK", T1."ItemCode" AS "ItemCode",
       T1."Dscription" AS "Description", T1."WhsCode" AS "WhsCode", T2."WhsName" AS "WhsName",
       T1."OcrCode3" AS "Nopol", T1."Quantity" AS "Quantity", T1."StockPrice" AS "UnitPrice",
       (T1."Quantity" * T1."StockPrice") AS "Amount", T0."DocEntry" AS "DocEntry",
       T0."DocNum" AS "DocNum", 'Goods Issue' AS "Transaction", T0."TransId" AS "TransId",
       T1."U_KM0" AS "KmStart", T1."U_KM1" AS "KmEnd"
FROM "OIGE" T0
INNER JOIN "IGE1" T1 ON T1."DocEntry" = T0."DocEntry"
INNER JOIN "OWHS" T2 ON T2."WhsCode" = T1."WhsCode"
WHERE T0."CANCELED" = 'N'
  AND T0."DocDate" BETWEEN :dateFrom AND :dateTo
  AND T1."AcctCode" LIKE :accountPattern{$itemFilter}{$vehicleFilter}{$warehouseFilter}
ORDER BY T0."DocDate" DESC, T0."DocEntry" DESC
SQL;
    }

    public function apInvoice(bool $filterItem, bool $filterVehicle, bool $filterWarehouse): string
    {
        $itemFilter = $filterItem ? ' AND T1."ItemCode" = :itemCode' : '';
        $vehicleFilter = $filterVehicle ? ' AND T1."OcrCode3" = :nopol' : '';
        $warehouseFilter = $filterWarehouse ? ' AND T1."WhsCode" = :warehouseCode' : '';

        return <<<SQL
SELECT T0."DocDate" AS "RefDate", T0."Ref2" AS "FPK", T1."ItemCode" AS "ItemCode",
       T1."Dscription" AS "Description", T1."WhsCode" AS "WhsCode", T2."WhsName" AS "WhsName",
       T1."OcrCode3" AS "Nopol", T0."CardCode" AS "VendorCode", T0."CardName" AS "VendorName",
       T1."Quantity" AS "Quantity", T1."Price" AS "UnitPrice", T1."LineTotal" AS "Amount",
       T0."DocCur" AS "Currency", T0."DocEntry" AS "DocEntry", T0."DocNum" AS "DocNum",
       'A/P Invoice' AS "Transaction", T0."TransId" AS "TransId", T1."U_KM0" AS "KmStart",
       T1."U_KM1" AS "KmEnd"
FROM "OPCH" T0
INNER JOIN "PCH1" T1 ON T1."DocEntry" = T0."DocEntry"
INNER JOIN "OWHS" T2 ON T2."WhsCode" = T1."WhsCode"
WHERE T0."CANCELED" = 'N'
  AND T0."DocDate" BETWEEN :dateFrom AND :dateTo
  AND T1."AcctCode" LIKE :accountPattern{$itemFilter}{$vehicleFilter}{$warehouseFilter}
ORDER BY T0."DocDate" DESC, T0."DocEntry" DESC
SQL;
    }

    public function paymentRequest(bool $filterVehicle, bool $filterWarehouse): string
    {
        $vehicleFilter = $filterVehicle ? ' AND T1."U_DEPT" = :nopol' : '';
        $warehouseFilter = $filterWarehouse ? ' AND T1."U_MESIN" = :warehouseCode' : '';

        return <<<SQL
SELECT T0."CreateDate" AS "RefDate", T1."U_FPK" AS "FPK", '' AS "ItemCode",
       T1."U_REMARK" AS "Description", T1."U_MESIN" AS "WhsCode", T2."Name" AS "WhsName",
       T1."U_DEPT" AS "Nopol", T0."U_BPCODE" AS "VendorCode", T0."U_BPNAME" AS "VendorName",
       0 AS "Quantity", 0 AS "UnitPrice", T1."U_PAYMENTAMT" AS "Amount", 'IDR' AS "Currency",
       T0."DocEntry" AS "DocEntry", T0."DocNum" AS "DocNum", 'Payment Request' AS "Transaction",
       0 AS "TransId", 0 AS "KmStart", 0 AS "KmEnd"
FROM "@VIT_PAYMENTREQ" T0
INNER JOIN "@VIT_PAYMENTREQD" T1 ON T1."DocEntry" = T0."DocEntry"
LEFT JOIN "@VDISPOL" T2 ON T2."Code" = T1."U_MESIN"
WHERE T0."Canceled" = 'N'
  AND T0."U_STATUS" = 'GENERATED'
  AND T0."CreateDate" BETWEEN :dateFrom AND :dateTo
  AND T1."U_ACCOUNTCODE" IN (:accountCode1, :accountCode2){$vehicleFilter}{$warehouseFilter}
ORDER BY T0."CreateDate" DESC, T0."DocEntry" DESC
SQL;
    }

    public function latestGoodsIssues(int $batchSize): string
    {
        $itemParameters = $this->parameterPlaceholders('itemCode', $batchSize);

        return <<<SQL
SELECT T0."DocDate" AS "RefDate", T0."Ref2" AS "FPK", T1."ItemCode" AS "ItemCode",
       T1."Dscription" AS "Description", T1."WhsCode" AS "WhsCode", T2."WhsName" AS "WhsName",
       T1."OcrCode3" AS "Nopol", T1."Quantity" AS "Quantity", T1."StockPrice" AS "UnitPrice",
       (T1."Quantity" * T1."StockPrice") AS "Amount", T0."DocEntry" AS "DocEntry",
       T0."DocNum" AS "DocNum", 'Goods Issue' AS "Transaction", T0."TransId" AS "TransId",
       T1."U_KM0" AS "KmStart", T1."U_KM1" AS "KmEnd"
FROM "OIGE" T0
INNER JOIN "IGE1" T1 ON T1."DocEntry" = T0."DocEntry"
INNER JOIN "OWHS" T2 ON T2."WhsCode" = T1."WhsCode"
WHERE T0."CANCELED" = 'N'
  AND T0."DocDate" BETWEEN :dateFrom AND :dateTo
  AND T1."AcctCode" LIKE :accountPattern
  AND T1."WhsCode" = :warehouseCode
  AND T1."OcrCode3" = :nopol
  AND T1."ItemCode" IN ({$itemParameters})
  AND NOT EXISTS (
      SELECT 1
      FROM "OIGE" X0
      INNER JOIN "IGE1" X1 ON X1."DocEntry" = X0."DocEntry"
      WHERE X0."CANCELED" = 'N'
        AND X0."DocDate" BETWEEN :dateFrom AND :dateTo
        AND X1."AcctCode" LIKE :accountPattern
        AND X1."WhsCode" = :warehouseCode
        AND X1."OcrCode3" = :nopol
        AND X1."ItemCode" = T1."ItemCode"
        AND (
            X0."DocDate" > T0."DocDate"
            OR (X0."DocDate" = T0."DocDate" AND X0."DocEntry" > T0."DocEntry")
            OR (X0."DocDate" = T0."DocDate" AND X0."DocEntry" = T0."DocEntry" AND X1."LineNum" > T1."LineNum")
        )
  )
ORDER BY T1."ItemCode"
SQL;
    }

    public function serialNumbers(int $batchSize): string
    {
        $documentParameters = $this->parameterPlaceholders('docEntry', $batchSize);

        return <<<SQL
SELECT T0."DocEntry" AS "DocEntry", T1."ItemCode" AS "ItemCode",
       T4."DistNumber" AS "DistNumber"
FROM "OIGE" T0
INNER JOIN "IGE1" T1 ON T1."DocEntry" = T0."DocEntry"
INNER JOIN "OITL" T2 ON T2."DocEntry" = T0."DocEntry"
    AND T2."DocType" = 60
    AND T2."DocLine" = T1."LineNum"
    AND T2."ItemCode" = T1."ItemCode"
INNER JOIN "ITL1" T3 ON T3."LogEntry" = T2."LogEntry" AND T3."ItemCode" = T2."ItemCode"
INNER JOIN "OSRN" T4 ON T4."ItemCode" = T3."ItemCode" AND T4."SysNumber" = T3."SysNumber"
WHERE T0."CANCELED" = 'N'
  AND T0."DocEntry" IN ({$documentParameters})
ORDER BY T0."DocEntry" DESC, T1."ItemCode", T4."DistNumber"
SQL;
    }

    private function parameterPlaceholders(string $prefix, int $batchSize): string
    {
        return collect(range(1, max(1, $batchSize)))
            ->map(fn (int $index): string => ':'.$prefix.$index)
            ->implode(', ');
    }
}
