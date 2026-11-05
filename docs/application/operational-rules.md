# Aturan Operasional: Boleh dan Tidak Boleh

## 1. Yang boleh dilakukan user

### Admin Operasional

- Membuat PR, GRPO, dan Goods Issue untuk warehouse yang dipetakan.
- Melihat dokumen warehouse sendiri.
- Mencari item dan Nopol dari master data SAP.
- Melampirkan foto sesuai aturan ukuran/format.
- Mencetak dokumen yang dapat dilihat.
- Mengganti password akun sendiri.

### Manager Operasional / Transport

- Melihat transaksi warehouse yang diizinkan policy.
- Menyetujui/menolak PR melalui link approval yang sah.
- Memberi alasan saat menolak.

### Admin HO

- Melihat dokumen lintas warehouse.
- Check PR untuk kebutuhan cetakan.
- Menyetujui/menolak Goods Issue melalui portal.
- Membuat dan mengubah user operasional sesuai batas role.

### Manager Admin HO

- Memonitor seluruh transaksi.
- Menjadi approver PR bila dipilih pada workflow.

### Super Admin

- Mengelola seluruh user dan workflow.
- Melihat Integration Monitor dan konfigurasi sistem.
- Menangani recovery transaksi setelah verifikasi.

## 2. Yang tidak boleh dilakukan user

- Membagikan password portal atau credential SAP.
- Menggunakan akun user lain untuk approval.
- Memilih Warehouse/Pool/Department di luar profil hanya untuk melewati pembatasan.
- Mengisi Qty melebihi open PO, Qty FPK, atau stok SAP.
- Menggunakan nomor serial yang sama untuk lebih dari satu unit.
- Mengunggah PDF sebagai bukti FPK Goods Issue; hanya foto yang diizinkan.
- Menekan submit/retry berulang ketika transaksi berstatus `POSTING` atau `POSTING_UNKNOWN`.
- Menganggap foto portal sebagai attachment SAP; foto memang hanya untuk dokumentasi portal.
- Menganggap check Admin HO sama dengan approval manager.

## 3. Yang boleh dilakukan developer/administrator

- Menambah database melalui environment dan credential per database.
- Mengubah mapping UDF melalui config/environment setelah verifikasi `$metadata`.
- Menjalankan sync item, stok, cost center, dan progress menggunakan command yang tersedia.
- Memeriksa payload aman di `sap_commands` dan error di Integration Monitor.
- Menambah regression test untuk setiap bug integrasi yang diperbaiki.
- Menggunakan HTTP fake pada automated test.

## 4. Yang tidak boleh dilakukan developer/administrator

- Menulis langsung ke tabel transaksi database SAP.
- Menyimpan password, bearer token, `B1SESSION`, atau `.env` ke Git.
- Mengirim transaksi uji ke SAP production tanpa persetujuan eksplisit.
- Menghapus/mengulang dokumen `POSTING_UNKNOWN` sebelum rekonsiliasi.
- Hardcode nama database, Warehouse, Pool, Department, Account Code, atau UDF di controller/view.
- Membuat controller memanggil SAP tanpa application service/gateway.
- Menghapus audit log atau command history untuk “membersihkan” status.
- Mengaktifkan Payment Request sebagai production module tanpa menyelesaikan persistensi/approval/integration test.
- Mengubah dependency tanpa persetujuan user.
- Mem-push ke GitHub bila user belum meminta.

## 5. Aturan dokumen

### Purchase Request

- Posting ke SAP hanya setelah approval final.
- `U_VPRIOR`, `U_UserWebSAP`, cost centers, dan line KM harus tetap dipetakan.
- Nopol opsional dan per baris.
- History item+nopol harus disnapshot saat submit.

### GRPO

- Hanya PO open Series 887/888, dua bulan terakhir, warehouse user, dan tanpa GRPO.
- Serial-managed item wajib setup serial sesuai Qty.
- Foto tidak dikirim ke SAP.
- PO/open quantity harus dibaca ulang sebelum posting.

### Goods Issue

- Approval hanya di portal oleh Admin HO/Super Admin.
- Stok wajib dicek saat submit dan tepat sebelum posting.
- Item stok nol tidak ditampilkan dan tidak dapat dipilih.
- Tipe pengeluaran `U_VTPI`, KM, dan Nopol berada per baris.
- Remarks mengikuti format bisnis yang disepakati.

## 6. Aturan deployment

- `APP_DEBUG=false` di production.
- HTTPS wajib; `SAP_VERIFY_TLS=true` kecuali lingkungan uji terisolasi.
- Queue worker dan scheduler wajib hidup.
- Jalankan migration sebelum membuka traffic ke versi baru.
- Jalankan `php artisan optimize:clear` setelah perubahan environment/config.
- Jalankan `npm ci`, `npm audit`, dan `npm run build`; jangan deploy bila ada vulnerability high/critical yang belum dievaluasi.
- Backup database portal sebelum migration material.
