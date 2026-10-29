<?php

namespace App\Http\Controllers\Operations;

use App\Application\Purchasing\SubmitPurchaseRequest;
use App\Exceptions\SapSubmissionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseRequestRequest;
use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapCostCenterCache;
use App\Models\SapDocument;
use App\Models\SapItemCache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class PurchaseRequestController extends Controller
{
    public function index(Request $request, SapCompanyContext $companies): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['PENDING', 'APPROVED', 'REJECTED'])],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        return view('operations.purchase-requests.index', [
            'documents' => SapDocument::query()
                ->forCompany($companies->current())
                ->visibleTo($request->user())
                ->where('document_type', 'PURCHASE_REQUEST')
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('sap_doc_num', 'like', "%{$search}%")
                        ->orWhere('fpk_number', 'like', "%{$search}%")
                        ->orWhere('normalized_data', 'like', "%{$search}%");
                }))
                ->when(filled($filters['status'] ?? null), fn ($query) => $query->whereHas('approval', fn ($approval) => $approval->where('status', $filters['status'])))
                ->with('approval.steps')
                ->latest('updated_at')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request, SapCompanyContext $companies): View
    {
        $companyDb = $companies->current();
        $selectedItemIds = collect(old('lines', []))->pluck('item_id')->filter()->all();
        $selectedVehicleId = old('vehicle_cost_center_id');

        return view('operations.purchase-requests.create', [
            'profile' => $request->user()->operationalProfile,
            'transactionUuid' => (string) Str::uuid(),
            'selectedItems' => SapItemCache::query()
                ->forCompany($companyDb)
                ->whereIn('id', $selectedItemIds)
                ->get()
                ->keyBy('id'),
            'selectedVehicle' => SapCostCenterCache::query()
                ->forCompany($companyDb)
                ->where('dimension', config('sap.dimensions.vehicle'))
                ->where('is_active', true)
                ->find($selectedVehicleId),
        ]);
    }

    public function store(StorePurchaseRequestRequest $request, SubmitPurchaseRequest $submit): RedirectResponse
    {
        try {
            $document = $submit->handle($request->validated(), $request->user(), $request->ip());

            return to_route('purchase-requests.index')->with('status', "Purchase Request #{$document->id} berhasil diajukan untuk approval. Dokumen belum dikirim ke SAP.");
        } catch (ConnectionException) {
            return back()->withErrors(['sap' => 'Koneksi SAP sedang terputus. Pengajuan belum dapat disiapkan karena validasi field SAP gagal.'])->withInput();
        } catch (SapBusinessException|SapSubmissionException $exception) {
            return back()->withErrors(['sap' => $exception->getMessage()])->withInput();
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['sap' => 'Pengajuan belum dapat disimpan. Periksa konfigurasi approval dan coba kembali.'])->withInput();
        }
    }
}
