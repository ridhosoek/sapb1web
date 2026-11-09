<?php

namespace App\Http\Controllers\Admin;

use App\Application\Users\CompanyWarehouseDirectory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Integration\Sap\SapCompanyContext;
use App\Models\AuditLog;
use App\Models\ControlDepartment;
use App\Models\ControlPool;
use App\Models\SapCostCenterCache;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class UserManagementController extends Controller
{
    public function index(Request $request, SapCompanyContext $companies): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:ACTIVE,INACTIVE'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        return view('admin.users.index', [
            'users' => User::query()
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('job_title', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('operationalProfile', fn ($profile) => $profile->where('warehouse_code', 'like', "%{$search}%"))
                        ->orWhereHas('operationalProfile.pool', fn ($pool) => $pool->where('code', 'like', "%{$search}%"))
                        ->orWhereHas('operationalProfile.department', fn ($department) => $department->where('code', 'like', "%{$search}%"));
                }))
                ->when(($filters['status'] ?? null) === 'ACTIVE', fn ($query) => $query->where('is_active', true))
                ->when(($filters['status'] ?? null) === 'INACTIVE', fn ($query) => $query->where('is_active', false))
                ->with('operationalProfile.pool', 'operationalProfile.department')
                ->latest()
                ->paginate(15)
                ->withQueryString(),
            'filters' => $filters,
            'companyDb' => $companies->current(),
        ]);
    }

    public function create(Request $request, SapCompanyContext $companies): View
    {
        $companyDb = $this->selectedCompany($request, $companies);

        return view('admin.users.create', [
            'companies' => $companies->available(),
            'companyDb' => $companyDb,
            'poolCostCenters' => $this->poolCostCenters($companyDb),
            'departments' => $this->departmentsFor($companyDb),
            'warehouseCodes' => $this->warehouseSuggestions($companyDb),
            'roles' => $request->user()->role->assignableRoles(),
        ]);
    }

    public function show(User $user, SapCompanyContext $companies): View
    {
        $user->load('operationalProfiles.pool', 'operationalProfiles.department');

        return view('admin.users.show', [
            'registeredUser' => $user,
            'companies' => $companies->available(),
            'currentProfile' => $user->operationalProfiles->firstWhere('company_db', $companies->current()),
            'legacyProfile' => $user->operationalProfiles->first(fn (UserOperationalProfile $profile): bool => $profile->company_db === null),
        ]);
    }

    public function edit(Request $request, User $user, SapCompanyContext $companies): View
    {
        abort_unless(in_array($user->role, $request->user()->role->assignableRoles(), true), 403);
        $companyDb = $this->selectedCompany($request, $companies);
        $user->load('operationalProfiles.pool', 'operationalProfiles.department');
        $profile = $user->operationalProfiles->firstWhere('company_db', $companyDb);

        return view('admin.users.edit', [
            'registeredUser' => $user,
            'companies' => $companies->available(),
            'companyDb' => $companyDb,
            'profile' => $profile,
            'legacyProfile' => $user->operationalProfiles->first(fn (UserOperationalProfile $legacy): bool => $legacy->company_db === null),
            'poolCostCenters' => $this->poolCostCenters($companyDb),
            'selectedPoolCostCenterId' => SapCostCenterCache::query()
                ->forCompany($companyDb)
                ->where('dimension', config('sap.dimensions.pool'))
                ->where('center_code', $profile?->pool?->code)
                ->value('id'),
            'departments' => $this->departmentsFor($companyDb),
            'warehouseCodes' => $this->warehouseSuggestions($companyDb),
            'roles' => $request->user()->role->assignableRoles(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $companyDb = $validated['company_db'];
        $poolCostCenter = $this->activePoolCostCenter($companyDb, (int) $validated['pool_cost_center_id']);

        DB::transaction(function () use ($companyDb, $poolCostCenter, $validated): void {
            $pool = $this->synchronizePool($poolCostCenter);
            $user = User::query()->create([
                'name' => $validated['name'],
                'job_title' => $validated['job_title'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => $validated['role'],
                'is_active' => $validated['is_active'],
            ]);

            UserOperationalProfile::query()->create([
                'user_id' => $user->id,
                'company_db' => $companyDb,
                'pool_id' => $pool->id,
                'department_id' => $validated['department_id'],
                'warehouse_code' => trim($validated['warehouse_code']),
            ]);
        });

        return redirect()
            ->route('admin.users.index')
            ->with('status', "User operasional berhasil didaftarkan. Pemetaan Pool, Department, dan Warehouse hanya berlaku untuk database {$companyDb}.");
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();
        $companyDb = $validated['company_db'];
        $poolCostCenter = $this->activePoolCostCenter($companyDb, (int) $validated['pool_cost_center_id']);

        DB::transaction(function () use ($companyDb, $poolCostCenter, $request, $user, $validated): void {
            $pool = $this->synchronizePool($poolCostCenter);
            $profile = $user->operationalProfiles()->forCompany($companyDb)->with('pool', 'department')->first();
            $before = [
                'name' => $user->name,
                'job_title' => $user->job_title,
                'email' => $user->email,
                'role' => $user->role->value,
                'is_active' => $user->is_active,
                'company_db' => $companyDb,
                'pool' => $profile?->pool?->code,
                'department' => $profile?->department?->code,
                'warehouse_code' => $profile?->warehouse_code,
            ];
            $user->fill([
                'name' => $validated['name'],
                'job_title' => $validated['job_title'],
                'email' => $validated['email'],
                'role' => $validated['role'],
                'is_active' => $validated['is_active'],
            ]);

            if (filled($validated['password'] ?? null)) {
                $user->password = $validated['password'];
                $user->setRememberToken(Str::random(60));
            }

            $user->save();
            // Only the row of the edited company is written; the mapping of every other company stays untouched.
            $profile = UserOperationalProfile::query()->updateOrCreate(
                ['user_id' => $user->id, 'company_db' => $companyDb],
                [
                    'pool_id' => $pool->id,
                    'department_id' => $validated['department_id'],
                    'warehouse_code' => trim($validated['warehouse_code']),
                ],
            );
            $profile->load('department');
            AuditLog::query()->create([
                'actor_id' => $request->user()->id,
                'event' => 'user.updated',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'metadata' => [
                    'before' => $before,
                    'after' => [
                        'name' => $user->name,
                        'job_title' => $user->job_title,
                        'email' => $user->email,
                        'role' => $user->role->value,
                        'is_active' => $user->is_active,
                        'company_db' => $companyDb,
                        'pool' => $pool->code,
                        'department' => $profile->department?->code,
                        'warehouse_code' => $profile->warehouse_code,
                    ],
                    'password_changed' => filled($validated['password'] ?? null),
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', "Data user berhasil diperbarui. Pemetaan operasional yang disimpan hanya untuk database {$companyDb}.");
    }

    private function selectedCompany(Request $request, SapCompanyContext $companies): string
    {
        $requested = (string) $request->query('company_db', '');

        return $companies->isAllowed($requested) ? $requested : $companies->current();
    }

    /** @return Collection<int, SapCostCenterCache> */
    private function poolCostCenters(string $companyDb): Collection
    {
        return SapCostCenterCache::query()
            ->forCompany($companyDb)
            ->where('dimension', config('sap.dimensions.pool'))
            ->where('is_active', true)
            ->orderBy('center_code')
            ->get(['id', 'center_code', 'center_name']);
    }

    /**
     * Departments whose code is an active Department cost center in the company being edited. A department without an
     * exact code match is not offered: the mapping is never guessed from a similar name.
     *
     * @return Collection<int, ControlDepartment>
     */
    private function departmentsFor(string $companyDb): Collection
    {
        $activeCodes = SapCostCenterCache::query()
            ->forCompany($companyDb)
            ->where('dimension', config('sap.dimensions.department'))
            ->where('is_active', true)
            ->pluck('center_code')
            ->map(fn (string $code): string => mb_strtolower(trim($code)))
            ->flip();

        return ControlDepartment::query()
            ->where('is_active', true)
            ->whereHas('pool', fn ($query) => $query->where('is_active', true))
            ->orderBy('code')
            ->get()
            ->filter(fn (ControlDepartment $department): bool => $activeCodes->has(mb_strtolower(trim($department->code))))
            ->values();
    }

    /** @return list<string> */
    private function warehouseSuggestions(string $companyDb): array
    {
        try {
            return app(CompanyWarehouseDirectory::class)->activeCodes($companyDb);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    private function activePoolCostCenter(string $companyDb, int $id): SapCostCenterCache
    {
        return SapCostCenterCache::query()
            ->forCompany($companyDb)
            ->where('dimension', config('sap.dimensions.pool'))
            ->where('is_active', true)
            ->findOrFail($id);
    }

    private function synchronizePool(SapCostCenterCache $poolCostCenter): ControlPool
    {
        // The pool row is shared by every company. An existing row keeps its name so that editing the mapping of one
        // company cannot rename the pool another company already uses; the company's own name stays in its cost center.
        $pool = ControlPool::query()->firstOrCreate(
            ['code' => $poolCostCenter->center_code],
            [
                'name' => $poolCostCenter->center_name ?: $poolCostCenter->center_code,
                'is_active' => true,
                'synced_at' => $poolCostCenter->synced_at,
            ],
        );

        if (! $pool->is_active) {
            $pool->update(['is_active' => true]);
        }

        return $pool;
    }
}
