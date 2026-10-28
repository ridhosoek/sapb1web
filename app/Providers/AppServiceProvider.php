<?php

namespace App\Providers;

use App\Domain\Shared\Enums\UserRole;
use App\Integration\ControlCenter\Contracts\ControlCenterGateway;
use App\Integration\ControlCenter\HttpControlCenterGateway;
use App\Integration\Sap\SapCompanyContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ControlCenterGateway::class, HttpControlCenterGateway::class);
        $this->app->scoped(SapCompanyContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        Paginator::useBootstrapFive();

        Gate::define('view-integration-monitor', fn (User $user): bool => $user->is_active && $user->hasRole(UserRole::SuperAdmin));
        Gate::define('manage-users', fn (User $user): bool => $user->is_active && $user->role->canManageUsers());
        Gate::define('view-system-configuration', fn (User $user): bool => $user->is_active && $user->hasRole(UserRole::SuperAdmin));
        Gate::define('manage-approval-workflows', fn (User $user): bool => $user->is_active && $user->role->canManageApprovalWorkflows());
        Gate::define('approve-goods-issues', fn (User $user): bool => $user->is_active && $user->role->canApproveGoodsIssues());
        Gate::define('view-payment-requests', fn (User $user): bool => $user->is_active && $user->hasRole(UserRole::SuperAdmin));
        Gate::define('approve-payment-requests', fn (User $user): bool => $user->is_active && $user->hasRole(UserRole::SuperAdmin));
    }
}
