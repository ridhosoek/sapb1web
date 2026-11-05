<x-mail::message>
# Persetujuan dokumen diperlukan

Yth. {{ $step->approver_name }},

{{ $approval->submitter->name }} mengajukan dokumen berikut dan menunggu keputusan Anda.

<x-mail::table>
| Informasi | Nilai |
|:--|:--|
| Dokumen | {{ $document->document_type === 'PURCHASE_REQUEST' ? 'Purchase Request' : 'Goods Issue' }} |
| Referensi | {{ $document->sap_doc_num ?: $document->fpk_number ?: 'Portal #'.$document->id }} |
| Database SAP | {{ $document->company_db }} |
| Pool / Department | {{ $document->pool?->code ?? '—' }} / {{ $document->department?->code ?? '—' }} |
| Jumlah Item | {{ count(data_get($document->normalized_data, 'lines', [])) }} |
| Tahap Approval | {{ $step->step_order }} dari {{ $approval->steps->count() }} |
</x-mail::table>

## Detail Item

<x-mail::table>
| Kode | Nama Item | Nopol | KM Akhir | Qty |
|:--|:--|:--|--:|--:|
@forelse($itemLines as $line)
| {{ $line['code'] }} | {{ $line['name'] }} | {{ $line['vehicle'] }} | {{ $line['ending_km'] }} | {{ $line['quantity'] }} |
@empty
| — | Detail item tidak tersedia | — | — | — |
@endforelse
</x-mail::table>

Silakan tinjau detail dokumen sebelum memilih **Setujui** atau **Tolak**.

<x-mail::button :url="$approvalUrl">
Tinjau dan Beri Keputusan
</x-mail::button>

Tautan ini bersifat pribadi, hanya dapat digunakan untuk tahap approval aktif, dan berlaku sampai {{ $step->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}.

Jika Anda tidak mengenali pengajuan ini, abaikan email dan hubungi administrator portal.

Hormat kami,<br>
{{ config('app.name') }}
</x-mail::message>
