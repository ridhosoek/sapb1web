<?php

namespace App\Livewire;

use App\Integration\Sap\SapCompanyContext;
use App\Models\SapItemCache;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ItemSearch extends Component
{
    public string $search = '';

    public function render(SapCompanyContext $companies): View
    {
        $term = trim($this->search);
        $items = mb_strlen($term) >= 2
            ? SapItemCache::query()
                ->forCompany($companies->current())
                ->where('is_active', true)
                ->where('is_inventory_item', true)
                ->search($term)
                ->orderBy('item_code')
                ->limit(12)
                ->get()
            : collect();

        return view('livewire.item-search', compact('items'));
    }
}
