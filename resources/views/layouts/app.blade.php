<!doctype html>
<html lang="id" data-bs-theme="light" style="color-scheme: light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Operational Portal') · SAP B1</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<div class="app-wrapper">
    <nav class="app-header navbar navbar-expand bg-white">
        <div class="container-fluid">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <button class="nav-link btn btn-link" data-lte-toggle="sidebar" type="button" aria-label="Buka menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                </li>
            </ul>
            <ul class="navbar-nav ms-auto align-items-center gap-3">
                <li class="nav-item d-none d-md-block">
                    <div class="context-chip">
                        <strong>{{ auth()->user()->operationalProfile->pool->code }} · {{ auth()->user()->operationalProfile->department->code }}</strong>
                        <span>{{ auth()->user()->name }} · {{ auth()->user()->role->label() }} · DB {{ session('sap_company_db') }}</span>
                    </div>
                </li>
                <li class="nav-item">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                            <i class="fa-solid fa-right-from-bracket me-1"></i> Keluar
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </nav>

    <aside class="app-sidebar shadow" data-bs-theme="dark">
        <div class="sidebar-brand">
            <a href="{{ route('dashboard') }}" class="brand-link text-decoration-none">
                <img class="sidebar-company-logo" src="{{ asset('images/logo-indra-angkola.jpg') }}" alt="Indra Angkola">
                <span class="brand-text">Operational Portal</span>
            </a>
        </div>
        <div class="sidebar-wrapper">
            <nav aria-label="Navigasi utama">
                <ul class="nav sidebar-menu flex-column nav-sidebar" data-lte-toggle="treeview" role="menu">
                    <li class="nav-header">OPERASIONAL</li>
                    <li class="nav-item">
                        <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                            <i class="nav-icon fa-solid fa-gauge-high"></i><p>Dashboard</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('purchase-requests.index') }}" class="nav-link {{ request()->routeIs('purchase-requests.*') ? 'active' : '' }}">
                            <i class="nav-icon fa-solid fa-file-circle-plus"></i><p>Purchase Request</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('purchase-progress.index') }}" class="nav-link {{ request()->routeIs('purchase-progress.*') ? 'active' : '' }}">
                            <i class="nav-icon fa-solid fa-route"></i><p>Purchase Progress</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('ready-to-receive.index') }}" class="nav-link {{ request()->routeIs('ready-to-receive.*') ? 'active' : '' }}">
                            <i class="nav-icon fa-solid fa-truck-ramp-box"></i><p>Goods Receipt PO</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="{{ route('goods-issues.index') }}" class="nav-link {{ request()->routeIs('goods-issues.*') ? 'active' : '' }}">
                            <i class="nav-icon fa-solid fa-arrow-right-from-bracket"></i><p>Goods Issue</p>
                        </a>
                    </li>
                    @can('approve-goods-issues')
                        <li class="nav-item">
                            <a href="{{ route('goods-issue-approvals.index') }}" class="nav-link {{ request()->routeIs('goods-issue-approvals.*') ? 'active' : '' }}">
                                <i class="nav-icon fa-solid fa-user-check"></i><p>Approval Goods Issue</p>
                            </a>
                        </li>
                    @endcan
                    <li class="nav-item">
                        <a href="{{ route('sparepart-report.index') }}" class="nav-link {{ request()->routeIs('sparepart-report.*') ? 'active' : '' }}">
                            <i class="nav-icon fa-solid fa-chart-column"></i><p>Report Beban Sparepart</p>
                        </a>
                    </li>
                    @if(auth()->user()->can('manage-users') || auth()->user()->can('manage-approval-workflows') || auth()->user()->can('view-integration-monitor') || auth()->user()->can('view-system-configuration'))
                        <li class="nav-header">ADMINISTRASI</li>
                        @can('view-integration-monitor')
                        <li class="nav-item">
                            <a href="{{ route('admin.integration-monitor') }}" class="nav-link {{ request()->routeIs('admin.integration-monitor') ? 'active' : '' }}">
                                <i class="nav-icon fa-solid fa-plug-circle-check"></i><p>Integration Monitor</p>
                            </a>
                        </li>
                        @endcan
                        @can('manage-users')
                        <li class="nav-item">
                            <a href="{{ route('admin.users.index') }}" class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                                <i class="nav-icon fa-solid fa-users"></i><p>User</p>
                            </a>
                        </li>
                        @endcan
                        @can('manage-approval-workflows')
                        <li class="nav-item">
                            <a href="{{ route('admin.approval-workflows.index') }}" class="nav-link {{ request()->routeIs('admin.approval-workflows.*') ? 'active' : '' }}">
                                <i class="nav-icon fa-solid fa-code-branch"></i><p>Konfigurasi Approval</p>
                            </a>
                        </li>
                        @endcan
                        @can('view-system-configuration')
                        <li class="nav-item">
                            <a href="{{ route('admin.configuration') }}" class="nav-link {{ request()->routeIs('admin.configuration') ? 'active' : '' }}">
                                <i class="nav-icon fa-solid fa-gears"></i><p>Konfigurasi Sistem</p>
                            </a>
                        </li>
                        @endcan
                    @endif
                </ul>
            </nav>
        </div>
    </aside>

    <main class="app-main">
        <div class="app-content-header">
            <div class="container-fluid">
                <div class="d-flex flex-column flex-md-row align-items-md-end justify-content-between gap-2">
                    <div>
                        <h1 class="page-title">@yield('page-title')</h1>
                        <p class="page-subtitle">@yield('page-subtitle')</p>
                    </div>
                    @yield('page-actions')
                </div>
            </div>
        </div>
        <div class="app-content pb-4">
            <div class="container-fluid">
                @if(session('status'))
                    <div class="alert alert-success">{{ session('status') }}</div>
                @endif
                @yield('content')
            </div>
        </div>
    </main>

    <footer class="app-footer small">
        <strong>SAP B1 Operational Web Portal</strong>
        <span class="float-end d-none d-sm-inline">System of Record: SAP Business One</span>
    </footer>
</div>
@livewireScripts
@stack('scripts')
</body>
</html>
