<?php

namespace App\Http\Requests;

use App\Domain\Shared\Enums\UserRole;
use App\Integration\Sap\SapCompanyContext;
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
            'job_title' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
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
            'department_cost_center_id' => [
                'required',
                'integer',
                Rule::exists('sap_cost_center_caches', 'id')->where(fn ($query) => $query
                    ->where('company_db', app(SapCompanyContext::class)->current())
                    ->where('dimension', config('sap.dimensions.department'))
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
            'department_cost_center_id.exists' => 'Cost center Department tidak aktif atau bukan berasal dari database SAP yang sedang dipilih.',
            'warehouse_code.required' => 'Warehouse wajib dipilih untuk membatasi akses user.',
            'role.in' => 'Anda tidak diizinkan membuat user dengan role tersebut.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'job_title' => trim((string) $this->input('job_title')),
            'warehouse_code' => strtoupper(trim((string) $this->input('warehouse_code'))),
        ]);
    }
}
