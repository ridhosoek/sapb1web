<?php

namespace App\Http\Requests;

use App\Domain\Shared\Enums\UserRole;
use App\Integration\Sap\SapCompanyContext;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $targetUser = $this->route('user');

        return $actor instanceof User
            && $targetUser instanceof User
            && $actor->can('manage-users')
            && in_array($targetUser->role, $actor->role->assignableRoles(), true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $targetUser = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($targetUser),
            ],
            'password' => ['nullable', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
            'role' => ['required', Rule::in(array_map(
                static fn (UserRole $role): string => $role->value,
                $this->user()->role->assignableRoles(),
            ))],
            'pool_cost_center_id' => [
                'required',
                'integer',
                Rule::exists('sap_cost_center_caches', 'id')->where(fn ($query) => $query
                    ->where('company_db', app(SapCompanyContext::class)->current())
                    ->where('dimension', config('sap.dimensions.pool'))
                    ->where('is_active', true)),
            ],
            'warehouse_code' => ['required', 'string', 'max:50'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pool_cost_center_id.exists' => 'Cost center Pool tidak aktif atau bukan berasal dari database SAP yang sedang dipilih.',
            'warehouse_code.required' => 'Warehouse wajib dipilih untuk membatasi akses user.',
            'role.in' => 'Anda tidak diizinkan menetapkan role tersebut.',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $targetUser = $this->route('user');

            if (! $targetUser instanceof User) {
                return;
            }

            if ($targetUser->is($this->user()) && ! $this->boolean('is_active')) {
                $validator->errors()->add('is_active', 'Anda tidak dapat menonaktifkan akun sendiri.');
            }

            $newRole = UserRole::tryFrom((string) $this->input('role'));

            if ($targetUser->is($this->user()) && $newRole !== $targetUser->role) {
                $validator->errors()->add('role', 'Anda tidak dapat mengubah role akun sendiri.');
            }

            $removesActiveSuperAdmin = $targetUser->is_active
                && $targetUser->role === UserRole::SuperAdmin
                && (! $this->boolean('is_active') || $newRole !== UserRole::SuperAdmin);

            if ($removesActiveSuperAdmin && User::query()->where('role', UserRole::SuperAdmin->value)->where('is_active', true)->count() <= 1) {
                $field = $this->boolean('is_active') ? 'role' : 'is_active';
                $validator->errors()->add($field, 'Super Admin aktif terakhir tidak dapat dinonaktifkan atau diganti rolenya.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'warehouse_code' => strtoupper(trim((string) $this->input('warehouse_code'))),
        ]);
    }
}
