<?php

namespace App\Http\Middleware;

use App\Application\Users\OperationalMapping;
use App\Integration\Sap\SapCompanyContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOperationalProfile
{
    public function __construct(
        private readonly SapCompanyContext $companies,
        private readonly OperationalMapping $operationalMapping,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->is_active, 403, 'Akun Anda tidak aktif.');

        $companyDb = $this->companies->current();
        $profile = $user->operationalProfile()->with('pool', 'department')->first();
        $reason = $this->operationalMapping->incompleteReason($profile, $companyDb);

        if ($reason !== null && ! $this->mayWorkWithoutMapping($request, $user)) {
            abort(403, "{$reason} Hubungi Admin HO untuk melengkapi pemetaan Anda sebelum memakai database ini.");
        }

        $user->setRelation('operationalProfile', $profile);

        return $next($request);
    }

    /**
     * Admin HO must still be able to open user management to map a company they have no profile for, and every user
     * can sign out.
     */
    private function mayWorkWithoutMapping(Request $request, User $user): bool
    {
        return $request->routeIs('logout')
            || ($request->routeIs('admin.users.*') && $user->can('manage-users'));
    }
}
