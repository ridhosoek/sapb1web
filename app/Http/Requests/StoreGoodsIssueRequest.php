<?php

namespace App\Http\Requests;

use App\Integration\Sap\SapCompanyContext;
use App\Models\IssueType;
use App\Models\SapItemWarehouseStock;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreGoodsIssueRequest extends FormRequest
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
            'fpk_number' => ['required', 'string', 'max:11'],
            'fpk_date' => ['required', 'date_format:Y-m-d', 'date_equals:today'],
            'complaint' => ['required', 'string', 'max:1000'],
            'damage_analysis' => ['required', 'string', 'max:1000'],
            'attachments' => ['nullable', 'array', 'max:'.config('sap.documents.goods_issue_attachments.max_files_per_upload', 3)],
            'attachments.*' => [
                'required',
                File::types(['jpg', 'jpeg', 'png'])
                    ->max((int) config('sap.documents.goods_issue_attachments.max_size_kb', 1024)),
            ],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.item_id' => [
                'required',
                'integer',
                Rule::exists('sap_items_cache', 'id')->where(fn ($query) => $query
                    ->where('company_db', app(SapCompanyContext::class)->current())
                    ->where('is_active', true)
                    ->where('is_inventory_item', true)),
            ],
            'lines.*.vehicle_cost_center_id' => [
                'nullable',
                'integer',
                Rule::exists('sap_cost_center_caches', 'id')->where(fn ($query) => $query
                    ->where('company_db', app(SapCompanyContext::class)->current())
                    ->where('dimension', config('sap.dimensions.vehicle'))
                    ->where('is_active', true)),
            ],
            'lines.*.requested_quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'lte:lines.*.requested_quantity', 'max:999999999'],
            'lines.*.ending_km' => ['required', 'integer', 'min:0'],
            'lines.*.issue_type_id' => ['required', 'integer', Rule::exists('issue_types', 'id')->where('is_active', true)],
            'lines.*.serial_numbers' => ['nullable', 'array'],
            'lines.*.serial_numbers.*' => ['required', 'string', 'max:32', 'distinct:strict'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines', []);

        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $serialNumbers = preg_split('/[\r\n,;]+/', (string) ($line['serial_numbers'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            $lines[$index]['serial_numbers'] = array_values(array_unique(array_map('trim', $serialNumbers ?: [])));
        }

        $this->merge(['lines' => $lines]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $profile = $this->user()->operationalProfile;

            if (! $profile->warehouse_code) {
                $validator->errors()->add('lines', 'Warehouse belum dipetakan pada profil user. Hubungi administrator.');

                return;
            }

            $companyDb = app(SapCompanyContext::class)->current();
            $warehouseStocks = SapItemWarehouseStock::query()
                ->forCompany($companyDb)
                ->forWarehouse($profile->warehouse_code)
                ->where('is_locked', false)
                ->whereIn('sap_item_cache_id', collect($this->input('lines', []))->pluck('item_id'))
                ->get()
                ->keyBy('sap_item_cache_id');
            $issueTypes = IssueType::query()
                ->whereIn('id', collect($this->input('lines', []))->pluck('issue_type_id')->filter())
                ->get()
                ->keyBy('id');

            $requestedByItem = collect($this->input('lines', []))
                ->groupBy(fn (mixed $line): int => (int) data_get($line, 'item_id'))
                ->map(fn ($lines): float => (float) $lines->sum(fn (mixed $line): float => (float) data_get($line, 'quantity', 0)));

            foreach ($this->input('lines', []) as $index => $line) {
                $stock = $warehouseStocks->get((int) ($line['item_id'] ?? 0));
                $quantity = (float) ($line['quantity'] ?? 0);
                $totalRequested = (float) $requestedByItem->get((int) ($line['item_id'] ?? 0), 0);
                $issueType = $issueTypes->get((int) ($line['issue_type_id'] ?? 0));

                if (! $stock || (float) $stock->available_stock <= 0) {
                    $validator->errors()->add("lines.{$index}.item_id", "Item tidak memiliki stok tersedia di warehouse {$profile->warehouse_code}.");

                    continue;
                }

                if ($totalRequested > (float) $stock->available_stock) {
                    $available = number_format((float) $stock->available_stock, 2, ',', '.');
                    $validator->errors()->add("lines.{$index}.quantity", "Total Qty Issue untuk item ini melebihi stok tersedia warehouse {$profile->warehouse_code} ({$available}).");
                }

                if (! $issueType) {
                    continue;
                }

                if (in_array($issueType->code, config('sap.goods_issue.serial_issue_type_codes', []), true)) {
                    $serialNumbers = $line['serial_numbers'] ?? [];

                    if (floor($quantity) !== $quantity) {
                        $validator->errors()->add("lines.{$index}.quantity", 'Qty issue untuk ban harus berupa bilangan bulat.');
                    }

                    if (count($serialNumbers) !== (int) $quantity) {
                        $validator->errors()->add("lines.{$index}.serial_numbers", 'Jumlah serial number harus sama dengan Qty Issue.');
                    }
                }
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'fpk_number' => 'nomor FPK',
            'fpk_date' => 'tanggal FPK/pengeluaran',
            'lines.*.item_id' => 'item',
            'lines.*.vehicle_cost_center_id' => 'nopol',
            'lines.*.issue_type_id' => 'tipe pengeluaran',
            'lines.*.requested_quantity' => 'qty permintaan FPK',
            'lines.*.quantity' => 'qty issue',
            'lines.*.ending_km' => 'KM akhir',
            'lines.*.serial_numbers' => 'serial number',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'attachments.max' => 'Maksimal '.config('sap.documents.goods_issue_attachments.max_files_per_upload', 3).' foto FPK dalam satu kali upload.',
            'attachments.*.mimetypes' => 'Lampiran FPK hanya boleh berupa foto JPG, JPEG, atau PNG.',
            'attachments.*.extensions' => 'Lampiran FPK hanya boleh menggunakan ekstensi JPG, JPEG, atau PNG.',
            'attachments.*.max' => 'Ukuran setiap foto FPK maksimal 1 MB.',
        ];
    }
}
