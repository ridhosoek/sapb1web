<?php

namespace App\Http\Requests;

use App\Domain\Shared\Enums\UserRole;
use App\Http\Requests\Concerns\ValidatesOperationalMapping;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    use ValidatesOperationalMapping;

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
            ...$this->operationalMappingRules(),
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->operationalMappingMessages(),
            'role.in' => 'Anda tidak diizinkan membuat user dengan role tersebut.',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateOperationalMapping($validator)];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareOperationalMappingInput();
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'job_title' => trim((string) $this->input('job_title')),
        ]);
    }
}
