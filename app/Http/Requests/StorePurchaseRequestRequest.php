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
            'vehicle_cost_center_id' => [
                'required',
                'integer',
                Rule::exists('sap_cost_center_caches', 'id')->where(fn ($query) => $query
                    ->where('company_db', app(SapCompanyContext::class)->current())
                    ->where('dimension', config('sap.dimensions.vehicle'))
                    ->where('is_active', true)),
            ],
            'required_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'priority' => ['required', Rule::in(['NORMAL', 'URGENT'])],
            'ending_km' => ['required', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:254'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.item_id' => [
                'required',
                'integer',
                'distinct:strict',
                Rule::exists('sap_items_cache', 'id')->where(fn ($query) => $query
                    ->where('company_db', app(SapCompanyContext::class)->current())
                    ->where('is_active', true)
                    ->where('is_purchase_item', true)),
            ],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
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
    public function attributes(): array
    {
        return [
            'vehicle_cost_center_id' => 'nopol',
            'required_date' => 'tanggal kebutuhan/purchase',
            'priority' => 'prioritas',
            'ending_km' => 'KM akhir',
            'lines.*.item_id' => 'item',
            'lines.*.quantity' => 'required qty',
            'lines.*.free_text' => 'free text',
        ];
    }
}
