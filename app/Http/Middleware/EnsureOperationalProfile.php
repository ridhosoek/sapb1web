<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOperationalProfile
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->is_active, 403, 'Akun Anda tidak aktif.');
        abort_unless(
            $user->operationalProfile()->whereHas('pool', fn ($query) => $query->where('is_active', true))
                ->whereHas('department', fn ($query) => $query->where('is_active', true))
                ->whereNotNull('warehouse_code')
                ->where('warehouse_code', '!=', '')
                ->exists(),
            403,
            'Profil operasional Department dan Warehouse belum lengkap.',
        );

        $user->loadMissing('operationalProfile.pool', 'operationalProfile.department');

        return $next($request);
    }
}
