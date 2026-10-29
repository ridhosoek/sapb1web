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
                File::types(['jpg', 'jpeg', 'png', 'pdf'])
                    ->max((int) config('sap.documents.goods_issue_attachments.max_size_kb', 10240)),
            ],
            'caption' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'attachments.required' => 'Pilih minimal satu file scan FPK.',
            'attachments.max' => 'Maksimal '.config('sap.documents.goods_issue_attachments.max_files_per_upload', 3).' file dalam satu kali upload.',
            'attachments.*.mimetypes' => 'Lampiran harus berupa PDF, JPG, JPEG, atau PNG yang valid.',
            'attachments.*.extensions' => 'Format lampiran harus PDF, JPG, JPEG, atau PNG.',
            'attachments.*.max' => 'Ukuran setiap lampiran maksimal '.number_format((int) config('sap.documents.goods_issue_attachments.max_size_kb', 10240) / 1024, 0).' MB.',
        ];
    }
}
