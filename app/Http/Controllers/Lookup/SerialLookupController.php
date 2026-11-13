<?php

namespace App\Http\Controllers\Lookup;

use App\Application\Inventory\GoodsIssueSerialSelection;
use App\Domain\Sap\Enums\SerialIssueRequirement;
use App\Exceptions\SapSubmissionException;
use App\Http\Controllers\Controller;
use App\Integration\Sap\MasterData\SerialStockGateway;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * BP-12 / TD-13: serials that SAP holds for one item in the signed-in user's own warehouse. The company is the session
 * company and the warehouse comes from the profile; the browser sends only the item and a search text.
 */
class SerialLookupController extends Controller
{
    public function __invoke(
        Request $request,
        SapCompanyContext $companies,
        GoodsIssueSerialSelection $selection,
        SerialStockGateway $serials,
    ): JsonResponse {
        Gate::authorize('create', SapDocument::class);
        abort_unless($selection->isEnabled(), 404);

        $validated = $request->validate([
            'item_id' => ['required', 'integer'],
            'q' => ['nullable', 'string', 'max:50'],
        ]);
        $warehouseCode = trim((string) $request->user()->operationalProfile?->warehouse_code);
        $item = SapItemCache::query()
            ->forCompany($companies->current())
            ->where('is_active', true)
            ->where('is_inventory_item', true)
            ->find((int) $validated['item_id']);

        if (! $item || $warehouseCode === '') {
            return response()->json(['message' => 'Item atau warehouse pengguna tidak ditemukan.'], 422);
        }

        if ($item->serialIssueRequirement() !== SerialIssueRequirement::SerialRequired) {
            return response()->json(['message' => "Item {$item->item_code} tidak dikelola serial di SAP."], 422);
        }

        try {
            $result = $serials->available($item->item_code, $warehouseCode, $validated['q'] ?? null);
        } catch (SapSubmissionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json([
            'data' => $result['serials'],
            'meta' => [
                'item_code' => $item->item_code,
                'warehouse_code' => $warehouseCode,
                'incomplete' => $result['incomplete'],
            ],
        ]);
    }
}
