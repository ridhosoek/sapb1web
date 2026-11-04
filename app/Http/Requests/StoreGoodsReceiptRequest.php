<?php

namespace App\Http\Requests;

use App\Models\SapDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

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
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_num' => ['required', 'integer', 'min:0', 'distinct:strict'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lines.*.serial_numbers' => ['nullable', 'array', 'max:100'],
            'lines.*.serial_numbers.*' => ['required', 'string', 'max:32', 'distinct:strict'],
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
            'lines.required' => 'Pilih minimal satu baris PO untuk diterima.',
            'lines.*.quantity.gt' => 'Qty diterima harus lebih besar dari nol.',
            'lines.*.serial_numbers.max' => 'Maksimal 100 serial number per baris item.',
            'lines.*.serial_numbers.*.max' => 'Setiap serial number maksimal 32 karakter sesuai batas SAP Business One.',
            'lines.*.serial_numbers.*.distinct' => 'Serial number tidak boleh duplikat.',
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
            'lines.*.serial_numbers.*' => 'serial number',
        ];
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
                    $serialNumbers = preg_split('/[\r\n,;]+/', $serialNumbers) ?: [];
                }

                $line['serial_numbers'] = collect(is_array($serialNumbers) ? $serialNumbers : [])
                    ->filter(fn (mixed $serialNumber): bool => is_scalar($serialNumber))
                    ->map(fn (mixed $serialNumber): string => trim((string) $serialNumber))
                    ->filter()
                    ->values()
                    ->all();

                return $line;
            })
            ->all();

        $this->merge(['lines' => $lines]);
    }
}
