@extends('layouts.app')

@php
    $isEdit = $workflow->exists;
    $initialSteps = old('steps', $isEdit
        ? $workflow->steps->map(fn($step) => ['approver_id' => $step->approver_id])->all()
        : (in_array($requestedType, ['', 'PURCHASE_REQUEST'], true)
            ? [['approver_id' => ''], ['approver_id' => '']]
            : [['approver_id' => '']]));
@endphp

@section('title', $isEdit ? 'Edit Workflow Approval' : 'Tambah Workflow Approval')
@section('page-title', $isEdit ? 'Edit Workflow Approval' : 'Tambah Workflow Approval')
@section('page-subtitle', 'Approver diproses berurutan dari tahap pertama sampai terakhir.')

@section('page-actions')
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.approval-workflows.index') }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali</a>
@endsection

@section('content')
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ $isEdit ? route('admin.approval-workflows.update', $workflow) : route('admin.approval-workflows.store') }}">
        @csrf
        @if($isEdit) @method('PUT') @endif
        <section class="transaction-section">
            <header class="section-heading"><h2>Identitas Workflow</h2></header>
            <div class="transaction-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="document_type">Jenis Dokumen *</label>
                        <select class="form-select" id="document_type" name="document_type" required>
                            <option value="PURCHASE_REQUEST" @selected(old('document_type', $requestedType) === 'PURCHASE_REQUEST')>Purchase Request</option>
                            <option value="GOODS_ISSUE" @selected(old('document_type', $requestedType) === 'GOODS_ISSUE')>Goods Issue</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="submitter_id">User Admin Operasional *</label>
                        <select class="form-select" id="submitter_id" name="submitter_id" required>
                            <option value="">Pilih Admin Operasional</option>
                            @foreach($submitters as $submitter)
                                <option value="{{ $submitter->id }}" data-warehouse-code="{{ $submitter->operationalProfile?->warehouse_code }}" @selected((string) old('submitter_id', $workflow->submitter_id) === (string) $submitter->id)>
                                    {{ $submitter->name }} — {{ $submitter->operationalProfile?->warehouse_code }} — {{ $submitter->email }}
                                </option>
                            @endforeach
                        </select>
                        @error('submitter_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4"><label class="form-label" for="name">Nama Workflow *</label><input class="form-control" id="name" name="name" value="{{ old('name', $workflow->name) }}" required></div>
                    <div class="col-12"><div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" id="is_active" name="is_active" type="checkbox" value="1" @checked(old('is_active', $workflow->is_active ?? true))><label class="form-check-label" for="is_active">Workflow aktif</label></div></div>
                </div>
            </div>
        </section>

        <section class="transaction-section mt-3">
            <header class="section-heading"><div><h2>Urutan Approver</h2><span class="small text-secondary" data-approval-help>Untuk PR, susun Manager Operasional lalu Manager Admin HO sesuai kebijakan perusahaan.</span></div><button class="btn btn-sm btn-outline-primary" type="button" data-add-approval-step><i class="fa-solid fa-plus me-1"></i> Tambah Tahap</button></header>
            <div class="transaction-body" data-approval-steps>
                @foreach($initialSteps as $index => $step)
                    <div class="row g-2 align-items-end mb-2" data-approval-step>
                        <div class="col-auto"><span class="badge text-bg-secondary mb-2" data-step-number>{{ $index + 1 }}</span></div>
                        <div class="col"><label class="form-label">Approver *</label><select class="form-select" name="steps[{{ $index }}][approver_id]" required><option value="">Pilih user sesuai Warehouse dan jenis dokumen</option>@foreach($users as $user)<option value="{{ $user->id }}" data-role="{{ $user->role->value }}" data-warehouse-code="{{ $user->operationalProfile?->warehouse_code }}" @selected((string) data_get($step, 'approver_id') === (string) $user->id)>{{ $user->name }} — {{ $user->role->label() }} — {{ $user->operationalProfile?->warehouse_code ?: 'Lintas Warehouse' }} — {{ $user->email }}</option>@endforeach</select></div>
                        <div class="col-auto"><button class="btn btn-outline-danger" type="button" data-remove-approval-step aria-label="Hapus tahap"><i class="fa-solid fa-trash"></i></button></div>
                    </div>
                @endforeach
            </div>
        </section>
        <div class="sticky-action-bar"><span data-approval-channel-help>Email PR dikirim ke alamat akun approver. Tautan berlaku {{ config('approval.link_expiration_hours') }} jam dan hanya dapat dipakai sekali.</span><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan Workflow</button></div>
    </form>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const container = document.querySelector('[data-approval-steps]');
    const addButton = document.querySelector('[data-add-approval-step]');
    const documentType = document.querySelector('#document_type');
    const submitter = document.querySelector('#submitter_id');
    const approvalHelp = document.querySelector('[data-approval-help]');
    const channelHelp = document.querySelector('[data-approval-channel-help]');
    const roleValues = {{ Illuminate\Support\Js::from([
        'managerOperational' => \App\Domain\Shared\Enums\UserRole::ManagerOperational->value,
        'managerAdminHo' => \App\Domain\Shared\Enums\UserRole::ManagerAdminHo->value,
        'adminHo' => \App\Domain\Shared\Enums\UserRole::AdminHo->value,
        'superAdmin' => \App\Domain\Shared\Enums\UserRole::SuperAdmin->value,
    ]) }};
    const users = {{ Illuminate\Support\Js::from($users->map(fn($user) => [
        'id' => $user->id,
        'role' => $user->role->value,
        'warehouseCode' => $user->operationalProfile?->warehouse_code,
        'label' => $user->name.' — '.$user->role->label().' — '.($user->operationalProfile?->warehouse_code ?: 'Lintas Warehouse').' — '.$user->email,
    ])->values()) }};
    const escapeHtml = value => String(value).replace(/[&<>'"]/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'}[character]));
    const isEligible = user => {
        if (documentType.value === 'GOODS_ISSUE') {
            return [roleValues.adminHo, roleValues.superAdmin].includes(user.role);
        }

        if (user.role === roleValues.managerOperational) {
            return user.warehouseCode !== null
                && user.warehouseCode !== ''
                && user.warehouseCode === submitter.selectedOptions[0]?.dataset.warehouseCode;
        }

        return [roleValues.managerAdminHo, roleValues.superAdmin].includes(user.role);
    };
    const refreshApproverOptions = () => {
        const isGoodsIssue = documentType.value === 'GOODS_ISSUE';
        approvalHelp.textContent = isGoodsIssue
            ? 'Goods Issue disetujui Admin HO melalui portal setelah memeriksa detail pengeluaran.'
            : 'Untuk PR, susun Manager Operasional lalu Manager Admin HO sesuai kebijakan perusahaan.';
        channelHelp.textContent = isGoodsIssue
            ? 'Goods Issue muncul di menu Approval Goods Issue milik approver. Email tidak dikirim.'
            : 'Email PR dikirim ke alamat akun approver. Tautan berlaku {{ config('approval.link_expiration_hours') }} jam dan hanya dapat dipakai sekali.';

        container.querySelectorAll('select[name*="[approver_id]"]').forEach(select => {
        [...select.options].slice(1).forEach(option => {
            const user = users.find(candidate => String(candidate.id) === option.value);
            const eligible = user && isEligible(user);
            option.hidden = !eligible;
            option.disabled = !eligible;
        });
        if (select.selectedOptions[0]?.disabled) select.value = '';
        });
    };
    const refresh = () => container.querySelectorAll('[data-approval-step]').forEach((row, index) => {
        row.querySelector('[data-step-number]').textContent = index + 1;
        row.querySelectorAll('[name]').forEach(input => input.name = input.name.replace(/steps\[\d+\]/, `steps[${index}]`));
    });
    addButton.addEventListener('click', () => {
        const index = container.querySelectorAll('[data-approval-step]').length;
        const row = document.createElement('div');
        row.className = 'row g-2 align-items-end mb-2';
        row.dataset.approvalStep = '';
        const options = users.filter(isEligible).map(user => `<option value="${user.id}" data-role="${escapeHtml(user.role)}" data-warehouse-code="${escapeHtml(user.warehouseCode ?? '')}">${escapeHtml(user.label)}</option>`).join('');
        row.innerHTML = `<div class="col-auto"><span class="badge text-bg-secondary mb-2" data-step-number>${index + 1}</span></div><div class="col"><label class="form-label">Approver *</label><select class="form-select" name="steps[${index}][approver_id]" required><option value="">Pilih user sesuai Warehouse dan jenis dokumen</option>${options}</select></div><div class="col-auto"><button class="btn btn-outline-danger" type="button" data-remove-approval-step aria-label="Hapus tahap"><i class="fa-solid fa-trash"></i></button></div>`;
        container.appendChild(row);
    });
    container.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-approval-step]');
        if (!button || container.querySelectorAll('[data-approval-step]').length === 1) return;
        button.closest('[data-approval-step]').remove();
        refresh();
    });
    documentType.addEventListener('change', refreshApproverOptions);
    submitter.addEventListener('change', refreshApproverOptions);
    refreshApproverOptions();
});
</script>
@endpush
