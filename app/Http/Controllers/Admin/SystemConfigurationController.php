<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\ControlVehicle;
use App\Models\SapApiLog;
use App\Models\SapCostCenterCache;
use App\Models\SapItemCache;
use App\Models\SapItemWarehouseStock;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SystemConfigurationController extends Controller
{
    public function __invoke(SapCompanyContext $companies): View
    {
        $baseUrl = (string) config('sap.base_url');
        $safeServiceLayerUrl = Str::of($baseUrl)->replaceMatches('/:\/\/[^\/@]+@/', '://')->toString();

        $companyStatus = collect($companies->available())->map(function (string $companyDb): array {
            return [
                'company_db' => $companyDb,
                'items' => SapItemCache::query()->forCompany($companyDb)->where('is_active', true)->count(),
                'purchase_items' => SapItemCache::query()->forCompany($companyDb)->where('is_active', true)->where('is_purchase_item', true)->count(),
                'warehouse_stocks' => SapItemWarehouseStock::query()->forCompany($companyDb)->count(),
                'cost_centers' => [
                    1 => SapCostCenterCache::query()->forCompany($companyDb)->where('dimension', 1)->where('is_active', true)->count(),
                    2 => SapCostCenterCache::query()->forCompany($companyDb)->where('dimension', 2)->where('is_active', true)->count(),
                    3 => SapCostCenterCache::query()->forCompany($companyDb)->where('dimension', 3)->where('is_active', true)->count(),
                ],
                'last_item_sync' => SapItemCache::query()->forCompany($companyDb)->max('synced_at'),
                'last_stock_sync' => SapItemWarehouseStock::query()->forCompany($companyDb)->max('synced_at'),
                'last_cost_center_sync' => SapCostCenterCache::query()->forCompany($companyDb)->max('synced_at'),
                'last_api_success' => SapApiLog::query()->forCompany($companyDb)->where('result', 'SUCCESS')->max('created_at'),
            ];
        });

        return view('admin.configuration.index', [
            'currentCompanyDb' => $companies->current(),
            'companyStatus' => $companyStatus,
            'sapConnection' => [
                'url' => $safeServiceLayerUrl,
                'verify_tls' => (bool) config('sap.verify_tls'),
                'connect_timeout' => (int) config('sap.connect_timeout_seconds'),
                'request_timeout' => (int) config('sap.timeout_seconds'),
                'session_store' => (string) config('sap.session.cache_store'),
                'session_ttl' => (int) config('sap.session.ttl_seconds'),
                'metadata_ttl' => (int) config('sap.metadata.ttl_seconds'),
            ],
            'runtime' => [
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'queue' => (string) config('queue.default'),
                'cache' => (string) config('cache.default'),
                'session' => (string) config('session.driver'),
            ],
            'mail' => [
                'mailer' => (string) config('mail.default'),
                'from_address' => (string) config('mail.from.address'),
                'from_name' => (string) config('mail.from.name'),
                'smtp_configured' => config('mail.default') === 'smtp'
                    && filled(config('mail.mailers.smtp.host'))
                    && filled(config('mail.mailers.smtp.username')),
                'approval_link_expiration_hours' => (int) config('approval.link_expiration_hours', 48),
            ],
            'fieldMappings' => collect(config('sap.fields', []))->filter(fn (mixed $value): bool => filled($value)),
            'dimensions' => config('sap.dimensions', []),
            'stockConfiguration' => [
                'low_threshold' => (float) config('sap.stock.low_threshold', 5),
                'configured_warehouses' => config('sap.stock.warehouses', []),
            ],
            'controlCenter' => [
                'url_configured' => filled(config('control_center.base_url')),
                'token_configured' => filled(config('control_center.token')),
                'pools' => ControlPool::query()->where('is_active', true)->count(),
                'departments' => ControlDepartment::query()->where('is_active', true)->count(),
                'vehicles' => ControlVehicle::query()->where('is_active', true)->count(),
                'users' => User::query()->where('is_active', true)->count(),
            ],
        ]);
    }
}
