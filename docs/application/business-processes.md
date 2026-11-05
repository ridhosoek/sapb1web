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

- serial ditentukan oleh `ManageSerialNumbers=tYES` dan method `On Every Transaction`;
- jumlah serial sama dengan Qty Diterima;
- Qty item serial harus bulat;
- minimal Manufacturer Serial, Internal Serial, atau Lot Number terisi;
- field unik mengikuti Company Admin Info jika dapat dibaca;
- item BAN yang tidak dikonfigurasi serial di SAP harus diperbaiki pada Item Master SAP, bukan dipaksa lewat portal.

## 6. Goods Issue

```text
Admin Operasional membuat GI
  → isi FPK + tanggal hari ini
  → isi keluhan dan analisa
  → pilih item stok > 0 pada warehouse user
  → per line: tipe U_VTPI, Qty FPK, Qty Issue, KM Akhir
  → per line: Nopol opsional dan serial bila diperlukan
  → lampirkan foto FPK opsional
  → simpan lokal + workflow approval
  → Admin HO membuka Approval Goods Issue
  → approve/reject di portal
  → jika approve: cek stok ulang
  → POST InventoryGenExits ke SAP
  → update stok cache dan audit
```

Remarks/Journal Memo dibentuk sebagai `Nomor FPK - Nopol - Nama Pool`.

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
