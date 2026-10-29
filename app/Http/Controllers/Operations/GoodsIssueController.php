<?php

namespace App\Http\Controllers\Operations;

use App\Application\Inventory\StoreGoodsIssueAttachments;
use App\Application\Inventory\SubmitGoodsIssue;
use App\Exceptions\SapSubmissionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGoodsIssueRequest;
use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Integration\Sap\SapCompanyContext;
use App\Models\IssueType;
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

class GoodsIssueController extends Controller
{
    public function index(Request $request, SapCompanyContext $companies): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['PENDING', 'APPROVED', 'REJECTED'])],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        return view('operations.goods-issues.index', [
            'documents' => SapDocument::query()
                ->forCompany($companies->current())
                ->visibleTo($request->user())
                ->where('document_type', 'GOODS_ISSUE')
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
        $profile = $request->user()->operationalProfile;
        $companyDb = $companies->current();
        $selectedItemIds = collect(old('lines', []))->pluck('item_id')->filter()->all();
        $selectedVehicleId = old('vehicle_cost_center_id');

        return view('operations.goods-issues.create', [
            'profile' => $profile,
            'transactionUuid' => (string) Str::uuid(),
            'selectedItems' => SapItemCache::query()
                ->forCompany($companyDb)
                ->whereIn('id', $selectedItemIds)
                ->with(['warehouseStocks' => fn ($query) => $query
                    ->forCompany($companyDb)
                    ->forWarehouse($profile->warehouse_code ?: '')])
                ->get()
                ->keyBy('id'),
            'selectedVehicle' => SapCostCenterCache::query()
                ->forCompany($companyDb)
                ->where('dimension', config('sap.dimensions.vehicle'))
                ->where('is_active', true)
                ->find($selectedVehicleId),
            'issueTypes' => IssueType::query()->where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function store(
        StoreGoodsIssueRequest $request,
        SubmitGoodsIssue $submit,
        StoreGoodsIssueAttachments $storeAttachments,
    ): RedirectResponse {
        try {
            $document = $submit->handle($request->safe()->except(['attachments']), $request->user(), $request->ip());

            if ($request->hasFile('attachments')) {
                try {
                    $storeAttachments->handle(
                        document: $document,
                        files: $request->file('attachments', []),
                        user: $request->user(),
                        ipAddress: $request->ip(),
                    );
                } catch (Throwable $exception) {
                    report($exception);

                    return to_route('documents.show', $document)
                        ->with('warning', 'Goods Issue berhasil diajukan, tetapi scan FPK belum tersimpan. Unggah kembali lampiran dari halaman detail.');
                }
            }

            return to_route('goods-issues.index')->with('status', "Goods Issue #{$document->id} berhasil diajukan ke tim Admin HO. Stok belum dikeluarkan dari SAP.");
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
