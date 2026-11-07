# Desain Paket 5 / TD-05 — Beberapa GRPO per Purchase Order

Tanggal: 2026-11-07. Status: **KODE LOKAL SELESAI DI BALIK FLAG NONAKTIF (bagian 10); UAT SAP BELUM.** Status desain sebelumnya: **DESAIN DIREVIEW; NAMA FINAL UDF `U_U_WebTxnId` DIPUTUSKAN USER; SATU RUTE GET GRPO SUDAH HTTP 200 SETELAH RESTART, GERBANG LINTAS NODE/COMPANY BELUM LULUS; IAL_DEV BELUM DIPERIKSA; belum ada perubahan kode TD-05.** Pemeriksaan sebelumnya sempat HTTP 400 dan tetap dicatat pada Q-20261107-10. Bagian yang bergantung pada kontrak SAP ditandai **BELUM TERBUKTI**. Keputusan bisnis yang sudah final: satu PO boleh memiliki beberapa GRPO sampai seluruh open quantity habis (`docs/product/business-process-register.md:9-10`).

## 1. Kondisi source saat ini (fakta, bukan SAP)

| Hal | Perilaku saat ini | Rujukan |
| --- | --- | --- |
| PO dikeluarkan dari Ready begitu ada satu GRPO di SAP | `purchaseOrderEntriesWithReceipts` lalu `reject(...)` | `SyncReadyPurchaseOrders.php:69-79`; `GoodsReceiptGateway.php:24-83` |
| Posting mengembalikan GRPO lama bila relasi PO–GRPO lokal ada | `existingReceipt()` mengambil relasi pertama | `PostGoodsReceipt.php:63-67, 238-243` |
| Posting menolak bila SAP sudah punya GRPO non-cancelled untuk PO | `ensurePurchaseOrderHasNoGoodsReceipt` | `PostGoodsReceipt.php:79, 258-271` |
| UUID transaksi dibuat di server saat POST dan tidak dikirim ke SAP | `Str::uuid()` | `PostGoodsReceipt.php:124`; `GoodsReceiptPayloadBuilder.php:30-69` |
| Layar GRPO memperlakukan relasi pertama sebagai satu-satunya GRPO | mode “dokumentasi saja” setelah ada GRPO | `GoodsReceiptDocumentationController.php:42-45, 54, 70` |
| Watcher PR menandai `FULLY_RECEIVED` dan berhenti begitu satu GRPO ditemukan | `markGoodsReceiptFound` | `SyncPurchaseRequestProgress.php:452-486` |
| Relasi PO–GRPO sudah mengizinkan banyak anak | unique `(parent, child, relation_type)` | migrasi `create_operational_foundation_tables` baris 108 |
| `sap_commands.transaction_uuid` unik; GI dan PR sudah memakai UUID dari form sebagai identitas permintaan | pola yang dapat ditiru | `SubmitGoodsIssue.php:34-42`; `GoodsIssueController.php:62` |

**Temuan penting:** aturan satu-GRPO-per-PO hari ini juga bekerja sebagai pengaman *tidak sengaja* terhadap POST ganda. Bila POST pertama timeout tetapi SAP sempat membuat GRPO, percobaan berikutnya ditolak oleh `ensurePurchaseOrderHasNoGoodsReceipt`. Begitu aturan itu dicabut, pengaman itu hilang; tanpa identitas per permintaan, timeout + klik ulang dapat membuat GRPO ganda dengan Qty yang sama. Karena itu identitas dan rekonsiliasi harus ada **sebelum** aturan lama dicabut, dan `existingReceipt()` tidak boleh dijadikan idempotency.

## 2. Identitas per permintaan penerimaan

1. Form GRPO membuat `receipt_request_uuid` (UUID v4) sekali per render dan mengirimnya sebagai field tersembunyi, seperti `transaction_uuid` pada Goods Issue. Server memvalidasi `uuid` dan menolak bila kosong.
2. Nilai itu menjadi `sap_commands.transaction_uuid` (kolom sudah unik) dan `sap_documents.transaction_uuid` GRPO yang terbentuk. Tidak perlu migrasi untuk identitas.
3. Perilaku saat UUID yang sama dikirim ulang (klik ganda, refresh, retry):
   - command `IN_SYNC` → kembalikan GRPO yang sama; tidak ada POST;
   - command `POSTING` atau `POSTING_UNKNOWN` → tolak dengan pesan bahwa status perlu rekonsiliasi; tidak ada POST;
   - command `SAP_ERROR` → penolakan pasti; boleh dicoba lagi dengan UUID yang sama hanya setelah seluruh validasi live diulang (open quantity, status PO, TD-01), dengan `attempts + 1`.
4. UUID dipakai juga di payload SAP (bagian 3) dan di log audit.

## 3. Penanda di dokumen SAP (BELUM TERBUKTI)

Rekonsiliasi memerlukan cara menemukan GRPO milik satu permintaan. Opsi, urut preferensi:

| Opsi | Cara | Syarat | Status bukti |
| --- | --- | --- | --- |
| A. UDF header `PurchaseDeliveryNotes` | kirim `U_U_WebTxnId` (mapping `sap.fields.web_transaction_id`), cari dengan `$filter=U_U_WebTxnId eq '<uuid>'` | User menetapkan nama final yang sama untuk semua transaksi/CompanyDB; GRPO IA sudah menerima `$select`/`$filter`, IAL_DEV belum diperiksa | **Terbukti sebagian.** GET ulang GRPO IA memberi 200; belum ada bukti IAL_DEV, panjang field, atau penyimpanan UUID pada dokumen baru. Builder GRPO hari ini belum mengirim UDF itu (`GoodsReceiptPayloadBuilder.php:63-67` hanya `web_created_by_user`). |
| B. Penanda di `Comments` | tambahkan `[WEB:<uuid>]` ke `Comments` (batas 254, saat ini dipotong di `GoodsReceiptPayloadBuilder.php:35`), cari dengan `contains(Comments,'<uuid>')` | Tidak ada perubahan SAP; dapat diedit user di SAP sehingga lebih lemah | **Belum terbukti** bahwa `contains` didukung pada `PurchaseDeliveryNotes` di IA/IAL_DEV. |
| C. Cocokkan isi | BaseEntry + BaseLine + Quantity + tanggal + user | Tidak ada perubahan SAP | Tidak dapat membedakan dua penerimaan sah dengan Qty sama pada hari yang sama; hanya sinyal pendukung, tidak pernah dasar retry otomatis. |

**Keputusan user 2026-11-07: pilih A dan gunakan nama final `U_U_WebTxnId` pada semua transaksi/CompanyDB.** User mengonfirmasi UDF sudah dibuat di IA dan IAL_DEV; portal dan akun Co-Product Owner tetap baca saja. B (`Comments`) tidak dipilih sebagai fallback; C hanya sinyal pendukung. GET/filter GRPO IA terbukti; implementasi menunggu bukti untuk IAL_DEV dan tiap entity yang memakai field ini. `config/sap.php` masih memakai default nama lama `U_WebTxnId` dan harus diperbarui Claude bersama test yang terkait.

## 4. Rekonsiliasi timeout (`POSTING_UNKNOWN`)

- Setiap POST memakai command dengan `request_payload` tersimpan (sudah ada) sebelum request keluar.
- `ConnectionException` atau hasil tak jelas → command `POSTING_UNKNOWN` (pola yang ada, `PostGoodsReceipt.php:220-233`). Selama ada command `POSTING`/`POSTING_UNKNOWN` untuk suatu PO, **penerimaan baru untuk PO itu ditolak**, karena sisa Qty tidak pasti.
- Perintah rekonsiliasi (usulan `sap:goods-receipts:reconcile`, dijadwalkan dan dapat dipicu dari Integration Monitor) untuk setiap command tak pasti:
  1. GET GRPO dengan penanda bagian 3 (read-only).
  2. **Ditemukan** → buat `SapDocument` GRPO, relasi PO–GRPO, audit `goods_receipt_po.reconciled`, command `IN_SYNC`, perbarui cache PO dari GET live.
  3. **Tidak ditemukan** → tetap `POSTING_UNKNOWN`. Umur command, dua GET kosong, dan open quantity tidak berubah **tidak membuktikan** POST tidak terjadi; jangan otomatis mengubah menjadi `SAP_ERROR` atau membuka retry. Super Admin dapat menutup kasus hanya setelah investigasi otoritatif membuktikan tidak ada GRPO terkait, dengan audit; sebelum retry, ulangi validasi live PO, status approval, dan open quantity.
  4. GET gagal atau hasil ambigu → tetap `POSTING_UNKNOWN`; tidak ada retry.
- Tidak ada blind retry di jalur mana pun. Hanya Super Admin boleh memicu retry manual setelah bukti rekonsiliasi; command `POSTING_UNKNOWN` untuk PO tetap memblokir penerimaan baru sampai kasus diselesaikan.

## 5. Relasi PO–GRPO dan open quantity per line

- Satu PO banyak GRPO: buat relasi baru per GRPO. `relation_metadata` ditambah `lines: [{base_line, quantity}]` sebagai catatan; **sumber kebenaran sisa Qty tetap SAP live** (`RemainingOpenQuantity` per line pada GET PO, sudah dipakai di `PostGoodsReceipt.php:519`), bukan cache dan bukan jumlah GRPO lokal.
- Sebelum POST: GET PO live, tolak bila PO batal/tutup, line tertutup, Qty > sisa line. Dalam kunci per PO (`Cache::lock` yang ada, `PostGoodsReceipt.php:51`) agar dua permintaan berbeda tidak sama-sama lolos terhadap sisa yang sama; SAP tetap penentu akhir bila dua sesi di luar portal bertabrakan.
- Pemeriksaan TD-01 (status approval PO) wajib dijalankan pada **setiap** penerimaan, bukan hanya yang pertama.
- Sesudah POST berhasil: GET ulang PO live dan simpan `RemainingOpenQuantity` hasil baca, bukan hasil pengurangan lokal (`updatePurchaseOrder`, `PostGoodsReceipt.php:534-561`). Status `PARTIALLY_RECEIVED` selama ada line dengan sisa > 0; `RECEIVED`/Closed hanya bila semua line habis.
- GRPO yang kemudian dibatalkan di SAP mengembalikan sisa Qty. **BELUM TERBUKTI** pada SAP nyata; karena sumber kebenaran adalah GET live, perilakunya akan mengikuti SAP, tetapi perlu diuji.

## 6. Perubahan perilaku yang akan dikerjakan (setelah desain disetujui)

1. `SyncReadyPurchaseOrders`: hapus pengecualian PO yang punya GRPO; PO ready bila ada line terbuka dengan sisa > 0 dan lolos TD-01.
2. `PostGoodsReceipt`: hapus `existingReceipt()` sebagai idempotency dan `ensurePurchaseOrderHasNoGoodsReceipt`; gunakan UUID permintaan, tolak PO dengan command tak pasti.
3. `GoodsReceiptPayloadBuilder`: kirim penanda bagian 3.
4. Layar GRPO: daftar semua GRPO milik PO, formulir tetap tersedia selama sisa > 0, UUID per render, tombol dinonaktifkan setelah submit; foto dikaitkan ke GRPO yang dipilih.
5. `SyncPurchaseRequestProgress::markGoodsReceiptFound`: `FULLY_RECEIVED` dan watcher `Stopped` hanya bila semua PO terkait habis; selain itu `PARTIALLY_RECEIVED` dan watcher tetap aktif; kumpulkan semua nomor GRPO.
6. Perintah/job rekonsiliasi dan aksi admin di Integration Monitor.

Migrasi: tidak wajib. Opsional: indeks pada `sap_commands (command_type, status)` untuk mencari command tak pasti. Tanpa perubahan config selain nama UDF (opsi A) dan jendela rekonsiliasi. Perlu scheduler untuk perintah rekonsiliasi (dampak deployment: entri scheduler baru).

## 7. Rencana test

GRPO parsial ke-1 dan ke-2 untuk PO yang sama; PO tetap Ready setelah parsial dan keluar setelah penuh; klik ganda dengan UUID sama → satu POST; UUID berbeda yang melebihi sisa → ditolak; timeout → `POSTING_UNKNOWN`, PO terblokir, rekonsiliasi “ditemukan” (membuat dokumen/relasi tanpa POST ulang), GET kosong meski lama → tetap `POSTING_UNKNOWN`, retry hanya setelah bukti otoritatif dan tindakan Super Admin; GRPO dibatalkan di SAP; watcher PARTIAL vs FULL; TD-01 dicek di setiap penerimaan; foto per GRPO. Semua memakai fake HTTP dan **tidak** membuktikan perilaku SAP nyata; UAT SAP tetap diperlukan.

## 8. Bukti SAP read-only yang diperlukan sebelum coding

1. `$metadata` `PurchaseDeliveryNotes` di IA dan IAL_DEV: apakah nama final `U_U_WebTxnId` ada.
2. `GET PurchaseDeliveryNotes?$select=DocEntry,U_U_WebTxnId` dan `$filter=U_U_WebTxnId eq '<uuid tidak ada>'` pada masing-masing company. GET kosong membuktikan filter diterima, bukan bahwa penyimpanan UUID baru sudah terbukti.
3. `RemainingOpenQuantity` dan `LineStatus` PO sebelum dan sesudah GRPO parsial, dan sesudah pembatalan GRPO (contoh dokumen yang sudah ada; tanpa membuat transaksi baru).

## 9. Keputusan dan gerbang tersisa

0. **Temuan terbaru: 4 node Service Layer IA tidak seragam (GRPO diterima node2/node3, ditolak node1/node4); lihat bridge Q-20261107-10. Gerbang kontrak UDF belum terpenuhi.**
   **Pembaruan sebelumnya (GET ulang, IA): nama final menurut user adalah `U_U_WebTxnId`; `PurchaseDeliveryNotes` kini menerima `$select` dan `$filter` (200; `value` kosong untuk UUID yang tidak ada; `U_WebTxnId` tetap 400). IAL_DEV belum diperiksa, jadi gerbang kedua company belum terpenuhi. Q-20261107-09 sudah dijawab; default `config/sap.php` masih salah.** Catatan pemeriksaan pertama, yang menolak field di GRPO sebelum UDF diduga dibuat:
   **Verifikasi awal 2026-11-07 (IA, sesi dari user): GAGAL.** `$metadata` tidak memuat `U_WebTxnId`; hanya `U_U_WebTxnId` pada tipe `Document` dan `StockTransfer`. `GET PurchaseDeliveryNotes` dengan `$select`/`$filter` atas `U_WebTxnId` atau `U_U_WebTxnId` → HTTP 400; hanya `PurchaseOrders` menerima `U_U_WebTxnId` (200). Inferensi: UDF terpasang di header PO dengan awalan ganda, bukan di header GRPO. **IAL_DEV belum diperiksa.** Opsi A tidak dapat dikode sampai UDF GRPO benar ada di kedua company (bridge Q-20261107-09). Tidak ada kode TD-05 yang ditulis.
1. **Diputuskan user:** nama final `U_U_WebTxnId` pada semua transaksi dan CompanyDB. UDF dilaporkan dibuat di IA dan IAL_DEV; GET/filter GRPO IA terbukti, IAL_DEV belum. Tidak memakai `Comments` sebagai fallback.
2. **Keputusan Co-Product Owner untuk keselamatan transaksi:** tidak ada jendela waktu yang otomatis melepaskan `POSTING_UNKNOWN`; bila GET kosong/ambigu, tetap tak pasti. Hanya Super Admin boleh melakukan retry manual setelah investigasi otoritatif membuktikan POST tidak terjadi, dengan audit dan validasi live ulang.
3. **Keputusan bisnis yang sudah ada:** GRPO parsial boleh berulang sampai Qty terbuka habis; tidak ada batas jumlah arbitrer per PO.
4. **Keputusan Co-Product Owner untuk hasil ambigu:** penerimaan baru pada PO yang memiliki command `POSTING`/`POSTING_UNKNOWN` ditolak sementara; tidak ada bypass Admin HO.

Paket 5 **belum siap kode penuh** sampai keberadaan UDF dan kemampuan GET/filter pada bagian 8 terbukti lewat Service Layer, serta konflik approval PO pada TD-01 ditangani. Jangan menafsirkan konfirmasi pembuatan UDF sebagai izin menulis ke SAP melalui akun baca.

## 10. Status implementasi lokal (2026-11-07)

Kode TD-05 sudah ditulis **di balik flag SAP_GRPO_PARTIAL_RECEIPTS_ENABLED (config sap.goods_receipt.partial_receipts_enabled, default alse)**. Nonaktif berarti alur satu-GRPO lama tetap dipakai tanpa perubahan perilaku; aktif berarti alur di bagian 2–6. Penyimpangan dari desain di atas:

- Identitas per permintaan memakai `sap_commands.transaction_uuid` (unik) dan UUID dikirim di `U_U_WebTxnId`; pengiriman ditolak sebelum POST bila metadata `PurchaseDeliveryNotes` tidak memuat UDF. Metadata dibagi tipe `Document`, jadi ini bukan bukti bahwa semua node menerima UDF; SAP tetap penentu (400 → `SAP_ERROR`).
- Migrasi **diperlukan**: kolom `sap_commands.base_document_entry` (nullable, di-backfill dari payload command GRPO lama) dan indeks pencarian, agar command tidak pasti dapat memblokir PO yang tepat.
- Hanya HTTP 4xx (selain 408) yang dianggap penolakan pasti. HTTP 5xx, timeout, koneksi putus, dan respons tanpa DocEntry menjadi `POSTING_UNKNOWN`. Alur lama (flag nonaktif) tetap menganggap semua HTTP error SAP sebagai `SAP_ERROR`; ini risiko yang sudah ada dan tidak diubah selama flag nonaktif.
- `SAP_ERROR` dengan UUID yang sama dapat diulang hanya setelah lookup UUID di SAP kosong; bila lookup menemukan dokumen, dokumen direkonsiliasi, bukan diposting ulang.
- `sap:goods-receipts:reconcile` (terjadwal tiap 5 menit per company, hanya GET) tidak pernah membuka command karena waktu atau hasil kosong. Command lama yang dikirim tanpa UUID di UDF tidak dapat dicocokkan otomatis dan hanya dapat dilepas Super Admin.
- Pelepasan Super Admin ada di Integration Monitor (`POST admin/goods-receipt-commands/{command}/release`, gate `release-uncertain-goods-receipts`): wajib bukti investigasi tertulis (minimal 20 karakter) dan konfirmasi; lookup UUID dan daftar GRPO PO harus berhasil dibaca; bila GRPO ditemukan, command direkonsiliasi; selain itu command menjadi `SAP_ERROR` dengan audit `goods_receipt_po.recovery_released`. Pelepasan hanya mencabut blokir; penerimaan berikutnya memakai UUID baru dan validasi live penuh.
- Progress PR memakai `fully_received` pada `normalized_data` PO yang hanya diisi dari GET live SAP (`PurchaseOrderReceiptProgress`); status lokal `CLOSED` tidak dipakai sebagai bukti penerimaan penuh karena sinkronisasi Ready juga menutup PO yang ditahan.
- Kasus tersisa: GRPO yang dibatalkan **setelah** PR `FULLY_RECEIVED` baru terdeteksi saat sinkronisasi Ready melihat PO dengan Qty terbuka lagi atau saat watcher PR dijalankan manual dengan `--doc-entry`.

**Gerbang aktivasi (belum terpenuhi):** sesi baca IAL_DEV; UAT GRPO terotorisasi yang membuktikan UUID 36 karakter tersimpan dan terbaca balik lewat `$filter`; GRPO kedua pada PO yang sama diterima SAP; pembatalan GRPO mengembalikan `RemainingOpenQuantity`; review idempotensi/recovery oleh Co-Product Owner.
