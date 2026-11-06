# Rencana Delivery Claude Code — GRPO dan Goods Issue

Pembaruan: 2026-11-06. Rencana ini mengatur pekerjaan lokal untuk `docs/product/tech-debt.md`. Setiap paket menghasilkan perubahan yang dapat direview sendiri. Status **kode selesai** berbeda dari **terverifikasi pada SAP nyata**. Tidak ada paket yang mengizinkan commit, push, deployment, perubahan database production, atau transaksi SAP production.

## Model dan effort yang disarankan

Pengaturan ini dipilih **di Claude Code sebelum mengirim prompt**, bukan melalui isi prompt. Gunakan alias agar model mengikuti yang tersedia pada provider/akun; periksa hasilnya dengan `/model` dan `/effort status`. Bila model atau effort tidak tersedia karena versi atau kebijakan akun, laporkan yang aktif dan lanjutkan dengan opsi terbaik yang diizinkan. Rujukan: [dokumentasi model dan effort Claude Code](https://code.claude.com/docs/en/model-config).

| Paket | Model | Effort | Alasan |
| --- | --- | --- | --- |
| 0–3 | `sonnet` | `high` | Audit source, bug terlokalisasi, dan test dengan review per paket. |
| 4 dan 6 | `opus` | `high` | Membaca kontrak SAP dan menangani failure mode transaksi. |
| 5 | `opus` | `xhigh` jika tersedia, selain itu `high` | Desain idempotency dan beberapa GRPO per PO. |
| 7 | `sonnet` | `medium` | Sinkronisasi dokumentasi dan matriks UAT setelah perilaku selesai. |

Di terminal Claude Code, gunakan `/model <alias>` dan `/effort <level>` sebelum paket berikutnya. Untuk sesi CLI baru, gunakan `claude --model <alias> --effort <level>`. Pilihan model tidak menggantikan gerbang bukti atau kewajiban test.

## 1. Keputusan yang sudah final

| Topik | Keputusan user | Sumber |
| --- | --- | --- |
| PO Ready for GRPO | PO yang masih pending approval SAP tidak masuk daftar; status diperiksa ulang sebelum POST. | `docs/product/business-process-register.md:10` |
| Penerimaan parsial | Satu PO boleh memiliki beberapa GRPO sampai seluruh open quantity habis. | `docs/product/business-process-register.md:9-10` |
| BAN nonserial | Serial mengikuti Item Master per item; Selendang Ban nonserial boleh diterima tanpa serial. | `docs/product/business-process-register.md:11` |
| GI H-1 | Semua pembuat GI yang berwenang boleh memakai tanggal FPK hari ini atau H-1. `DocDate` dan `TaxDate` mengikuti FPK. | `docs/product/business-process-register.md:12` |
| Approval GI terlambat | Jika pada approval final tanggal FPK sudah lebih tua dari H-1, tolak sebelum POST. Pengajuan ulang memakai FPK fisik, nomor, dan tanggal baru. | `docs/product/business-process-register.md:12` |

## 2. Hal yang harus dibuktikan, bukan diasumsikan

| Kontrak | Bukti minimum | Jika bukti belum tersedia |
| --- | --- | --- |
| Status approval PO di IA dan IAL_DEV | `$metadata` dan GET Service Layer yang menunjukkan indikator resmi beserta nilainya untuk PO pending dan PO approved; indikator dapat berada pada PO atau resource approval terkait. Respons error -5002 di log portal hanya membuktikan penolakan pada waktu POST. | Jangan menebak field atau menyatakan filter selesai. Kerjakan paket independen, laporkan kebutuhan akses SAP read-only. |
| Stok tersedia per item dan warehouse | Metadata/respons GET SAP untuk `InStock`, `Committed`, status locked, dan arti stok tersedia. Gateway yang ada hanya memilih `InStock > 0` dan `Locked = tNO` (`app/Integration/Sap/MasterData/WarehouseStockGateway.php:15-27`), sehingga hasil kosong tidak boleh disamakan dengan stok nol. | Jangan mengganti validasi final dengan query yang tidak membedakan nol, locked, item hilang, dan SAP gagal. |
| Idempotency per penerimaan GRPO | Bukti apakah UDF transaksi web tersedia pada `PurchaseDeliveryNotes`; periksa config, `$metadata`, dan pola command. Saat ini UUID dibuat di server saat POST dan tidak dikirim dalam payload (`app/Application/Receiving/PostGoodsReceipt.php:123-133`; `app/Integration/Sap/Documents/GoodsReceiptPayloadBuilder.php:30-69`). | Rancang rekonsiliasi tanpa blind retry. Jika perlu UDF/migration SAP, ajukan keputusan sebelum mengubah SAP. |
| Siklus approval GI | Baca status dan transaksi di `DecideDocumentApproval` sebelum menambah penolakan otomatis; approval final saat ini ditandai `APPROVED` sebelum POST (`app/Application/Approvals/DecideDocumentApproval.php:22-77`). | Jangan menghasilkan status `APPROVED` untuk GI yang tanggalnya sudah tidak sah. |
| Kebijakan periode SAP tertutup | User belum menentukan pemilik eskalasi. | Implementasikan penolakan dan pesan tanpa menggeser tanggal; jangan menetapkan role eskalasi baru. |

Rahasia tidak boleh dicetak ke terminal, browser, log, chat, test fixture, atau Git. Query MySQL production hanya `SELECT`. SAP production hanya GET/read-only sampai ada otorisasi eksplisit untuk transaksi UAT.

## 3. Urutan paket kerja

| Paket | Scope | Gerbang sebelum coding | Bukti selesai lokal |
| --- | --- | --- | --- |
| 0 | Baseline dan evidence ledger | Baca `AGENTS.md`, aturan/skill proyek, docs, source, test; catat `git status`, branch, commit, file lokal user. Pisahkan keputusan, fakta kode, data portal, dan SAP nyata. | Tabel fakta dengan rujukan file/baris dan pertanyaan yang benar-benar mengubah transaksi. Belum ada edit kode. |
| 1 | **TD-03** workflow company | Source cukup; tidak butuh credential SAP. | Workflow wajib `company_db` dokumen; jika tidak ada/tidak ada step, tidak ada approval/email/post. Test cocok, tidak ada, dan workflow company lain. |
| 2 | **TD-04** BAN nonserial | Verifikasi cabang validasi dan payload yang sudah ada; metadata item yang gagal dibaca tetap error. | BAN nonserial Qty tanpa serial; serial-managed tetap Qty bulat dan satu serial per unit. Test payload dan failure mode. |
| 3 | **BP-06** GI H-1 | Keputusan produk final; verifikasi timezone aplikasi dan transisi approval. | Hari ini/H-1 valid; H-2/future ditolak. UI, server, payload, dan audit cocok. GI terlambat ditolak otomatis sebelum POST dan pesan meminta FPK baru. |
| 4 | **TD-01** PO pending approval | Wajib bukti indikator dan nilai SAP untuk IA dan IAL_DEV. | Pending tidak tampil Ready; approved/open/sisa Qty tampil; status unknown fail closed; cek ulang sebelum POST; error SAP terpetakan. Test cache lama dan status berubah. |
| 5 | **TD-05** beberapa GRPO per PO | Wajib desain tertulis untuk identitas per permintaan, rekonsiliasi timeout, relasi PO–GRPO, dan open quantity per line. Jangan mengandalkan `existingReceipt()` berbasis PO sebagai idempotency. | GRPO parsial 1 dan 2 boleh; klik ganda permintaan sama tidak membuat dokumen baru; sisa Qty live membatasi POST; full saja terminal; watcher dan progress tepat. |
| 6 | **TD-02** stok GI live | Wajib bukti kontrak SAP per item/warehouse dan cara membedakan nol, locked, missing, serta GET gagal. | Cek live sebelum POST, jumlahkan Qty per item; insufficient/locked/unknown/gagal baca tidak POST; SAP_ERROR dan POSTING_UNKNOWN tetap berbeda; cache tidak dianggap otoritas final. |
| 7 | **TD-06/07** UAT dan dokumentasi | Paket 1–6 yang relevan selesai lokal. | Dokumen bisnis, handoff, runbook, dan backlog selaras dengan kode; matriks UAT IA/IAL_DEV berisi hasil yang benar-benar diuji, bukan klaim dari fake test. |

Paket 1–3 dapat dikerjakan tanpa akses SAP langsung. Paket 4 dan 6 **terblokir pada kontrak SAP** bila akses read-only belum tersedia. Paket 5 membutuhkan desain idempotency yang dapat direview sebelum perubahan besar; Claude boleh mengerjakan analisisnya sembari menunggu akses. Jangan menandai paket terblokir sebagai selesai.

## 4. Aturan kerja per paket

1. Sebelum edit: tulis **fakta**, **keputusan final**, **kontrak belum terbukti**, file yang akan disentuh, dan acceptance criteria. Bila pertanyaan mengubah transaksi, accounting, approval, atau authorization, tanya user; untuk detail teknis biasa, cari bukti di source/test/dokumentasi lebih dulu.
2. Implementasikan hanya paket yang gerbangnya terpenuhi. Jangan mengubah kode paket lain secara terselubung. Jaga semua perubahan lokal user dan jangan memperbarui dependency tanpa izin.
3. Uji happy path dan failure mode yang material dengan test terfokus. Setelah PHP berubah, jalankan Pint sesuai `AGENTS.md`. Periksa diff dan dampak migration, config, worker, scheduler, serta build.
4. Laporkan per paket: file/baris, apa yang berubah, hasil test, batas verifikasi SAP, risiko tersisa, dan status `SELESAI LOKAL`, `PERLU UAT SAP`, atau `TERBLOKIR`. Lanjutkan paket independen tanpa menunggu jawaban untuk paket lain.
5. Tidak ada commit, push, deploy, DB production write, SAP production POST, atau pencetakan secret. `SAP_ERROR` boleh ditangani sebagai penolakan pasti; `POSTING_UNKNOWN` memerlukan rekonsiliasi sebelum retry.

## 5. Handoff yang diminta dari Claude

Setelah tiap paket, kirim ringkasan pendek dan tabel acceptance criteria `Lulus / Gagal / Belum diverifikasi`. Pada akhir pekerjaan, kirim daftar perubahan lokal, test yang dijalankan, dokumentasi yang diselaraskan, kebutuhan UAT SAP nyata, dan keputusan user yang masih terbuka. Jangan menulis bahwa seluruh backlog selesai jika paket 4 atau 6 belum melewati bukti SAP.

## 6. Prompt lanjutan setelah review

Setelah laporan dan diff satu paket direview, kirim prompt berikut dengan nomor paket yang tepat. Jika paket yang diminta memerlukan kontrak SAP, minta Claude membuktikan kontraknya terlebih dahulu dalam respons yang sama; bila belum terbukti, Claude harus menandainya `TERBLOKIR` dan melanjutkan analisis paket independen yang ditunjuk user.

> Lanjutkan **hanya Paket [nomor]** pada `docs/agent-handoff/claude-code-delivery-plan.md`, termasuk protokol penanda selesai di Bagian 7. Baca ulang status Git dan keputusan produk terbaru. Sebelum edit, tulis fakta kode/SAP yang membuktikan gerbang paket terpenuhi serta acceptance criteria. Implementasikan scope paket itu saja, jalankan test terfokus dan Pint bila PHP berubah, lalu laporkan diff, hasil test, status verifikasi SAP, dan risiko tersisa. Jangan commit, push, deploy, atau melakukan write ke production. Berhenti setelah menulis penanda **SIAP DIREVIEW** dan handoff paket untuk review.

User dapat meminta status tech debt kepada Co-Product Owner tanpa menyalin laporan Claude; status Git dan penanda paket akan diperiksa langsung. Paket yang tersangkut akses SAP tidak menghalangi Paket 1–3, perapihan dokumentasi, atau desain tertulis Paket 5.

## 7. Penanda selesai dan status yang dapat diaudit

Satu perubahan file atau test yang lulus tidak membuktikan Claude sudah berhenti bekerja. Untuk setiap paket berikutnya, Claude mengubah kolom **Penanda Claude** di tabel bawah menjadi **SEDANG DIKERJAKAN** saat mulai dan **SIAP DIREVIEW** hanya pada akhir pekerjaan, setelah semua test serta diff diperiksa. Di akhir file ini Claude menambahkan bagian `Handoff Paket N` berisi tanggal/jam, branch dan commit dasar, file yang berubah, perintah/hasil test, acceptance criteria yang belum terpenuhi, serta batas verifikasi SAP. Claude hanya mengubah penandanya sendiri dan bagian handoff; kolom **Status review** serta backlog produk diubah oleh Co-Product Owner setelah review independen. Jika penanda akhir atau handoff tidak ada, status paket adalah **BELUM TERBUKTI SELESAI** meskipun ada perubahan kode.

Saat user bertanya tentang tech debt, Co-Product Owner membaca status Git dan penanda paket terbaru, membandingkan SHA-256 file yang pernah direview dengan fingerprint di bawah, lalu memeriksa source, test, dan bukti SAP terhadap acceptance criteria. Perubahan baru membatalkan verifikasi lama untuk scope yang terkena. **TERVERIFIKASI LOKAL** tidak berarti **UAT SAP LULUS** atau **DEPLOYED**. Tanya langsung kapan saja; tidak ada pemantauan otomatis saat chat tidak aktif.

| Paket | Penanda Claude | Status review per 2026-11-06 | Bukti dan batas |
| --- | --- | --- | --- |
| 1 / TD-03 | Protokol belum berlaku | **TERVERIFIKASI LOKAL** | Diff `StartDocumentApproval` dan test diperiksa; suite 206 test/1093 assertion lulus saat review Paket 1. Verifikasi MySQL production dan deployment belum dilakukan. |
| 2 / TD-04 | **SIAP DIREVIEW** (revisi ke-2: status metadata dan metode tak dikenal, 2026-11-06 17:20 +07:00; lihat Handoff Paket 2 — revisi ke-2) | **TERVERIFIKASI LOKAL** | Review independen: 236 test/1266 assertion lulus; form, modal, Item Master, dan POST selaras untuk status serial yang diuji. Arti `bomm_OnReleaseOnly` non-BAN dan item nyata IA/IAL_DEV belum diverifikasi di SAP; belum deploy. |
| 3 / GI H-1 | **SIAP DIREVIEW** (2026-11-06 17:46 +07:00; lihat Handoff Paket 3) | **BELUM MULAI** | Keputusan bisnis tersedia; belum ada perubahan pada scope paket. |
| 4 / TD-01 | Belum ada | **TERBLOKIR KONTRAK SAP** | Indikator approval PO dari GET/metadata IA dan IAL_DEV belum terbukti. |
| 5 / TD-05 | Belum ada | **BELUM MULAI** | Desain idempotency dan rekonsiliasi belum direview. |
| 6 / TD-02 | Belum ada | **TERBLOKIR KONTRAK SAP** | Kontrak stok live per item/warehouse belum terbukti. |
| 7 / TD-06/07 | Belum ada | **BELUM MULAI** | Menunggu hasil paket dan bukti UAT yang sah. |

Fingerprint SHA-256 dari file yang direview pada 2026-11-06 (hitung ulang dengan `Get-FileHash` sebelum memakai status review lama):

| Paket | File | SHA-256 |
| --- | --- | --- |
| 1 | `app/Application/Approvals/StartDocumentApproval.php` | `D9603DFDCD71ADD2C409DA026761A53AE26823309DA333646EA54390EFB128FF` |
| 1 | `tests/Feature/ApprovalEmailTest.php` | `F83C307E1867C995D7A17BA8094C3B607172A6CCB848CF8F27910233C1281C8C` |
| 1 | `tests/Feature/GoodsIssueSubmissionTest.php` | `9BEF930F26C329501C4A3A9308693A8C3BFDF89741E11DE764C09ACE07BAA775` |
| 2 | `app/Domain/Sap/Enums/SerialReceiptRequirement.php` | `385F4BF04C77604F4441A666803C0E6EE6BFFE4B0F2BE16B5F9EEF7A2E48166B` |
| 2 | `app/Application/Receiving/PostGoodsReceipt.php` | `562E684B58868BB424BFE50EFD863013708AA26A75F3EE498C637D2A4CAA8691` |
| 2 | `app/Models/SapItemCache.php` | `86AF870184464E2DA39B1350A448FE0835BEC7F878DFD13BB70EF428526B3970` |
| 2 | `resources/views/operations/ready-to-receive/show.blade.php` | `EAF72058A1F66AD5EC8C50855EFA8BB1711EBE2B27922E27FA415B5A609A390A` |
| 2 | `resources/views/operations/item-master/show.blade.php` | `A844D693F876B438C75FC383D5086C1E6A6F9B5E25B55C141C7CB2FCE4258A04` |
| 2 | `resources/views/operations/item-master/index.blade.php` | `AF2B0C585DE1D8B47FAD109F19CAAED4D897BEE1C779266DEFB1E0B3AE177631` |
| 2 | `tests/Feature/GoodsReceiptPostingTest.php` | `48B4A4805BDD8901BBB9ACD510950A5CF3D276FFA6BE33A8708E4867C110B147` |
| 2 | `tests/Feature/GoodsReceiptDocumentationTest.php` | `CF665081DD9E929035941E1B52ECFAB59294B97C8717FF1CD35C7A514B910881` |
| 2 | `tests/Feature/ItemMasterTest.php` | `AFB1518270ED0B5C99AE4A51252CC8280F94DE3F6BC3388712C864E4BCD60148` |

## Handoff Paket 2 — revisi tampilan serial TD-04

- **Waktu:** 2026-11-06 16:47 +07:00. **Branch dan commit dasar:** `main` / `6e05b25`; tidak ada commit baru dan perubahan lokal user serta Paket 1 dipertahankan.
- **Akar masalah:** form menyimpulkan serial dari kolom boolean `manages_serial_numbers` dan keberadaan key `source_payload`, sehingga `ManageSerialNumbers = null`, tYES dengan metode lain, atau nilai tak dikenal tampil “Tidak dikelola serial”, padahal server menolak sebagian dari konfigurasi tersebut.
- **Perubahan:** `app/Domain/Sap/Enums/SerialReceiptRequirement.php` (baru) menjadi satu-satunya klasifikasi (`NonSerial`, `SerialRequired`, `SerialNotRequiredOnReceipt`, `Unsupported`, `Undetermined`) dan dipakai oleh `PostGoodsReceipt::receiptLines` (server), `SapItemCache::serialReceiptRequirement()` (nilai mentah dari `source_payload`), dan `ready-to-receive/show.blade.php` (form). `tNO` → nonserial; tYES + `bomm_OnEveryTransaction` → input serial; BAN tYES + metode lain → “Konfigurasi serial belum didukung”; flag atau metode null/tak dikenal → “Serial belum dapat dipastikan”.
- **Perubahan perilaku server di luar BAN (perlu keputusan review):** item non-BAN dengan flag serial tidak terbaca atau tYES tanpa metode kini ditolak (sebelumnya diterima tanpa serial). Ini fail-closed agar form dan server tidak berbeda; tidak ada aturan SAP baru. Non-BAN tYES + metode lain tetap diterima tanpa serial seperti sebelumnya dan form menyebutnya “tidak diminta saat GRPO”.
- **File:** `app/Domain/Sap/Enums/SerialReceiptRequirement.php`, `app/Models/SapItemCache.php`, `app/Application/Receiving/PostGoodsReceipt.php`, `resources/views/operations/ready-to-receive/show.blade.php`, `tests/Feature/GoodsReceiptDocumentationTest.php`, `tests/Feature/GoodsReceiptPostingTest.php`, dan penanda di berkas ini.
- **Hasil:** test terfokus `GoodsReceiptDocumentationTest` 14 lulus dan `GoodsReceiptPostingTest` lulus; suite lengkap 222 test/1174 assertion lulus; Pint lulus untuk 5 file PHP yang berubah; `git diff --check` bersih. Tidak ada aset frontend yang berubah (JS inline di Blade), jadi build Vite tidak diperlukan. View lama gagal pada 4 dari 6 kasus tampilan baru.
- **Belum terpenuhi / batas verifikasi:** tidak ada pembacaan atau POST ke SAP; perilaku nyata Selendang Ban dan nilai literal `ManageSerialNumbers` di IA/IAL_DEV belum diverifikasi (perlu UAT SAP). Halaman Item Master (`operations/item-master/show.blade.php:31-33`) masih memakai kolom boolean dan menampilkan “Tidak” untuk konfigurasi tak dikenal; tidak diubah karena di luar scope. Perilaku tYES + metode selain `bomm_OnEveryTransaction` untuk BAN tetap menunggu keputusan bisnis.

## Handoff Paket 2 — revisi ke-2 (status metadata, metode tak dikenal, Item Master)

- **Waktu:** 2026-11-06 17:20 +07:00. **Branch dan commit dasar:** `main` / `6e05b25`; tidak ada commit. Perubahan lokal user dan Paket 1 dipertahankan; SHA-256 tiga file Paket 1 tidak berubah dan sama dengan fingerprint di atas.
- **Temuan 1 (status metadata modal):** `data-item-metadata` memakai `hasCompleteMasterMetadata()` yang hanya memeriksa keberadaan key. Kini memakai `SapItemCache::metadataStatusLabel()` (`SapItemCache.php:99-110`): key tidak lengkap → “Belum tersedia”; key lengkap tetapi klasifikasi `Undetermined` → “Terbaca, serial belum dapat dipastikan”; selain itu “Terbaca dari SAP” (`show.blade.php:104`). Modal juga memakai label klasifikasi untuk “Manage Item By” (`:102`).
- **Temuan 2 (metode tak dikenal):** `SerialReceiptRequirement::resolve` (`SerialReceiptRequirement.php:25-47`) kini hanya mengenali `bomm_OnEveryTransaction` (serial wajib) dan `bomm_OnReleaseOnly`. Untuk `bomm_OnReleaseOnly`, perilaku yang sudah ada dipertahankan: BAN → `Unsupported` (ditolak), non-BAN → tanpa serial saat GRPO, dan bila grup item tidak terbaca → `Undetermined`. Metode lain, kosong, atau flag selain tYES/tNO → `Undetermined`: UI “belum dapat dipastikan” dan server menolak sebelum POST. Perilaku non-BAN + `bomm_OnReleaseOnly` berasal dari kode sebelum Paket 2; **tidak ada bukti SAP di repo untuk arti metode itu saat GRPO** dan belum diuji di SAP nyata.
- **Item Master:** `item-master/show.blade.php:31-34` dan `index.blade.php:48` memakai nilai mentah `source_payload` (“Ya/Tidak/Belum dapat dipastikan”, “Serial wajib saat GRPO” dari klasifikasi, baris baru “Status Metadata”). `requiresSerialNumbersOnReceipt()` kini berasal dari klasifikasi yang sama, sehingga modal, form, Item Master, dan server tidak berbeda. `isTireItem()` yang tidak dipakai di tempat lain diganti `tireGroupStatus()` (null bila nama grup tidak diketahui).
- **Server:** `PostGoodsReceipt.php:355-374` menghitung status BAN sebagai true/false/null dan pesan `Undetermined` kini menyebut flag, metode, dan grup item.
- **File:** `app/Domain/Sap/Enums/SerialReceiptRequirement.php`, `app/Models/SapItemCache.php`, `app/Application/Receiving/PostGoodsReceipt.php`, `resources/views/operations/ready-to-receive/show.blade.php`, `resources/views/operations/item-master/show.blade.php`, `resources/views/operations/item-master/index.blade.php`, `tests/Feature/GoodsReceiptPostingTest.php`, `tests/Feature/GoodsReceiptDocumentationTest.php`, `tests/Feature/ItemMasterTest.php`, dan penanda di berkas ini.
- **Test:** 11 kasus form+modal (nonserial, serial OnEvery, BAN OnReleaseOnly, flag null, tYES tanpa metode, metode tak dikenal BAN dan non-BAN, flag tak dikenal, non-BAN OnReleaseOnly, grup tak terbaca); 6 kasus Item Master show+index; test POST baru untuk metode tak dikenal (BAN dan non-BAN), grup tak terbaca, dan karakterisasi non-BAN OnReleaseOnly; test POST Paket 2 sebelumnya dipertahankan. Mutasi: metode tak dikenal dianggap tanpa serial → test POST gagal; status metadata selalu “Terbaca dari SAP” → 8 test gagal.
- **Hasil:** `GoodsReceiptPostingTest`, `GoodsReceiptDocumentationTest`, `ItemMasterTest` 52 test lulus; suite lengkap 236 test/1266 assertion lulus; Pint lulus untuk 6 file PHP; `git diff --check` bersih. Tidak ada aset frontend yang berubah, jadi build Vite tidak diperlukan.
- **Dampak deployment:** tanpa migration, config, worker, atau scheduler. Baris `sap_items_cache` tanpa `source_payload` (di cache lokal: 15.678 dari 16.645 baris) kini tampil “Belum pasti/Belum dapat dipastikan” di Item Master sampai disinkronkan ulang; sebelumnya tampil “Tidak”. Item non-BAN dengan flag serial tidak terbaca atau tYES dengan metode tidak dikenal kini ditolak saat GRPO.
- **Belum terpenuhi / batas verifikasi:** tidak ada GET/POST ke SAP; nilai literal `ManageSerialNumbers`/`SRIAndBatchManageMethod` item nyata (termasuk Selendang Ban) di IA dan IAL_DEV, serta arti `bomm_OnReleaseOnly` saat GRPO, belum diverifikasi (perlu UAT SAP). Keputusan bisnis untuk BAN dengan `bomm_OnReleaseOnly` masih terbuka. Hitungan cache di atas berasal dari MySQL lokal (`127.0.0.1`), bukan MySQL portal produksi.

## Handoff Paket 3 — Goods Issue tanggal FPK hari ini atau H-1

- **Waktu:** 2026-11-06 17:46 +07:00. **Branch dan commit dasar:** `main` / `6e05b25` (perubahan lokal belum di-commit saat paket dikerjakan). Perubahan lokal user serta Paket 1 dan 2 dipertahankan.
- **Fakta yang sudah benar dan tidak diubah:** `GoodsIssuePayloadBuilder.php:58-59` sudah mengirim `DocDate` dan `TaxDate` = `fpk_date`; `DocDueDate` tidak dikirim dan tidak diubah. `PostApprovedDocument.php:22` sudah mensyaratkan approval `APPROVED` sebelum posting, sehingga approval yang `REJECTED` tidak bisa diposting lewat Decide, `sap:post-approved`, maupun tombol admin.
- **Perubahan:**
  - `app/Application/Inventory/GoodsIssueFpkDateWindow.php` (baru): satu sumber jendela tanggal (hari ini dan H-1 menurut `APP_TIMEZONE`, default `Asia/Bangkok`).
  - `StoreGoodsIssueRequest.php:30-35` memakai jendela itu (menggantikan `date_equals:today`); `:100-111` menolak nomor FPK yang pernah ditolak otomatis; pesan di `:185-186`.
  - `resources/views/operations/goods-issues/create.blade.php`: `min` = H-1, `max` = hari ini, label Posting Date dan Document Date mengikuti tanggal FPK yang dipilih (skrip inline, tanpa build).
  - `DecideDocumentApproval.php:41-44, 98-146`: pada langkah approval final Goods Issue, tanggal FPK diperiksa di dalam transaksi **sebelum** step/approval/dokumen ditandai APPROVED dan sebelum POST. Bila di luar jendela, step dan approval `REJECTED`, `normalized_data` memuat `approval_status=REJECTED`, `approval_rejection_reason=FPK_DATE_EXPIRED`, `approval_rejected_automatically=true`, dan pesan; `sap_commands.last_error` diisi pesan; audit `goods_issue.rejected_expired_fpk_date` dicatat; tidak ada POST.
  - `GoodsIssueApprovalController.php:64-72` menampilkan pesan penolakan otomatis (bukan “disetujui dan dikirim ke SAP”).
  - `PostApprovedGoodsIssue.php:114-128`: penolakan SAP (mis. periode posting tertutup) kini juga mencatat audit `goods_issue.sap_post_rejected`; status `SAP_ERROR`, `last_error`, dan tanggal payload tidak digeser.
- **Keputusan model status:** tidak ada status `IntegrationStatus` baru. Penolakan otomatis mengikuti pola penolakan manual: approval `REJECTED`, dokumen dan command tetap `PENDING_POST` (enum tidak punya status ditolak), tetapi tidak dapat diposting karena gerbang approval. Menambah enum akan memengaruhi filter monitor dan query posting; bila Co-Product Owner menginginkan status terminal eksplisit untuk dokumen ditolak, itu perlu keputusan terpisah dan berlaku juga untuk penolakan manual.
- **Test:** `GoodsIssueSubmissionTest` 22 test (sebelumnya 14), menggantikan `test_goods_issue_cannot_use_a_past_fpk_date` yang mengunci aturan lama: hari ini dan H-1 diterima; H-2, besok, dan tahun lalu ditolak lewat request langsung; form hanya menawarkan H-1..hari ini dengan label tanggal mengikuti; H-1 mengirim `DocDate` dan `TaxDate` H-1; FPK hari ini yang disetujui besok (kini H-1) tetap diposting; FPK H-1 yang disetujui lusa ditolak otomatis dengan seluruh status, command, dan audit, tanpa POST dan tanpa mengubah stok; klik ulang, `sap:post-approved`, dan tombol admin tidak memposting; nomor FPK yang ditolak otomatis tidak dapat dipakai ulang tetapi nomor baru dengan tanggal baru diterima; penolakan manual tidak dianggap penolakan otomatis; respons penolakan SAP palsu menjaga tanggal FPK dan mencatat `SAP_ERROR` serta audit. Mutasi: menghapus cek approval final, mengembalikan `date_equals:today`, dan mengganti `TaxDate` masing-masing menggagalkan test terkait.
- **Hasil:** suite lengkap 248 test/1358 assertion lulus (baseline sebelumnya 236/1266); Pint lulus untuk 6 file PHP; `git diff --check` bersih. Tidak ada aset yang perlu dibundel (JS inline di Blade).
- **Dampak deployment:** tanpa migration, config, worker, atau scheduler. Kueri nomor FPK memakai path JSON `normalized_data->approval_rejection_reason` (kolom `json`, aman di MySQL; belum dijalankan terhadap MySQL).
- **Belum terpenuhi / batas verifikasi:** semua bukti berasal dari `Http::fake`; tidak ada GET/POST ke SAP. Kode dan teks error SAP untuk periode posting tertutup di IA dan IAL_DEV belum diketahui (test memakai pesan palsu). **Keputusan terbuka:** GI yang sudah `APPROVED` tetapi gagal POST (mis. `SAP_ERROR`) lalu dicoba ulang setelah FPK lebih tua dari H-1 tidak diperiksa ulang; aturan bisnis hanya menyebut approval final, dan menolak di titik itu membutuhkan transisi APPROVED→ditolak yang belum diputuskan. Pemilik eskalasi periode SAP tertutup belum diputuskan. `docs/application/business-processes.md` belum diselaraskan (TD-07).
