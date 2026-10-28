<?php

use App\Http\Controllers\Admin\ApprovalNotificationController;
use App\Http\Controllers\Admin\ApprovalWorkflowController;
use App\Http\Controllers\Admin\IntegrationMonitorController;
use App\Http\Controllers\Admin\SystemConfigurationController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\ApprovalDecisionController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Finance\PaymentApprovalController;
use App\Http\Controllers\Finance\PaymentRequestController;
use App\Http\Controllers\Lookup\ItemLookupController;
use App\Http\Controllers\Lookup\VehicleLookupController;
use App\Http\Controllers\Operations\ApprovedDocumentPostController;
use App\Http\Controllers\Operations\DocumentAttachmentController;
use App\Http\Controllers\Operations\DocumentPrintController;
use App\Http\Controllers\Operations\GoodsIssueController;
use App\Http\Controllers\Operations\GoodsReceiptDocumentationController;
use App\Http\Controllers\Operations\PurchaseProgressController;
use App\Http\Controllers\Operations\PurchaseProgressSyncController;
use App\Http\Controllers\Operations\PurchaseRequestController;
use App\Http\Controllers\Operations\ReadyToReceiveController;
use App\Http\Controllers\Operations\SapDocumentDetailController;
use App\Http\Controllers\Operations\SparepartHistoryController;
use App\Http\Controllers\Operations\SparepartReportController;
use App\Http\Controllers\Operations\StockLedgerController;
use App\Http\Middleware\EnsureOperationalProfile;
use App\Http\Middleware\EnsureSapCompanySelected;
use App\Models\SapDocument;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('/approvals/{step}/{token}', [ApprovalDecisionController::class, 'show'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('approvals.show');
Route::post('/approvals/{step}/{token}', [ApprovalDecisionController::class, 'decide'])
    ->middleware(['signed', 'throttle:10,1'])
    ->name('approvals.decide');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', EnsureSapCompanySelected::class, EnsureOperationalProfile::class])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/purchase-progress', PurchaseProgressController::class)->name('purchase-progress.index');
    Route::post('/purchase-progress/{document}/sync', PurchaseProgressSyncController::class)
        ->whereNumber('document')
        ->name('purchase-progress.sync');
    Route::get('/sparepart-expense-report', SparepartReportController::class)->name('sparepart-report.index');
    Route::get('/stock-monitoring', fn () => to_route('sparepart-report.index'))->name('stock-monitoring.index');
    Route::get('/stock-monitoring/{stock}/ledger', StockLedgerController::class)
        ->whereNumber('stock')
        ->name('stock-monitoring.show');
    Route::get('/documents/{document}', SapDocumentDetailController::class)
        ->whereNumber('document')
        ->name('documents.show');
    Route::get('/documents/{document}/print', DocumentPrintController::class)
        ->whereNumber('document')
        ->name('documents.print');
    Route::get('/document-attachments/{attachment}', DocumentAttachmentController::class)
        ->whereNumber('attachment')
        ->name('document-attachments.show');
    Route::post('/documents/{document}/post-approved', ApprovedDocumentPostController::class)
        ->whereNumber('document')
        ->middleware('can:manage-approval-workflows')
        ->name('documents.post-approved');

    Route::get('/purchase-requests', [PurchaseRequestController::class, 'index'])->name('purchase-requests.index');
    Route::get('/ready-to-receive', ReadyToReceiveController::class)->name('ready-to-receive.index');
    Route::get('/ready-to-receive/{purchaseOrder}', [GoodsReceiptDocumentationController::class, 'show'])
        ->whereNumber('purchaseOrder')
        ->name('ready-to-receive.show');
    Route::get('/goods-issues', [GoodsIssueController::class, 'index'])->name('goods-issues.index');

    Route::middleware('can:create,'.SapDocument::class)->group(function () {
        Route::get('/purchase-requests/create', [PurchaseRequestController::class, 'create'])->name('purchase-requests.create');
        Route::post('/purchase-requests', [PurchaseRequestController::class, 'store'])->name('purchase-requests.store');
        Route::get('/purchase-requests/sparepart-history', SparepartHistoryController::class)
            ->middleware('throttle:60,1')
            ->name('purchase-requests.sparepart-history');
        Route::post('/ready-to-receive/{purchaseOrder}/attachments', [GoodsReceiptDocumentationController::class, 'store'])
            ->whereNumber('purchaseOrder')
            ->middleware('throttle:20,1')
            ->name('ready-to-receive.attachments.store');
        Route::post('/ready-to-receive/{purchaseOrder}/receive', [GoodsReceiptDocumentationController::class, 'receive'])
            ->whereNumber('purchaseOrder')
            ->middleware('throttle:10,1')
            ->name('ready-to-receive.receive');
        Route::get('/goods-issues/create', [GoodsIssueController::class, 'create'])->name('goods-issues.create');
        Route::post('/goods-issues', [GoodsIssueController::class, 'store'])->name('goods-issues.store');
    });

    Route::get('/admin/integration-monitor', IntegrationMonitorController::class)
        ->middleware('can:view-integration-monitor')
        ->name('admin.integration-monitor');
    Route::resource('/admin/users', UserManagementController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->middleware('can:manage-users')
        ->names('admin.users');
    Route::resource('/admin/approval-workflows', ApprovalWorkflowController::class)
        ->only(['index', 'create', 'store', 'edit', 'update'])
        ->middleware('can:manage-approval-workflows')
        ->names('admin.approval-workflows');
    Route::post('/admin/approval-steps/{step}/email', ApprovalNotificationController::class)
        ->whereNumber('step')
        ->middleware('can:manage-approval-workflows')
        ->name('admin.approval-steps.email');

    Route::controller(PaymentRequestController::class)
        ->middleware('can:view-payment-requests')
        ->group(function () {
            Route::get('/payment-requests', 'index')->name('payment-requests.index');
            Route::get('/payment-requests/create', 'create')->name('payment-requests.create');
        });
    Route::get('/payment-approvals', PaymentApprovalController::class)
        ->middleware('can:approve-payment-requests')
        ->name('payment-approvals.index');
    Route::get('/admin/configuration', SystemConfigurationController::class)
        ->middleware('can:view-system-configuration')
        ->name('admin.configuration');

    Route::get('/lookups/vehicles', VehicleLookupController::class)->name('lookups.vehicles');
    Route::get('/lookups/items', ItemLookupController::class)->name('lookups.items');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
