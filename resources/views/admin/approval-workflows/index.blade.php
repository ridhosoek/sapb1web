@extends('layouts.app')

@section('title', 'Konfigurasi Approval')
@section('page-title', 'Konfigurasi Approval')
@section('page-subtitle', 'Atur urutan approver PR dan Goods Issue untuk database SAP yang sedang dipilih.')

@section('page-actions')
    <a class="btn btn-sm btn-primary" href="{{ route('admin.approval-workflows.create') }}"><i class="fa-solid fa-plus me-1"></i> Tambah Workflow</a>
@endsection

@section('content')
    <div class="alert alert-info py-2">
        Perubahan workflow hanya berlaku untuk pengajuan baru. Purchase Request memakai email berurutan; Goods Issue muncul langsung di menu Approval Goods Issue milik Admin HO tanpa email.
    </div>

    <section class="operational-panel mb-3">
        <header class="panel-header"><h2 class="panel-title">Workflow Aktif</h2><span class="badge text-bg-light border">DB {{ session('sap_company_db') }}</span></header>
        <div class="table-responsive">
            <table class="table table-hover table-operational align-middle">
                <thead><tr><th>Dokumen</th><th>Pool / Wilayah</th><th>Nama</th><th>Kanal</th><th>Urutan Approval</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                @forelse($workflows as $workflow)
                    <tr>
                        <td>{{ str($workflow->document_type)->replace('_', ' ')->title() }}</td>
                        <td class="mono">{{ $workflow->pool?->code ?? 'Belum dipetakan' }}</td>
                        <td>{{ $workflow->name }}</td>
                        <td><span class="badge {{ $workflow->document_type === 'GOODS_ISSUE' ? 'text-bg-primary' : 'text-bg-info' }}">{{ $workflow->document_type === 'GOODS_ISSUE' ? 'Portal Web' : 'Email' }}</span></td>
                        <td>
                            @foreach($workflow->steps as $step)
                                <span class="badge text-bg-light border me-1">{{ $step->step_order }}. {{ $step->approver->name }}</span>
                            @endforeach
                        </td>
                        <td><span class="badge {{ $workflow->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $workflow->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.approval-workflows.edit', $workflow) }}">Edit</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-code-branch"></i>Belum ada workflow per Pool. PR dan Goods Issue belum dapat diajukan.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="operational-panel">
        <header class="panel-header"><h2 class="panel-title">Approval Menunggu</h2><span class="badge text-bg-light border">{{ $pendingApprovals->count() }} pengajuan</span></header>
        <div class="table-responsive">
            <table class="table table-hover table-operational align-middle">
                <thead><tr><th>Dokumen</th><th>Pengaju</th><th>Approver Saat Ini</th><th>Status Notifikasi</th><th>Diajukan</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                @forelse($pendingApprovals as $approval)
                    @php($currentStep = $approval->steps->firstWhere('step_order', $approval->current_step))
                    <tr>
                        <td><a href="{{ route('documents.show', $approval->document) }}">{{ str($approval->document->document_type)->replace('_', ' ')->title() }} #{{ $approval->document->id }}</a></td>
                        <td>{{ $approval->submitter->name }}</td>
                        <td>{{ $currentStep?->approver_name ?? '—' }} <span class="text-secondary">(tahap {{ $approval->current_step }})</span><div class="small text-secondary">{{ $approval->document->document_type === 'GOODS_ISSUE' ? 'Admin HO · Portal Web' : ($currentStep?->notification_email ?? 'Email belum tersedia') }}</div></td>
                        <td>
                            @if($approval->document->document_type === 'GOODS_ISSUE')
                                <span class="badge text-bg-primary">Menunggu di portal</span>
                            @elseif($currentStep?->notification_sent_at)
                                <span class="badge text-bg-success">{{ config('mail.default') === 'log' ? 'Simulasi tercatat' : 'Terkirim' }}</span><div class="small text-secondary">{{ $currentStep->notification_sent_at->format('d/m/Y H:i') }}</div>
                            @elseif($currentStep?->notification_failed_at)
                                <span class="badge text-bg-danger">Gagal</span><div class="small text-secondary">Percobaan {{ $currentStep->notification_attempts }}</div>
                            @elseif($currentStep?->notification_queued_at)
                                <span class="badge text-bg-warning">Dalam antrean</span>
                            @else
                                <span class="badge text-bg-secondary">Belum dikirim</span>
                            @endif
                        </td>
                        <td>{{ $approval->submitted_at->diffForHumans() }}</td>
                        <td class="text-end">
                            @if($currentStep && $approval->document->document_type === 'PURCHASE_REQUEST')
                                <form method="POST" action="{{ route('admin.approval-steps.email', $currentStep) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="fa-solid fa-envelope me-1"></i> {{ $currentStep->notification_queued_at ? 'Kirim Ulang' : 'Kirim Email' }}</button>
                                </form>
                            @elseif($currentStep)
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('documents.show', $approval->document) }}">Lihat Detail</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="empty-state"><i class="fa-regular fa-circle-check"></i>Tidak ada approval yang menunggu.</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
