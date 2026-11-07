# Bisnis Proses

## 1. Login dan pemilihan database

```text
User membuka login
  → isi email + password portal
  → pilih database SAP
  → autentikasi akun aktif
  → simpan database di session
  → validasi operational profile
  → dashboard sesuai role/warehouse
```

Warehouse, Pool, dan Department berasal dari profil user. User tidak boleh menggantinya pada transaksi.

## 2. Purchase Request

```text
Admin Operasional membuat PR
  → pilih item
  → isi Qty, UoM manual opsional, Free Text
  → isi Nopol opsional per baris
  → isi KM Akhir per baris
  → sistem mengambil history item+nopol
  → simpan dokumen lokal + snapshot history
  → mulai workflow approval
  → email approver tahap 1
  → approval bertingkat
  → approval final
  → POST PurchaseRequests ke SAP
  → simpan DocEntry/DocNum
  → aktifkan watcher purchase progress
```

Mandatory saat ini:

- required date tidak boleh sebelum hari ini;
- priority `NORMAL`/`URGENT`;
- minimal satu line;
- item purchase aktif;
- Qty > 0;
- KM akhir per line;
- Warehouse/Pool/Department dari user profile.

Nopol dan UoM manual tidak mandatory.

## 3. Approval Purchase Request

1. Super Admin membuat workflow per Admin Operasional dan jenis dokumen.
2. Workflow menyimpan urutan approver.
3. PR mengirim email hanya kepada approver aktif saat ini.
4. Link signed berlaku 48 jam dan keputusan hanya dapat dilakukan sekali.
5. Approve meneruskan tahap berikutnya; Reject menghentikan dokumen.
6. Tahap final memicu posting ke SAP.
7. Admin HO dapat melakukan Check untuk identitas cetakan.

Manager transport lintas warehouse boleh dipilih sebagai approver untuk kebutuhan pengganti wilayah.

## 4. Purchase Progress

```text
PR sudah di SAP
  → watcher mencari Purchase Quotation berdasarkan base document
  → jika ditemukan, simpan relation PQ
  → cari Purchase Order dari PQ/PR
  → jika ditemukan, simpan relation PO
  → cari GRPO dari PO
  → jika ditemukan, simpan relation GRPO
  → update progress dan watcher
```

Quotation/PO/GRPO tidak boleh dicentang hanya karena status lokal atau asumsi urutan dokumen.

## 5. Goods Receipt PO

```text
Buka menu Goods Receipt PO
  → sinkron PO open dari SAP
  → filter Series 887/888
  → filter dua bulan terakhir
  → filter warehouse user
  → keluarkan PO yang sudah punya GRPO
  → user membuka PO
  → sistem refresh metadata item
  → isi Qty Diterima
  → untuk serial-managed: isi setup serial per unit
  → upload foto opsional
  → validasi ulang PO/open qty/serial
  → POST PurchaseDeliveryNotes ke SAP
  → simpan GRPO dan relasi ke PO
  → simpan foto privat hanya di portal
```

Aturan serial:

- serial ditentukan per item oleh Item Master SAP, bukan oleh nama grup: `ManageSerialNumbers=tNO` berarti nonserial (termasuk BAN seperti Selendang Ban; diterima berdasarkan Qty tanpa `SerialNumbers`), sedangkan `tYES` + `bomm_OnEveryTransaction` berarti serial wajib;
- jumlah serial sama dengan Qty Diterima dan Qty item serial harus bulat;
- minimal Manufacturer Serial, Internal Serial, atau Lot Number terisi;
- field unik mengikuti Company Admin Info jika dapat dibaca;
- BAN dengan `tYES` + `bomm_OnReleaseOnly` belum didukung (ditolak sampai ada keputusan bisnis); flag atau metode yang null, kosong, atau tidak dikenal dianggap belum pasti dan ditolak sebelum POST, dan ditampilkan sebagai “belum dapat dipastikan” pada form, modal, dan Item Master;
- non-BAN dengan `bomm_OnReleaseOnly` tetap diterima tanpa serial seperti perilaku sebelumnya; arti metode itu saat GRPO belum dibuktikan di SAP nyata;
- status: selesai di kode lokal (TD-04), belum UAT SAP atau deploy.

Keterbatasan saat ini (belum selesai): PO yang sudah punya satu GRPO keluar dari daftar dan GRPO kedua ditolak (TD-05, desain di `docs/agent-handoff/paket-5-design-td-05.md`); daftar Ready kini memakai aturan sementara fail closed pada `AuthorizationStatus` PO (hanya `dasWithout`, `dasGenerated`, `dasGeneratedbyAuthorizer`; PO lain ditahan dengan alasan di layar) dan status dicek ulang secara live sebelum POST (TD-01 selesai lokal; penyebab penolakan -5002 untuk PO `dasApproved` belum dijelaskan SAP, sehingga aturan dapat menyembunyikan PO valid).

## 6. Goods Issue

```text
Admin Operasional membuat GI
  → isi nomor FPK + tanggal FPK (hari ini atau H-1)
  → isi keluhan dan analisa
  → pilih item stok > 0 pada warehouse user
  → per line: tipe U_VTPI, Qty FPK, Qty Issue, KM Akhir
  → per line: Nopol opsional dan serial bila diperlukan
  → lampirkan foto FPK opsional
  → simpan lokal + workflow approval
  → Admin HO membuka Approval Goods Issue
  → approve/reject di portal
  → approval final tetap dapat dilakukan setelah hari H; tanggal FPK asli dipertahankan
  → jika approve: cek stok live SAP tepat sebelum POST per company/warehouse/item (Qty item berulang dijumlahkan; batas `max(0, InStock − Committed)`; GET gagal, item/warehouse hilang, terkunci, data tidak valid, atau stok kurang menghentikan POST dan dokumen tetap `PENDING_POST`; cache portal hanya untuk tampilan; SAP tetap otoritas akhir)
  → POST InventoryGenExits ke SAP
  → update stok cache dan audit
```

Remarks/Journal Memo dibentuk sebagai `Nomor FPK - Nopol - Nama Pool`.

Aturan tanggal FPK (keputusan rapat tim terbaru, dikonfirmasi user 2026-11-07; kode lokal sudah dikoreksi sesuai keputusan ini, belum UAT SAP atau deploy):

- tanggal FPK hanya boleh hari ini atau H-1 menurut timezone aplikasi (`APP_TIMEZONE`); H-2 dan tanggal masa depan ditolak oleh form dan server;
- `DocDate` (Posting Date) dan `TaxDate` (Document Date) SAP keduanya sama dengan tanggal FPK; `DocDueDate` tidak dikirim;
- batas hari ini/H-1 berlaku **pada saat Admin Operasional mengajukan GI**, bukan pada saat Admin HO menyetujui atau saat POST/retry;
- bila approval terjadi pada H+1 atau lebih lambat, GI tetap dikirim dengan `DocDate` dan `TaxDate` yang sama dengan tanggal FPK awal; tanggal tidak digeser otomatis;
- `POSTING_UNKNOWN` tidak di-retry sebelum hasil SAP direkonsiliasi; `SAP_ERROR` dapat dicoba ulang melalui jalur yang diotorisasi tanpa mengganti tanggal FPK;
- tidak ada lagi penolakan otomatis berdasarkan usia FPK pada approval final, sebelum POST, maupun pada retry; kode dan test penolakan lama (`FPK_DATE_EXPIRED`) sudah dihapus. Nomor FPK tidak dibatasi pemakaian ulangnya oleh aturan usia;
- bila SAP menolak karena periode posting tertutup, simpan error sebagai `SAP_ERROR` dan audit penolakan tanpa menggeser tanggal. Pemilik eskalasi periode tertutup belum diputuskan;
- GI yang sebelumnya telanjur ditandai `FPK_DATE_EXPIRED` perlu ditinjau per dokumen; jangan dibuka atau dikirim ulang otomatis.

## 7. Item Master dan reaktivasi item

- Halaman Item Master meminta user mencari terlebih dahulu.
- Lookup PR mencari cache lokal dan, saat ada keyword, melakukan targeted refresh ke SAP.
- Jika item yang sebelumnya inactive diaktifkan kembali di SAP, hasil pencarian terbaru akan mengubah cache menjadi aktif.
- Full sync tetap dijalankan harian untuk konsistensi keseluruhan.

## 8. Report Beban Sparepart

Report dibatasi ke warehouse user dan dapat difilter. Form PR memakai endpoint history untuk menyimpan snapshot terakhir saat dokumen dibuat, sehingga Admin HO tetap melihat konteks historis walaupun data SAP berubah kemudian.

## 9. User management

- Admin HO dapat membuat/mengubah Admin Operasional dan Manager Operasional.
- Super Admin dapat mengelola seluruh role.
- User dapat mengganti password sendiri.
- Edit user dapat mengubah nama, email, jabatan, role sesuai kewenangan, Pool, Warehouse, password opsional, dan status aktif.
- Department sengaja tidak diubah pada edit flow saat ini.
- Akun sendiri tidak boleh dinonaktifkan atau diubah rolenya.
- Super Admin aktif terakhir tidak boleh dinonaktifkan/didemote.

## 10. Ketika SAP disconnect

- PR/GI yang baru disubmit tetap tersimpan lokal dan menunggu approval.
- Bila posting setelah approval gagal pasti, status menjadi `SAP_ERROR`.
- Bila koneksi putus saat POST dan hasilnya tidak diketahui, status menjadi `POSTING_UNKNOWN`.
- Jangan klik submit/retry berulang pada `POSTING_UNKNOWN`.
- Periksa Integration Monitor dan SAP menggunakan transaction UUID/UDF sebelum recovery.
- GRPO adalah direct posting; intent command dicatat sebelum POST, tetapi status SAP tetap harus direkonsiliasi pada timeout ambigu.
