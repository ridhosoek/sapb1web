<?php

namespace App\Http\Requests;

use App\Domain\Shared\Enums\UserRole;
use App\Http\Requests\Concerns\ValidatesOperationalMapping;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    use ValidatesOperationalMapping;

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
            'job_title' => ['required', 'string', 'max:150'],
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
            ...$this->operationalMappingRules(),
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->operationalMappingMessages(),
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

            $this->validateOperationalMapping($validator);

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
        $this->prepareOperationalMappingInput();
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'job_title' => trim((string) $this->input('job_title')),
        ]);
    }
}
