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
        ];
    }
}
