<?php

namespace App\Http\Requests\Concerns;

use App\Application\Users\CompanyWarehouseDirectory;
use App\Integration\Sap\SapCompanyContext;
use App\Models\ControlDepartment;
use App\Models\SapCostCenterCache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Shared validation of the per user per CompanyDB mapping (TD-09): Pool, Department and Warehouse are checked against
 * the company being edited, never against the company Admin HO happens to be logged into.
 */
trait ValidatesOperationalMapping
{
    /** @return array<string, array<int, mixed>> */
    protected function operationalMappingRules(): array
    {
        $companyDb = (string) $this->input('company_db');

        return [
            'company_db' => ['required', 'string', Rule::in(app(SapCompanyContext::class)->available())],
            'pool_cost_center_id' => [
                'required',
                'integer',
                Rule::exists('sap_cost_center_caches', 'id')->where(fn ($query) => $query
                    ->where('company_db', $companyDb)
                    ->where('dimension', config('sap.dimensions.pool'))
                    ->where('is_active', true)),
            ],
            'department_id' => [
                'required',
                'integer',
                Rule::exists('control_departments', 'id')->where('is_active', true),
            ],
            'warehouse_code' => ['required', 'string', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    protected function operationalMappingMessages(): array
    {
        return [
            'company_db.in' => 'Database SAP yang dipilih tidak diizinkan.',
            'pool_cost_center_id.exists' => 'Cost center Pool tidak aktif atau bukan dimensi Pool pada database SAP yang dipilih.',
            'department_id.exists' => 'Department yang dipilih tidak aktif atau tidak tersedia.',
            'warehouse_code.required' => 'Warehouse wajib dipilih untuk membatasi akses user.',
        ];
    }

    protected function prepareOperationalMappingInput(): void
    {
        $this->merge([
            'company_db' => trim((string) ($this->input('company_db') ?: app(SapCompanyContext::class)->current())),
            'warehouse_code' => strtoupper(trim((string) $this->input('warehouse_code'))),
        ]);
    }

    protected function validateOperationalMapping(Validator $validator): void
    {
        if ($validator->errors()->has('company_db')) {
            return;
        }

        $companyDb = (string) $this->input('company_db');

        if (! $validator->errors()->has('department_id')) {
            $this->validateDepartment($validator, $companyDb);
        }

        if (! $validator->errors()->has('warehouse_code')) {
            $this->validateWarehouse($validator, $companyDb);
        }
    }

    private function validateDepartment(Validator $validator, string $companyDb): void
    {
        $department = ControlDepartment::query()
            ->whereKey($this->integer('department_id'))
            ->where('is_active', true)
            ->whereHas('pool', fn ($query) => $query->where('is_active', true))
            ->first();

        if (! $department) {
            $validator->errors()->add('department_id', 'Department tidak memiliki pemetaan operasional yang aktif.');

            return;
        }

        $departmentCode = mb_strtolower(trim($department->code));
        $hasActiveCostCenter = SapCostCenterCache::query()
            ->forCompany($companyDb)
            ->where('dimension', config('sap.dimensions.department'))
            ->where('is_active', true)
            ->pluck('center_code')
            ->contains(fn (string $code): bool => mb_strtolower(trim($code)) === $departmentCode);

        if (! $hasActiveCostCenter) {
            $validator->errors()->add('department_id', "Department {$department->code} tidak memiliki cost center aktif dimensi Department pada database SAP {$companyDb}.");
        }
    }

    private function validateWarehouse(Validator $validator, string $companyDb): void
    {
        // Checked on every save, also when the warehouse did not change: a warehouse that SAP deactivated since the
        // last save must not be kept silently.
        $warehouseCode = (string) $this->input('warehouse_code');

        try {
            $isActive = app(CompanyWarehouseDirectory::class)->isActive($companyDb, $warehouseCode);
        } catch (Throwable $exception) {
            report($exception);
            $validator->errors()->add('warehouse_code', "Daftar warehouse aktif database SAP {$companyDb} tidak dapat dibaca saat ini, sehingga pemetaan belum disimpan. Coba lagi.");

            return;
        }

        if (! $isActive) {
            $validator->errors()->add('warehouse_code', "Warehouse {$warehouseCode} tidak aktif atau tidak ada pada database SAP {$companyDb}.");
        }
    }
}
