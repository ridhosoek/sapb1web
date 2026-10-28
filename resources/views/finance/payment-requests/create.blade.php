@extends('layouts.app')

@section('title', 'Payment Request')
@section('page-title', 'Payment Request')
@section('page-subtitle', 'Kontrol pembayaran A/P Invoice sebelum terbentuk Outgoing Payment SAP.')

@section('page-actions')
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('payment-requests.index') }}"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Daftar</a>
        <a class="btn btn-sm btn-outline-secondary" href="{{ route('payment-approvals.index') }}"><i class="fa-solid fa-file-signature me-1"></i> Approval Queue</a>
    </div>
@endsection

@section('content')
    <div class="alert preview-notice py-2" role="status">
        <i class="fa-solid fa-shield-halved me-1"></i>
        Mode rancangan terkontrol: alur dan validasi UI aktif. Penyimpanan UDO dan pembuatan Outgoing Payment belum diaktifkan sampai metadata Service Layer dikonfirmasi.
    </div>

    <section class="operational-panel mb-3">
        <header class="panel-header">
            <h2 class="panel-title">Business Flow yang Dikendalikan</h2>
            <span class="badge text-bg-light border">Maker · Approver · Treasury</span>
        </header>
        <div class="panel-body">
            <div class="payment-flow" aria-label="Alur Payment Request">
                @foreach([
                    ['icon' => 'fa-pen-to-square', 'title' => '1. Draft', 'text' => 'Maker memilih BP dan invoice/account.'],
                    ['icon' => 'fa-paper-plane', 'title' => '2. Submit', 'text' => 'Nilai dan detail dibekukan sebagai snapshot.'],
                    ['icon' => 'fa-user-check', 'title' => '3. Approval', 'text' => 'Persetujuan berjenjang sesuai limit.'],
                    ['icon' => 'fa-building-columns', 'title' => '4. Execute', 'text' => 'Treasury melengkapi bank dan posting date.'],
                    ['icon' => 'fa-circle-check', 'title' => '5. Generated', 'text' => 'Outgoing Payment dibuat satu kali di SAP.'],
                ] as $index => $step)
                    @if($index > 0)<i class="fa-solid fa-chevron-right payment-flow-arrow"></i>@endif
                    <div class="payment-flow-step">
                        <span class="payment-flow-icon"><i class="fa-solid {{ $step['icon'] }}"></i></span>
                        <strong>{{ $step['title'] }}</strong>
                        <small>{{ $step['text'] }}</small>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <form data-payment-request-form novalidate>
        <div class="row g-3">
            <div class="col-xl-7">
                <section class="transaction-section h-100">
                    <header class="section-heading">
                        <h2>Identitas Payment Request</h2>
                        <span class="badge payment-status-badge">DRAFT</span>
                    </header>
                    <div class="transaction-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="payment-series">Series *</label>
                                <select class="form-select mono-field" id="payment-series" name="series" required>
                                    <option value="">Pilih series</option>
                                    <option value="BK0126">BK0126</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="payment-request-no">Payment Req No</label>
                                <input class="form-control mono-field locked-field" id="payment-request-no" value="Otomatis dari SAP" disabled>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="payment-type">Type *</label>
                                <select class="form-select" id="payment-type" name="type" data-payment-type required>
                                    <option value="VENDOR">Vendor</option>
                                    <option value="CUSTOMER">Customer</option>
                                    <option value="ACCOUNT">Account</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="payment-create-date">Create Date</label>
                                <input class="form-control mono-field locked-field" id="payment-create-date" value="{{ now()->format('Y-m-d') }}" disabled>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="payment-required-date">Req. Payment Date *</label>
                                <input class="form-control mono-field" id="payment-required-date" name="required_payment_date" type="date" value="{{ now()->format('Y-m-d') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="payment-currency">Document Currency *</label>
                                <div class="input-group">
                                    <select class="form-select mono-field" id="payment-currency" name="currency" data-payment-currency required>
                                        <option value="IDR">IDR</option>
                                        <option value="USD">USD</option>
                                        <option value="SGD">SGD</option>
                                    </select>
                                    <input class="form-control mono-field locked-field" name="document_rate" data-document-rate type="number" min="0" step="0.0001" value="1" aria-label="Document rate" disabled>
                                </div>
                            </div>
                            <div class="col-md-6" data-bp-fields>
                                <label class="form-label" for="payment-bp-code">BP Code *</label>
                                <input class="form-control mono-field" id="payment-bp-code" name="bp_code" maxlength="50" placeholder="Pilih vendor/customer dari SAP" required>
                            </div>
                            <div class="col-md-6" data-bp-fields>
                                <label class="form-label" for="payment-bp-name">BP Name *</label>
                                <input class="form-control" id="payment-bp-name" name="bp_name" maxlength="255" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="receiving-account-no">Receiving Account No</label>
                                <input class="form-control mono-field" id="receiving-account-no" name="receiving_account_no" maxlength="100">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="receiving-bank-name">Receiving Bank</label>
                                <input class="form-control" id="receiving-bank-name" name="receiving_bank_name" maxlength="255">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="receiving-account-name">Account Name</label>
                                <input class="form-control" id="receiving-account-name" name="receiving_account_name" maxlength="255">
                            </div>
                            <div class="col-md-4 d-none" data-customer-amount-field>
                                <label class="form-label" for="customer-payment-amount">Payment Amount *</label>
                                <input class="form-control text-end" id="customer-payment-amount" data-customer-payment-amount type="number" min="0" step="0.01" value="0">
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-xl-5">
                <section class="transaction-section h-100 payment-execution-section">
                    <header class="section-heading">
                        <h2>Kontrol Eksekusi Treasury</h2>
                        <span class="text-secondary small"><i class="fa-solid fa-lock me-1"></i> Aktif setelah Approved</span>
                    </header>
                    <fieldset class="transaction-body" data-execution-fields disabled>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="payment-means">Payment Means *</label>
                                <select class="form-select" id="payment-means" name="payment_means" data-payment-means>
                                    <option value="">Pilih metode</option>
                                    <option value="CASH">Cash</option>
                                    <option value="BANK TRANSFER">Bank Transfer</option>
                                    <option value="CHECK">Check</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="posting-date">Posting Date *</label>
                                <input class="form-control mono-field" id="posting-date" name="posting_date" type="date" max="{{ now()->format('Y-m-d') }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="payment-coa">COA *</label>
                                <input class="form-control mono-field" id="payment-coa" name="coa" maxlength="50" placeholder="Postable financial account">
                            </div>
                            <div class="col-12" data-bank-fields>
                                <label class="form-label" for="company-bank-account">Bank Account Company *</label>
                                <input class="form-control mono-field" id="company-bank-account" name="company_bank_account" maxlength="100">
                            </div>
                            <div class="col-12 d-none" data-check-fields>
                                <label class="form-label" for="company-check-no">Check No *</label>
                                <input class="form-control mono-field" id="company-check-no" name="check_no" maxlength="100">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Outgoing No</label>
                                <div class="readonly-value mono-field">Belum terbentuk</div>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label">SAP Company DB</label>
                                <div class="readonly-value mono-field">{{ session('sap_company_db') }}</div>
                            </div>
                        </div>
                    </fieldset>
                </section>
            </div>
        </div>

        <section class="transaction-section mt-3">
            <header class="section-heading">
                <h2>Detail Pembayaran</h2>
                <span class="text-secondary small">Satu request hanya boleh memiliki satu currency</span>
            </header>
            <div class="transaction-body pb-0">
                <ul class="nav nav-tabs payment-detail-tabs" role="tablist">
                    <li class="nav-item" role="presentation"><button class="nav-link active" type="button" data-payment-tab="invoice">Invoice</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" type="button" data-payment-tab="account">Account</button></li>
                </ul>
            </div>

            <div data-payment-pane="invoice">
                <div class="transaction-body border-bottom">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label" for="invoice-type">Invoice Type</label>
                            <select class="form-select" id="invoice-type" data-invoice-type>
                                <option value="AP">AP Invoice</option>
                                <option value="APDP">A/P Down Payment</option>
                                <option value="APCN">A/P Credit Memo</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="invoice-source">Open SAP Document</label>
                            <select class="form-select mono-field" id="invoice-source" data-invoice-source>
                                <option value="">Pilih invoice terbuka</option>
                                @foreach($sourceInvoices as $invoice)
                                    @php($data = $invoice->normalized_data ?? [])
                                    <option
                                        value="{{ $invoice->sap_doc_entry }}"
                                        data-document-type="{{ match($invoice->document_type) {'AP_DOWN_PAYMENT' => 'APDP', 'AP_CREDIT_NOTE' => 'APCN', default => 'AP'} }}"
                                        data-document-no="{{ $invoice->sap_doc_num }}"
                                        data-vendor-code="{{ $data['vendor_code'] ?? '' }}"
                                        data-vendor-name="{{ $data['vendor_name'] ?? '' }}"
                                        data-vendor-reference="{{ $data['vendor_reference'] ?? '' }}"
                                        data-currency="{{ $data['currency'] ?? 'IDR' }}"
                                        data-invoice-amount="{{ $data['invoice_amount'] ?? 0 }}"
                                        data-withholding-tax="{{ $data['withholding_tax'] ?? 0 }}"
                                        data-balance="{{ $data['balance'] ?? 0 }}"
                                        data-due-date="{{ $data['due_date'] ?? '' }}"
                                    >{{ $invoice->sap_doc_num ?? $invoice->sap_doc_entry }} — {{ $data['vendor_name'] ?? 'Vendor belum tersinkron' }}</option>
                                @endforeach
                            </select>
                            @if($sourceInvoices->isEmpty())
                                <div class="form-text">Belum ada A/P Invoice terbuka di cache portal.</div>
                            @endif
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="invoice-payment-amount">Payment Amount</label>
                            <input class="form-control text-end" id="invoice-payment-amount" data-invoice-payment-amount type="number" min="0" step="0.01" value="0">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="invoice-cash-discount">Cash Discount</label>
                            <input class="form-control text-end" id="invoice-cash-discount" data-invoice-cash-discount type="number" min="0" step="0.01" value="0">
                        </div>
                        <div class="col-md-2 d-grid">
                            <button class="btn btn-outline-primary" type="button" data-add-invoice-line><i class="fa-solid fa-plus me-1"></i> Add Invoice</button>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-operational payment-detail-table mb-0">
                        <thead><tr><th>#</th><th>Type</th><th>Invoice No</th><th>Vendor Ref</th><th>Currency</th><th class="text-end">Invoice Amount</th><th class="text-end">WTax</th><th class="text-end">Balance</th><th class="text-end">Cash Discount</th><th class="text-end">Payment</th><th></th></tr></thead>
                        <tbody data-invoice-lines><tr data-empty-row><td colspan="11"><div class="empty-state py-4"><i class="fa-regular fa-file-lines"></i>Pilih invoice SAP lalu tambahkan ke request.</div></td></tr></tbody>
                    </table>
                </div>
            </div>

            <div class="d-none" data-payment-pane="account">
                <div class="transaction-body border-bottom">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-2"><label class="form-label" for="account-code">Account No</label><input class="form-control mono-field" id="account-code" data-account-code maxlength="50"></div>
                        <div class="col-md-3"><label class="form-label" for="account-name">Account Name</label><input class="form-control" id="account-name" data-account-name maxlength="255"></div>
                        <div class="col-md-3"><label class="form-label" for="account-remarks">Remarks Detail</label><input class="form-control" id="account-remarks" data-account-remarks maxlength="255"></div>
                        <div class="col-md-2"><label class="form-label" for="account-amount">Payment Amount</label><input class="form-control text-end" id="account-amount" data-account-amount type="number" min="0" step="0.01" value="0"></div>
                        <div class="col-md-2 d-grid"><button class="btn btn-outline-primary" type="button" data-add-account-line><i class="fa-solid fa-plus me-1"></i> Add Account</button></div>
                        <div class="col-md-2"><label class="form-label" for="account-department">Department</label><input class="form-control mono-field" id="account-department" data-account-department maxlength="8"></div>
                        <div class="col-md-2"><label class="form-label" for="account-pool">Lokasi Pool</label><input class="form-control mono-field" id="account-pool" data-account-pool maxlength="8"></div>
                        <div class="col-md-2"><label class="form-label" for="account-vehicle">No Pol</label><input class="form-control mono-field" id="account-vehicle" data-account-vehicle maxlength="8"></div>
                        <div class="col-md-2"><label class="form-label" for="account-project">Project</label><input class="form-control mono-field" id="account-project" data-account-project maxlength="20"></div>
                        <div class="col-md-2"><label class="form-label" for="account-dimension-4">Dimension 4</label><input class="form-control mono-field" id="account-dimension-4" data-account-dimension-4 maxlength="8"></div>
                        <div class="col-md-2"><label class="form-label" for="account-dimension-5">Dimension 5</label><input class="form-control mono-field" id="account-dimension-5" data-account-dimension-5 maxlength="8"></div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-operational payment-detail-table mb-0">
                        <thead><tr><th>#</th><th>Account</th><th>Name</th><th>Department</th><th>Pool</th><th>No Pol</th><th>Project</th><th>Remark</th><th class="text-end">Payment</th><th></th></tr></thead>
                        <tbody data-account-lines><tr data-empty-row><td colspan="10"><div class="empty-state py-4"><i class="fa-solid fa-table-list"></i>Tambahkan baris account untuk pembayaran non-invoice.</div></td></tr></tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="transaction-section mt-3">
            <div class="transaction-body">
                <div class="row g-3">
                    <div class="col-lg-7">
                        <label class="form-label" for="payment-remarks">Remarks *</label>
                        <textarea class="form-control" id="payment-remarks" name="remarks" rows="3" maxlength="1000" required></textarea>
                        <label class="form-label mt-3" for="payment-approval-notes">Notes Approval</label>
                        <textarea class="form-control locked-field" id="payment-approval-notes" rows="2" placeholder="Diisi oleh approver" disabled></textarea>
                    </div>
                    <div class="col-lg-5">
                        <div class="form-check mb-3">
                            <input class="form-check-input" id="payment-on-account" name="payment_on_account" type="checkbox">
                            <label class="form-check-label" for="payment-on-account">Payment on Account</label>
                        </div>
                        <div class="payment-summary">
                            <div><span>Total Payment</span><strong data-total-payment>0.00</strong></div>
                            <div><label for="bank-charge">Bank Charge</label><input class="form-control form-control-sm text-end" id="bank-charge" name="bank_charge" data-bank-charge type="number" min="0" step="0.01" value="0"></div>
                            <div class="grand-total"><span>Grand Total</span><strong data-grand-total>0.00</strong></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="alert alert-success d-none mt-3 mb-0" data-payment-result role="status"></div>
        <div class="sticky-action-bar payment-action-bar">
            <div><i class="fa-solid fa-circle-info text-primary me-1"></i><span class="text-secondary">Submit akan membekukan detail dan mengirim ke approval bertingkat.</span></div>
            <div class="d-flex flex-wrap gap-2 justify-content-end">
                <button class="btn btn-outline-secondary" type="reset">Reset</button>
                <button class="btn btn-outline-primary" type="button" data-save-draft><i class="fa-regular fa-floppy-disk me-1"></i> Validasi Draft</button>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-paper-plane me-1"></i> Submit Approval</button>
                <button class="btn btn-success" type="button" disabled title="Hanya aktif setelah semua approval selesai"><i class="fa-solid fa-building-columns me-1"></i> Execute</button>
            </div>
        </div>
    </form>

@endsection
