<?php

namespace App\Http\Requests;

use App\Models\SapDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreGoodsIssueAttachmentRequest extends FormRequest
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
            'attachments' => ['required', 'array', 'min:1', 'max:'.config('sap.documents.goods_issue_attachments.max_files_per_upload', 3)],
            'attachments.*' => [
                'required',
                File::types(['jpg', 'jpeg', 'png'])
                    ->max((int) config('sap.documents.goods_issue_attachments.max_size_kb', 1024)),
            ],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'attachments.required' => 'Pilih minimal satu foto FPK.',
            'attachments.max' => 'Maksimal '.config('sap.documents.goods_issue_attachments.max_files_per_upload', 3).' foto dalam satu kali upload.',
            'attachments.*.mimetypes' => 'Lampiran FPK hanya boleh berupa foto JPG, JPEG, atau PNG.',
            'attachments.*.extensions' => 'Lampiran FPK hanya boleh menggunakan ekstensi JPG, JPEG, atau PNG.',
            'attachments.*.max' => 'Ukuran setiap foto FPK maksimal 1 MB.',
        ];
    }
}
