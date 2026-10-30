<?php

namespace App\Http\Requests;

use App\Domain\Shared\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreApprovalWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-approval-workflows') === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(['PURCHASE_REQUEST', 'GOODS_ISSUE'])],
            'submitter_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')
                    ->where('role', UserRole::AdminOperational->value)
                    ->where('is_active', true),
            ],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:10'],
            'steps.*.approver_id' => ['required', 'integer', 'distinct:strict', Rule::exists('users', 'id')->where('is_active', true)],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['document_type', 'submitter_id', 'steps'])) {
                return;
            }

            $documentType = $this->string('document_type')->toString();
            $submitter = User::query()
                ->with('operationalProfile')
                ->find($this->integer('submitter_id'));

            if (! $submitter?->operationalProfile?->warehouse_code) {
                $validator->errors()->add('submitter_id', 'User Admin Operasional harus memiliki Warehouse aktif.');

                return;
            }

            $submitterWarehouse = $submitter->operationalProfile->warehouse_code;
            $approvers = User::query()
                ->whereIn('id', collect($this->input('steps', []))->pluck('approver_id'))
                ->with('operationalProfile')
                ->get()
                ->keyBy('id');

            foreach (array_values($this->input('steps', [])) as $index => $step) {
                $approver = $approvers->get((int) ($step['approver_id'] ?? 0));

                if (! $approver) {
                    continue;
                }

                $canApprove = $documentType === 'PURCHASE_REQUEST'
                    ? $approver->role->canApprovePurchaseRequests()
                    : $approver->role->canApproveGoodsIssues();

                if (! $canApprove) {
                    $validator->errors()->add(
                        "steps.{$index}.approver_id",
                        $documentType === 'PURCHASE_REQUEST'
                            ? 'Approver PR harus Manager Operasional/Transport, Manager Admin HO, atau Super Admin.'
                            : 'Approver Goods Issue harus Admin HO atau Super Admin.',
                    );

                    continue;
                }

                if ($approver->role === UserRole::ManagerOperational
                    && strcasecmp((string) $approver->operationalProfile?->warehouse_code, $submitterWarehouse) !== 0) {
                    $validator->errors()->add(
                        "steps.{$index}.approver_id",
                        'Manager Operasional/Transport harus berasal dari Warehouse yang sama dengan user Admin Operasional.',
                    );
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }
}
