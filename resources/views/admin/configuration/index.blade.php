@extends('layouts.app')

@section('title', 'Konfigurasi Sistem')
@section('page-title', 'Konfigurasi Sistem')
@section('page-subtitle', 'Konfigurasi efektif portal tanpa menampilkan password, token, atau credential SAP.')

@section('content')
    <div class="alert alert-info py-2"><i class="fa-solid fa-circle-info me-1"></i>Halaman ini read-only. Koneksi dan credential dikelola melalui <span class="mono-field">.env</span>; mapping aplikasi berada pada <span class="mono-field">config/sap.php</span>.</div>

    <div class="row g-3 mb-3">
        <div class="col-xl-7"><section class="operational-panel h-100">
            <header class="panel-header"><h2 class="panel-title">SAP Service Layer</h2><span class="badge text-bg-light border">DB {{ $currentCompanyDb }}</span></header>
            <div class="table-responsive"><table class="table table-operational mb-0"><tbody>
                <tr><th>Base URL</th><td class="mono">{{ $sapConnection['url'] }}</td></tr>
                <tr><th>TLS Verification</th><td>{{ $sapConnection['verify_tls'] ? 'Aktif' : 'Nonaktif' }}</td></tr>
                <tr><th>Timeout</th><td>{{ $sapConnection['connect_timeout'] }} detik connect · {{ $sapConnection['request_timeout'] }} detik request</td></tr>
                <tr><th>SAP Session</th><td>{{ $sapConnection['session_store'] }} · TTL {{ $sapConnection['session_ttl'] }} detik</td></tr>
                <tr><th>Metadata Cache</th><td>TTL {{ $sapConnection['metadata_ttl'] }} detik</td></tr>
            </tbody></table></div>
        </section></div>
        <div class="col-xl-5"><section class="operational-panel h-100">
            <header class="panel-header"><h2 class="panel-title">Runtime Aplikasi</h2></header>
            <div class="table-responsive"><table class="table table-operational mb-0"><tbody>
                <tr><th>Environment</th><td class="mono">{{ $runtime['environment'] }}</td></tr>
                <tr><th>Debug</th><td>{{ $runtime['debug'] ? 'Aktif' : 'Nonaktif' }}</td></tr>
                <tr><th>Queue</th><td class="mono">{{ $runtime['queue'] }}</td></tr>
                <tr><th>Cache</th><td class="mono">{{ $runtime['cache'] }}</td></tr>
                <tr><th>Session</th><td class="mono">{{ $runtime['session'] }}</td></tr>
            </tbody></table></div>
        </section></div>
    </div>

    <section class="operational-panel mb-3">
        <header class="panel-header"><h2 class="panel-title">Database SAP dan Master Data Cache</h2><span class="badge text-bg-light border">{{ $companyStatus->count() }} database</span></header>
        <div class="table-responsive"><table class="table table-hover table-operational"><thead><tr><th>Company DB</th><th>Active Items</th><th>Purchase Items</th><th>Stock Rows</th><th>Department</th><th>Pool</th><th>Nopol</th><th>Last Item Sync</th><th>Last Stock Sync</th><th>Last Cost Center Sync</th><th>Last API Success</th></tr></thead><tbody>
        @foreach($companyStatus as $company)
            <tr><td class="mono"><span class="status-dot {{ $company['company_db'] === $currentCompanyDb ? 'success' : 'muted' }}"></span>{{ $company['company_db'] }}</td><td>{{ number_format($company['items']) }}</td><td>{{ number_format($company['purchase_items']) }}</td><td>{{ number_format($company['warehouse_stocks']) }}</td><td>{{ $company['cost_centers'][1] }}</td><td>{{ $company['cost_centers'][2] }}</td><td>{{ $company['cost_centers'][3] }}</td><td>{{ $company['last_item_sync'] ? \Illuminate\Support\Carbon::parse($company['last_item_sync'])->format('d/m/Y H:i') : '—' }}</td><td>{{ $company['last_stock_sync'] ? \Illuminate\Support\Carbon::parse($company['last_stock_sync'])->format('d/m/Y H:i') : '—' }}</td><td>{{ $company['last_cost_center_sync'] ? \Illuminate\Support\Carbon::parse($company['last_cost_center_sync'])->format('d/m/Y H:i') : '—' }}</td><td>{{ $company['last_api_success'] ? \Illuminate\Support\Carbon::parse($company['last_api_success'])->format('d/m/Y H:i') : '—' }}</td></tr>
        @endforeach
        </tbody></table></div>
    </section>

    <div class="row g-3">
        <div class="col-xl-6"><section class="operational-panel h-100">
            <header class="panel-header"><h2 class="panel-title">Field dan Dimensi SAP</h2></header>
            <div class="table-responsive"><table class="table table-operational mb-0"><thead><tr><th>Business Field</th><th>SAP Property</th></tr></thead><tbody>
            @foreach($fieldMappings as $businessField => $sapField)<tr><td>{{ str($businessField)->replace('_', ' ')->title() }}</td><td class="mono">{{ $sapField }}</td></tr>@endforeach
            @foreach($dimensions as $dimension => $number)<tr><td>Dimension {{ str($dimension)->title() }}</td><td class="mono">{{ $number }}</td></tr>@endforeach
            <tr><td>Fallback Low Stock</td><td class="mono">{{ number_format($stockConfiguration['low_threshold'], 2, ',', '.') }}</td></tr>
            <tr><td>Extra Stock Warehouses</td><td class="mono">{{ collect($stockConfiguration['configured_warehouses'])->join(', ') ?: 'Dari profil user' }}</td></tr>
            </tbody></table></div>
        </section></div>
        <div class="col-xl-6"><section class="operational-panel h-100">
            <header class="panel-header"><h2 class="panel-title">Konteks Operasional</h2></header>
            <div class="panel-body">
                <div class="row g-3 mb-3">
                    @foreach([['Pool', $controlCenter['pools']], ['Department', $controlCenter['departments']], ['Vehicle', $controlCenter['vehicles']], ['Active User', $controlCenter['users']]] as [$label, $value])
                        <div class="col-6"><div class="metric-card compact"><div class="metric-label">{{ $label }}</div><div class="metric-value">{{ $value }}</div></div></div>
                    @endforeach
                </div>
                <div class="control-note"><i class="fa-solid fa-link"></i><div><strong>Control Center</strong><span>URL {{ $controlCenter['url_configured'] ? 'sudah' : 'belum' }} dikonfigurasi · Token {{ $controlCenter['token_configured'] ? 'sudah' : 'belum' }} dikonfigurasi.</span></div></div>
            </div>
        </section></div>
    </div>

    <section class="operational-panel mt-3">
        <header class="panel-header"><h2 class="panel-title">Email Approval</h2><span class="badge {{ $mail['smtp_configured'] ? 'text-bg-success' : 'text-bg-warning' }}">{{ $mail['smtp_configured'] ? 'SMTP siap' : 'Mode '.$mail['mailer'] }}</span></header>
        <div class="table-responsive"><table class="table table-operational mb-0"><tbody>
            <tr><th>Pengirim</th><td>{{ $mail['from_name'] }} &lt;<span class="mono">{{ $mail['from_address'] }}</span>&gt;</td></tr>
            <tr><th>Mailer</th><td class="mono">{{ $mail['mailer'] }}</td></tr>
            <tr><th>Masa Berlaku Link</th><td>{{ $mail['approval_link_expiration_hours'] }} jam</td></tr>
            <tr><th>Status</th><td>{{ $mail['smtp_configured'] ? 'Email keluar dapat dikirim melalui SMTP.' : 'Email belum dikirim ke internet. Lengkapi konfigurasi SMTP untuk mengaktifkan pengiriman aktual.' }}</td></tr>
        </tbody></table></div>
    </section>
@endsection
