<?php

namespace App\Http\Middleware;

use App\Integration\Sap\SapCompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSapCompanySelected
{
    public function __construct(private readonly SapCompanyContext $companies) {}

    public function handle(Request $request, Closure $next): Response
    {
        $companyDb = $request->session()->get('sap_company_db');

        if (! $this->companies->isAllowed($companyDb)) {
            $companyDb = $this->companies->current();
            $request->session()->put('sap_company_db', $companyDb);
        }

        $this->companies->select($companyDb);

        return $next($request);
    }
}
