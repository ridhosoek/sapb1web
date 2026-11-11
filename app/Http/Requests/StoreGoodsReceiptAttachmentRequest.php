<?php

namespace App\Http\Requests;

use App\Models\SapDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;

/**
 * Photo upload for one Goods Receipt PO. It is a request of its own on purpose (TD-10): a failed or repeated upload
 * must never touch the SAP posting.
 */
class StoreGoodsReceiptAttachmentRequest extends FormRequest
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
            'goods_receipt_id' => ['required', 'integer'],
            'images' => ['required', 'array', 'min:1', 'max:'.self::maximumFiles()],
            'images.*' => [
                'required',
                File::image()
                    ->extensions(['jpg', 'jpeg', 'png', 'webp'])
                    ->max(self::maximumSizeKilobytes()),
            ],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'goods_receipt_id.required' => 'Pilih GRPO tujuan foto.',
            'goods_receipt_id.integer' => 'GRPO tujuan foto tidak valid.',
            'images.required' => 'Pilih minimal satu foto penerimaan.',
            'images.array' => 'Bukti penerimaan harus berupa kumpulan foto.',
            'images.max' => 'Maksimal '.self::maximumFiles().' foto dalam satu kali upload.',
            'images.*.uploaded' => 'Foto :attribute gagal diunggah. Ukurannya kemungkinan melebihi batas '.self::maximumSizeLabel().'; perkecil foto lalu coba lagi.',
            'images.*.image' => 'File :attribute bukan gambar yang valid.',
            'images.*.extensions' => 'File :attribute harus berformat JPG, JPEG, PNG, atau WebP.',
            'images.*.max' => 'Foto :attribute melebihi batas '.self::maximumSizeLabel().'.',
        ];
    }

    /**
     * Name the offending file in the message so the user knows which photo to replace.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach ((array) $this->file('images', []) as $index => $image) {
            if ($image instanceof UploadedFile) {
                $attributes["images.{$index}"] = '"'.$image->getClientOriginalName().'"';
            }
        }

        return $attributes;
    }

    public static function maximumFiles(): int
    {
        return (int) config('sap.documents.goods_receipt_attachments.max_files_per_upload', 5);
    }

    public static function maximumSizeKilobytes(): int
    {
        return (int) config('sap.documents.goods_receipt_attachments.max_size_kb', 1024);
    }

    public static function maximumSizeLabel(): string
    {
        return rtrim(rtrim(number_format(self::maximumSizeKilobytes() / 1024, 1, '.', ''), '0'), '.').' MB';
    }
}
