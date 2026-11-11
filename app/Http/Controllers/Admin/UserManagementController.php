<?php

namespace App\Http\Controllers\Admin;

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

class UserManagementController extends Controller
{
    public function index(Request $request): View
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
        ]);
    }

    public function create(Request $request, SapCompanyContext $companies): View
    {
        return view('admin.users.create', [
            'poolCostCenters' => $this->poolCostCenters($companies),
            'departmentCostCenters' => $this->departmentCostCenters($companies),
            'roles' => $request->user()->role->assignableRoles(),
        ]);
    }

    public function show(User $user): View
    {
        return view('admin.users.show', [
            'registeredUser' => $user->load('operationalProfile.pool', 'operationalProfile.department'),
        ]);
    }

    public function edit(Request $request, User $user, SapCompanyContext $companies): View
    {
        abort_unless(in_array($user->role, $request->user()->role->assignableRoles(), true), 403);
        $user->load('operationalProfile.pool', 'operationalProfile.department');

        return view('admin.users.edit', [
            'registeredUser' => $user,
            'poolCostCenters' => $this->poolCostCenters($companies),
            'selectedPoolCostCenterId' => SapCostCenterCache::query()
                ->forCompany($companies->current())
                ->where('dimension', config('sap.dimensions.pool'))
                ->where('center_code', $user->operationalProfile?->pool?->code)
                ->value('id'),
            'departmentCostCenters' => $this->departmentCostCenters($companies),
            'selectedDepartmentCostCenterId' => SapCostCenterCache::query()
                ->forCompany($companies->current())
                ->where('dimension', config('sap.dimensions.department'))
                ->where('is_active', true)
                ->where('center_code', $user->operationalProfile?->department?->code)
                ->value('id'),
            'roles' => $request->user()->role->assignableRoles(),
        ]);
    }

    public function store(StoreUserRequest $request, SapCompanyContext $companies): RedirectResponse
    {
        $validated = $request->validated();
        $poolCostCenter = $this->activePoolCostCenter($companies, (int) $validated['pool_cost_center_id']);
        $departmentCostCenter = $this->activeDepartmentCostCenter($companies, (int) $validated['department_cost_center_id']);

        DB::transaction(function () use ($poolCostCenter, $departmentCostCenter, $validated): void {
            $pool = $this->synchronizePool($poolCostCenter);
            $department = $this->synchronizeDepartment($departmentCostCenter);
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
                'pool_id' => $pool->id,
                'department_id' => $department->id,
                'warehouse_code' => trim($validated['warehouse_code']),
            ]);
        });

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'User operasional berhasil didaftarkan.');
    }

    public function update(UpdateUserRequest $request, User $user, SapCompanyContext $companies): RedirectResponse
    {
        $validated = $request->validated();
        $poolCostCenter = $this->activePoolCostCenter($companies, (int) $validated['pool_cost_center_id']);
        $departmentCostCenter = filled($validated['department_cost_center_id'] ?? null)
            ? $this->activeDepartmentCostCenter($companies, (int) $validated['department_cost_center_id'])
            : null;

        DB::transaction(function () use ($poolCostCenter, $departmentCostCenter, $request, $user, $validated): void {
            $pool = $this->synchronizePool($poolCostCenter);
            $department = $departmentCostCenter ? $this->synchronizeDepartment($departmentCostCenter) : null;
            $profile = $user->operationalProfile()->with('pool', 'department')->firstOrFail();
            $before = [
                'name' => $user->name,
                'job_title' => $user->job_title,
                'email' => $user->email,
                'role' => $user->role->value,
                'is_active' => $user->is_active,
                'pool' => $profile->pool?->code,
                'department' => $profile->department?->code,
                'warehouse_code' => $profile->warehouse_code,
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
            $profile->update([
                'pool_id' => $pool->id,
                'department_id' => $department?->id ?? $profile->department_id,
                'warehouse_code' => trim($validated['warehouse_code']),
            ]);
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
                        'pool' => $pool->code,
                        'department' => $department?->code ?? $profile->department?->code,
                        'warehouse_code' => $profile->warehouse_code,
                    ],
                    'password_changed' => filled($validated['password'] ?? null),
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()
            ->route('admin.users.show', $user)
            ->with('status', 'Data user berhasil diperbarui.');
    }

    /** @return Collection<int, SapCostCenterCache> */
    private function poolCostCenters(SapCompanyContext $companies): Collection
    {
        return SapCostCenterCache::query()
            ->forCompany($companies->current())
            ->where('dimension', config('sap.dimensions.pool'))
            ->where('is_active', true)
            ->orderBy('center_code')
            ->get(['id', 'center_code', 'center_name']);
    }

    /** @return Collection<int, SapCostCenterCache> */
    private function departmentCostCenters(SapCompanyContext $companies): Collection
    {
        return SapCostCenterCache::query()
            ->forCompany($companies->current())
            ->where('dimension', config('sap.dimensions.department'))
            ->where('is_active', true)
            ->orderBy('center_code')
            ->get(['id', 'center_code', 'center_name']);
    }

    private function activePoolCostCenter(SapCompanyContext $companies, int $id): SapCostCenterCache
    {
        return SapCostCenterCache::query()
            ->forCompany($companies->current())
            ->where('dimension', config('sap.dimensions.pool'))
            ->where('is_active', true)
            ->findOrFail($id);
    }

    private function activeDepartmentCostCenter(SapCompanyContext $companies, int $id): SapCostCenterCache
    {
        return SapCostCenterCache::query()
            ->forCompany($companies->current())
            ->where('dimension', config('sap.dimensions.department'))
            ->where('is_active', true)
            ->findOrFail($id);
    }

    private function synchronizePool(SapCostCenterCache $poolCostCenter): ControlPool
    {
        return ControlPool::query()->updateOrCreate(
            ['code' => $poolCostCenter->center_code],
            [
                'name' => $poolCostCenter->center_name ?: $poolCostCenter->center_code,
                'is_active' => true,
                'synced_at' => $poolCostCenter->synced_at,
            ],
        );
    }

    private function synchronizeDepartment(SapCostCenterCache $departmentCostCenter): ControlDepartment
    {
        $department = ControlDepartment::query()
            ->where('code', $departmentCostCenter->center_code)
            ->where('is_active', true)
            ->first();

        return $department ?? ControlDepartment::query()->create([
            'pool_id' => null,
            'code' => $departmentCostCenter->center_code,
            'name' => $departmentCostCenter->center_name ?: $departmentCostCenter->center_code,
            'is_active' => true,
            'synced_at' => $departmentCostCenter->synced_at,
        ]);
    }
}
