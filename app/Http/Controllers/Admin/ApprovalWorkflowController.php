<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApprovalWorkflowRequest;
use App\Integration\Sap\SapCompanyContext;
use App\Models\ApprovalWorkflow;
use App\Models\ControlPool;
use App\Models\DocumentApproval;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ApprovalWorkflowController extends Controller
{
    public function index(SapCompanyContext $companies): View
    {
        $companyDb = $companies->current();

        return view('admin.approval-workflows.index', [
            'workflows' => ApprovalWorkflow::query()
                ->where('company_db', $companyDb)
                ->with(['pool', 'steps.approver'])
                ->orderBy('document_type')
                ->orderBy('pool_id')
                ->get(),
            'pendingApprovals' => DocumentApproval::query()
                ->where('status', 'PENDING')
                ->whereHas('document', fn ($query) => $query->where('company_db', $companyDb))
                ->with(['document', 'submitter', 'steps.approver'])
                ->latest('submitted_at')
                ->limit(50)
                ->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.approval-workflows.form', [
            'workflow' => new ApprovalWorkflow(['is_active' => true]),
            'users' => $this->approvers(),
            'pools' => $this->pools(),
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
            'workflow' => $approvalWorkflow->load('steps.approver'),
            'users' => $this->approvers(),
            'pools' => $this->pools(),
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
                ->where('pool_id', $data['pool_id'])
                ->when($workflow->exists, fn ($query) => $query->whereKeyNot($workflow->id))
                ->exists();

            if ($duplicate) {
                abort(422, 'Workflow untuk jenis dokumen dan Pool ini sudah ada. Silakan edit workflow yang tersedia.');
            }

            $workflow->fill([
                'company_db' => $companyDb,
                'pool_id' => $data['pool_id'],
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
            ->with('operationalProfile.pool')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, ControlPool> */
    private function pools(): Collection
    {
        return ControlPool::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }
}
