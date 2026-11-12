<?php

namespace App\Http\Requests;

use App\Integration\Sap\SapCompanyContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'transaction_uuid' => ['required', 'uuid'],
            // Product Owner decision 2026-11-12: any past date is allowed; it becomes DocDate and TaxDate in SAP.
            'document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'required_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:document_date'],
            'priority' => ['required', Rule::in(['NORMAL', 'URGENT'])],
            'notes' => ['nullable', 'string', 'max:254'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.item_id' => [
                'required',
                'integer',
                Rule::exists('sap_items_cache', 'id')->where(fn ($query) => $query
                    ->where('company_db', app(SapCompanyContext::class)->current())
                    ->where('is_active', true)
                    ->where('is_purchase_item', true)),
            ],
            'lines.*.vehicle_cost_center_id' => [
                'nullable',
                'integer',
                Rule::exists('sap_cost_center_caches', 'id')->where(fn ($query) => $query
                    ->where('company_db', app(SapCompanyContext::class)->current())
                    ->where('dimension', config('sap.dimensions.vehicle'))
                    ->where('is_active', true)),
            ],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.ending_km' => ['required', 'integer', 'min:0'],
            'lines.*.manual_uom' => ['nullable', 'string', 'max:100'],
            'lines.*.free_text' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->user()->operationalProfile->warehouse_code) {
                $validator->errors()->add('lines', 'Warehouse belum dipetakan pada profil user. Hubungi administrator.');
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'document_date.required' => 'Tanggal PR wajib diisi. Muat ulang halaman bila kolom tanggal PR belum tampil.',
            'document_date.before_or_equal' => 'Tanggal PR tidak boleh melewati hari ini.',
            'required_date.after_or_equal' => 'Tanggal kebutuhan tidak boleh lebih awal dari tanggal PR.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'document_date' => 'tanggal PR',
            'required_date' => 'tanggal kebutuhan/purchase',
            'priority' => 'prioritas',
            'lines.*.item_id' => 'item',
            'lines.*.vehicle_cost_center_id' => 'nopol',
            'lines.*.quantity' => 'required qty',
            'lines.*.ending_km' => 'KM akhir',
            'lines.*.manual_uom' => 'UoM manual',
            'lines.*.free_text' => 'free text',
        ];
    }
}
