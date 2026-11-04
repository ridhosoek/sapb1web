<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapItemCache;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ItemMasterController extends Controller
{
    public function index(Request $request, SapCompanyContext $companies): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:100'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $items = null;

        if ($search !== '') {
            $items = SapItemCache::query()
                ->forCompany($companies->current())
                ->search($search)
                ->orderByDesc('is_active')
                ->orderBy('item_code')
                ->paginate(20)
                ->withQueryString();
        }

        return view('operations.item-master.index', [
            'items' => $items,
            'filters' => $filters,
            'search' => $search,
        ]);
    }

    public function show(int $item, SapCompanyContext $companies): View
    {
        $item = SapItemCache::query()
            ->forCompany($companies->current())
            ->with(['warehouseStocks' => fn ($query) => $query
                ->forCompany($companies->current())
                ->orderBy('warehouse_code')])
            ->findOrFail($item);

        return view('operations.item-master.show', ['item' => $item]);
    }
}
