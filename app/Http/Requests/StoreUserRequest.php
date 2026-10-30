<?php

namespace App\Http\Requests;

use App\Domain\Shared\Enums\UserRole;
use App\Models\ControlDepartment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
            'role' => ['required', Rule::in(array_map(
                static fn (UserRole $role): string => $role->value,
                $this->user()->role->assignableRoles(),
            ))],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('control_departments', 'id')->where('is_active', true),
            ],
            'warehouse_code' => ['required', 'string', 'max:50'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'department_id.exists' => 'Department yang dipilih tidak aktif atau tidak tersedia.',
            'warehouse_code.required' => 'Warehouse wajib dipilih untuk membatasi akses user.',
            'role.in' => 'Anda tidak diizinkan membuat user dengan role tersebut.',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('department_id')) {
                return;
            }

            $departmentIsAvailable = ControlDepartment::query()
                ->whereKey($this->integer('department_id'))
                ->where('is_active', true)
                ->whereHas('pool', fn ($query) => $query->where('is_active', true))
                ->exists();

            if (! $departmentIsAvailable) {
                $validator->errors()->add('department_id', 'Department tidak memiliki pemetaan operasional yang aktif.');
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
