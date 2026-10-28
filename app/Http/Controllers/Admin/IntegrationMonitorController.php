<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Integration\Sap\SapCompanyContext;
use App\Models\SapApiLog;
use App\Models\SapWatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class IntegrationMonitorController extends Controller
{
    public function __invoke(SapCompanyContext $companies): View
    {
        $companyDb = $companies->current();
        $logs = SapApiLog::query()->forCompany($companyDb);

        return view('admin.integration-monitor.index', [
            'metrics' => [
                'last_success' => (clone $logs)->where('result', 'SUCCESS')->latest('created_at')->first()?->created_at,
                'pending_jobs' => DB::table('jobs')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
                'active_watchers' => SapWatcher::query()
                    ->whereHas('document', fn ($query) => $query->forCompany($companyDb))
                    ->where('watch_status', 'ACTIVE')
                    ->count(),
                'sap_errors_today' => (clone $logs)->where('result', 'ERROR')->whereDate('created_at', today())->count(),
                'average_response_ms' => (int) round((float) (clone $logs)->whereNotNull('duration_ms')->avg('duration_ms')),
            ],
            'logs' => (clone $logs)->latest('created_at')->limit(30)->get(),
            'watchers' => SapWatcher::query()
                ->with('document')
                ->whereHas('document', fn ($query) => $query->forCompany($companyDb))
                ->latest('updated_at')
                ->limit(10)
                ->get(),
        ]);
    }
}
