<?php

namespace App\Http\Requests;

use App\Models\SapDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreGoodsReceiptRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', SapDocument::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'receipt_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'receipt_request_uuid' => [Rule::requiredIf(fn (): bool => (bool) config('sap.goods_receipt.partial_receipts_enabled')), 'nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_num' => ['required', 'integer', 'min:0', 'distinct:strict'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.serial_numbers' => ['nullable', 'array', 'max:100'],
            'lines.*.serial_numbers.*' => ['required', 'array'],
            'lines.*.serial_numbers.*.manufacturer_serial_number' => ['nullable', 'string', 'max:32'],
            'lines.*.serial_numbers.*.internal_serial_number' => ['nullable', 'string', 'max:32'],
            'lines.*.serial_numbers.*.lot_number' => ['nullable', 'string', 'max:32'],
            'lines.*.serial_numbers.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.serial_numbers.*.manufacture_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.serial_numbers.*.reception_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.serial_numbers.*.location' => ['nullable', 'string', 'max:100'],
            'lines.*.serial_numbers.*.notes' => ['nullable', 'string', 'max:254'],
            'images' => ['nullable', 'array', 'max:'.config('sap.documents.goods_receipt_attachments.max_files_per_upload', 5)],
            'images.*' => [
                'required',
                File::image()
                    ->extensions(['jpg', 'jpeg', 'png', 'webp'])
                    ->max((int) config('sap.documents.goods_receipt_attachments.max_size_kb', 5120)),
            ],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'receipt_date.before_or_equal' => 'Tanggal penerimaan tidak boleh melewati hari ini.',
            'receipt_request_uuid.required' => 'ID permintaan penerimaan tidak ditemukan. Muat ulang halaman lalu kirim kembali.',
            'receipt_request_uuid.uuid' => 'ID permintaan penerimaan tidak valid. Muat ulang halaman lalu kirim kembali.',
            'lines.required' => 'Pilih minimal satu baris PO untuk diterima.',
            'lines.*.quantity.gt' => 'Qty diterima harus lebih besar dari nol.',
            'lines.*.serial_numbers.max' => 'Maksimal 100 serial number per baris item.',
            'lines.*.serial_numbers.*.manufacturer_serial_number.max' => 'Nomor seri pabrikan maksimal 32 karakter sesuai batas SAP Business One.',
            'lines.*.serial_numbers.*.internal_serial_number.max' => 'Serial number maksimal 32 karakter sesuai batas SAP Business One.',
            'lines.*.serial_numbers.*.lot_number.max' => 'Lot number maksimal 32 karakter sesuai batas SAP Business One.',
            'images.max' => 'Maksimal '.config('sap.documents.goods_receipt_attachments.max_files_per_upload', 5).' foto dalam satu kali upload.',
            'images.*.image' => 'Setiap lampiran harus berupa gambar yang valid.',
            'images.*.extensions' => 'Format foto harus JPG, JPEG, PNG, atau WebP.',
            'images.*.max' => 'Ukuran setiap foto maksimal '.number_format((int) config('sap.documents.goods_receipt_attachments.max_size_kb', 5120) / 1024, 0).' MB.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'receipt_date' => 'tanggal penerimaan',
            'lines.*.quantity' => 'qty diterima',
            'lines.*.serial_numbers.*.manufacturer_serial_number' => 'nomor seri pabrikan',
            'lines.*.serial_numbers.*.internal_serial_number' => 'serial number',
            'lines.*.serial_numbers.*.lot_number' => 'lot number',
            'lines.*.serial_numbers.*.expiry_date' => 'tanggal kedaluwarsa',
            'lines.*.serial_numbers.*.manufacture_date' => 'tanggal produksi',
            'lines.*.serial_numbers.*.reception_date' => 'tanggal penerimaan serial',
            'lines.*.serial_numbers.*.location' => 'lokasi serial',
            'lines.*.serial_numbers.*.notes' => 'catatan serial',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->input('lines', []) as $lineIndex => $line) {
                if (! is_array($line)) {
                    continue;
                }

                foreach ($line['serial_numbers'] ?? [] as $serialIndex => $serialNumber) {
                    if (! is_array($serialNumber)) {
                        continue;
                    }

                    $hasIdentifier = collect([
                        $serialNumber['manufacturer_serial_number'] ?? null,
                        $serialNumber['internal_serial_number'] ?? null,
                        $serialNumber['lot_number'] ?? null,
                    ])->contains(fn (mixed $value): bool => filled($value));

                    if (! $hasIdentifier) {
                        $validator->errors()->add(
                            "lines.{$lineIndex}.serial_numbers.{$serialIndex}",
                            'Setiap baris serial harus memiliki Nomor Seri Pabrikan, Serial Number, atau Lot Number.',
                        );
                    }
                }
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $lines = collect($this->input('lines', []))
            ->map(function (mixed $line): mixed {
                if (! is_array($line)) {
                    return $line;
                }

                $serialNumbers = $line['serial_numbers'] ?? [];

                if (is_string($serialNumbers)) {
                    $serialNumbers = collect(preg_split('/[\r\n,;]+/', $serialNumbers) ?: [])
                        ->map(fn (string $serialNumber): array => ['internal_serial_number' => trim($serialNumber)])
                        ->all();
                }

                $line['serial_numbers'] = collect(is_array($serialNumbers) ? $serialNumbers : [])
                    ->map(function (mixed $serialNumber): mixed {
                        if (is_scalar($serialNumber)) {
                            return ['internal_serial_number' => trim((string) $serialNumber)];
                        }

                        if (! is_array($serialNumber)) {
                            return $serialNumber;
                        }

                        return collect([
                            'manufacturer_serial_number',
                            'internal_serial_number',
                            'lot_number',
                            'expiry_date',
                            'manufacture_date',
                            'reception_date',
                            'location',
                            'notes',
                        ])->mapWithKeys(function (string $field) use ($serialNumber): array {
                            $value = trim((string) ($serialNumber[$field] ?? ''));

                            return [$field => $value !== '' ? $value : null];
                        })->all();
                    })
                    ->values()
                    ->all();

                return $line;
            })
            ->all();

        $this->merge(['lines' => $lines]);
    }
}
