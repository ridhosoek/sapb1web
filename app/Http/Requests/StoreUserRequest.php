<?php

namespace App\Http\Requests;

use App\Domain\Shared\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

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
            'pool_id' => [
                'required',
                'integer',
                Rule::exists('control_pools', 'id')->where('is_active', true),
            ],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('control_departments', 'id')
                    ->where('pool_id', $this->integer('pool_id'))
                    ->where('is_active', true),
            ],
            'warehouse_code' => ['nullable', 'string', 'max:50'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'department_id.exists' => 'Department aktif harus berasal dari Pool yang dipilih.',
            'pool_id.exists' => 'Pool yang dipilih tidak aktif atau tidak tersedia.',
            'role.in' => 'Anda tidak diizinkan membuat user dengan role tersebut.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
