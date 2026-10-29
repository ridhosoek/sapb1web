<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Models\ControlPool;
use App\Models\User;
use App\Models\UserOperationalProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('operationalProfile.pool', fn ($pool) => $pool->where('code', 'like', "%{$search}%"))
                        ->orWhereHas('operationalProfile.department', fn ($department) => $department->where('code', 'like', "%{$search}%"));
                }))
                ->when(($filters['status'] ?? null) === 'ACTIVE', fn ($query) => $query->where('is_active', true))
                ->when(($filters['status'] ?? null) === 'INACTIVE', fn ($query) => $query->where('is_active', false))
                ->with(['operationalProfile.pool', 'operationalProfile.department'])
                ->latest()
                ->paginate(15)
                ->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.users.create', [
            'pools' => ControlPool::query()
                ->where('is_active', true)
                ->with(['departments' => fn ($query) => $query->where('is_active', true)->orderBy('code')])
                ->orderBy('code')
                ->get(),
            'roles' => $request->user()->role->assignableRoles(),
        ]);
    }

    public function show(User $user): View
    {
        return view('admin.users.show', [
            'registeredUser' => $user->load(['operationalProfile.pool', 'operationalProfile.department']),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated): void {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => $validated['role'],
                'is_active' => $validated['is_active'],
            ]);

            UserOperationalProfile::query()->create([
                'user_id' => $user->id,
                'pool_id' => $validated['pool_id'],
                'department_id' => $validated['department_id'],
                'warehouse_code' => $validated['warehouse_code'] ?? null,
            ]);
        });

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'User operasional berhasil didaftarkan.');
    }
}
