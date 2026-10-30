<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Shared\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApprovalWorkflowRequest;
use App\Integration\Sap\SapCompanyContext;
use App\Models\ApprovalWorkflow;
use App\Models\DocumentApproval;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ApprovalWorkflowController extends Controller
{
    public function index(Request $request, SapCompanyContext $companies): View
    {
        $companyDb = $companies->current();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:PURCHASE_REQUEST,GOODS_ISSUE'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        return view('admin.approval-workflows.index', [
            'workflows' => ApprovalWorkflow::query()
                ->where('company_db', $companyDb)
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhereHas('submitter', fn ($submitter) => $submitter
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhereHas('operationalProfile', fn ($profile) => $profile->where('warehouse_code', 'like', "%{$search}%")))
                        ->orWhereHas('steps.approver', fn ($approver) => $approver->where('name', 'like', "%{$search}%"));
                }))
                ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('document_type', $filters['status']))
                ->with(['submitter.operationalProfile', 'steps.approver'])
                ->orderBy('document_type')
                ->orderBy('submitter_id')
                ->get(),
            'pendingApprovals' => DocumentApproval::query()
                ->where('status', 'PENDING')
                ->whereHas('document', fn ($query) => $query->where('company_db', $companyDb))
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->whereHas('document', fn ($document) => $document
                        ->where('sap_doc_num', 'like', "%{$search}%")
                        ->orWhere('fpk_number', 'like', "%{$search}%")
                        ->orWhere('normalized_data', 'like', "%{$search}%"))
                        ->orWhereHas('submitter', fn ($submitter) => $submitter->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('steps', fn ($step) => $step->where('approver_name', 'like', "%{$search}%"));
                }))
                ->when(filled($filters['status'] ?? null), fn ($query) => $query->whereHas('document', fn ($document) => $document->where('document_type', $filters['status'])))
                ->with(['document', 'submitter', 'steps.approver'])
                ->latest('submitted_at')
                ->limit(50)
                ->get(),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.approval-workflows.form', [
            'workflow' => new ApprovalWorkflow(['is_active' => true]),
            'users' => $this->approvers(),
            'submitters' => $this->submitters(),
            'requestedType' => $request->string('document_type')->toString(),
        ]);
    }

    public function store(StoreApprovalWorkflowRequest $request, SapCompanyContext $companies): RedirectResponse
    {
        $this->persist(new ApprovalWorkflow, $request->validated(), $companies->current());

        return to_route('admin.approval-workflows.index')->with('status', 'Workflow approval berhasil dibuat.');
    }

    public function edit(ApprovalWorkflow $approvalWorkflow, SapCompanyContext $companies): View
    {
        abort_unless($approvalWorkflow->company_db === $companies->current(), 404);

        return view('admin.approval-workflows.form', [
            'workflow' => $approvalWorkflow->load(['submitter.operationalProfile', 'steps.approver']),
            'users' => $this->approvers(),
            'submitters' => $this->submitters(),
            'requestedType' => $approvalWorkflow->document_type,
        ]);
    }

    public function update(StoreApprovalWorkflowRequest $request, ApprovalWorkflow $approvalWorkflow, SapCompanyContext $companies): RedirectResponse
    {
        abort_unless($approvalWorkflow->company_db === $companies->current(), 404);
        $this->persist($approvalWorkflow, $request->validated(), $companies->current());

        return to_route('admin.approval-workflows.index')->with('status', 'Workflow approval berhasil diperbarui. Pengajuan lama tetap memakai snapshot approver sebelumnya.');
    }

    /** @param array<string, mixed> $data */
    private function persist(ApprovalWorkflow $workflow, array $data, string $companyDb): void
    {
        DB::transaction(function () use ($workflow, $data, $companyDb): void {
            $duplicate = ApprovalWorkflow::query()
                ->where('company_db', $companyDb)
                ->where('document_type', $data['document_type'])
                ->where('submitter_id', $data['submitter_id'])
                ->when($workflow->exists, fn ($query) => $query->whereKeyNot($workflow->id))
                ->exists();

            if ($duplicate) {
                abort(422, 'Workflow untuk jenis dokumen dan user Admin Operasional ini sudah ada. Silakan edit workflow yang tersedia.');
            }

            $submitter = User::query()->with('operationalProfile')->findOrFail($data['submitter_id']);

            $workflow->fill([
                'company_db' => $companyDb,
                'submitter_id' => $submitter->id,
                'pool_id' => $submitter->operationalProfile?->pool_id,
                'document_type' => $data['document_type'],
                'name' => $data['name'],
                'is_active' => (bool) $data['is_active'],
            ])->save();
            $workflow->steps()->delete();

            $approvers = User::query()
                ->whereIn('id', collect($data['steps'])->pluck('approver_id'))
                ->get()
                ->keyBy('id');

            foreach (array_values($data['steps']) as $index => $step) {
                $approver = $approvers->get($step['approver_id']);

                $workflow->steps()->create([
                    'approver_id' => $step['approver_id'],
                    'step_order' => $index + 1,
                    'notification_email' => $approver->email,
                ]);
            }
        });
    }

    /** @return Collection<int, User> */
    private function approvers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->with('operationalProfile')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, User> */
    private function submitters(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->where('role', UserRole::AdminOperational)
            ->whereHas('operationalProfile', fn ($query) => $query
                ->whereNotNull('warehouse_code')
                ->where('warehouse_code', '!=', ''))
            ->with('operationalProfile')
            ->orderBy('name')
            ->get();
    }
}
