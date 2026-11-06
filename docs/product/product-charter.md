# Product Charter — SAP B1 Operational Web Portal

## 1. Product statement

SAP B1 Operational Web Portal adalah lapisan operasional terkontrol di atas SAP Business One untuk memungkinkan banyak user operasional bekerja menggunakan identitas masing-masing tanpa bergantian memakai satu user SAP akibat keterbatasan lisensi.

Portal menyederhanakan input, approval, dokumentasi, monitoring, dan audit, sementara SAP Business One tetap menjadi system of record transaksi perusahaan.

## 2. Masalah yang diselesaikan

- Admin Operasional bergantian menggunakan satu user SAP.
- Credential SAP berisiko dibagikan dan tindakan sulit ditelusuri per orang.
- Input SAP GUI kompleks untuk user operasional.
- Approval dan dokumen pendukung tersebar di media berbeda.
- Progress pembelian sulit dipantau tanpa membuka SAP.
- Kesalahan warehouse, cost center, item, serial, atau kuantitas dapat menghasilkan transaksi yang salah.
- Gangguan koneksi SAP berisiko menyebabkan duplicate posting jika retry tidak dikontrol.

## 3. Pengguna dan job-to-be-done

### Admin Operasional

Membuat PR, menerima barang melalui GRPO, dan mengajukan Goods Issue dengan data warehouse/cost center yang benar tanpa harus mengoperasikan SAP GUI.

### Manager Operasional / Transport

Memeriksa konteks kebutuhan dan memberikan keputusan approval PR secara cepat melalui email.

### Admin HO

Melihat data lintas warehouse, melakukan check PR, menyetujui Goods Issue, dan mengelola user operasional.

### Manager Admin HO

Memonitor transaksi lintas wilayah dan menjadi approver tingkat HO bila diperlukan.

### Super Admin

Menjaga konfigurasi, workflow, integrasi, user, dan recovery transaksi.

## 4. Outcome produk

- Setiap tindakan dapat ditelusuri ke user portal.
- Transaksi hanya masuk SAP setelah validasi dan approval yang sesuai.
- User hanya melihat/menggunakan data sesuai database, warehouse, dan role.
- Input portal menghasilkan payload SAP yang sesuai master data serta UDF perusahaan.
- Progress dokumen berasal dari dokumen SAP nyata, bukan asumsi.
- Gangguan SAP tidak menyebabkan blind retry atau duplicate document.
- User memperoleh pesan error yang dapat ditindaklanjuti tanpa melihat stack trace Laravel.

## 5. Prinsip produk

### SAP tetap sumber kebenaran

Portal boleh menyimpan cache dan snapshot, tetapi status transaksi SAP, stok, open quantity, item master, serial configuration, dan dokumen turunan harus diverifikasi terhadap SAP pada titik kritis.

### Context ditentukan sistem

Database dipilih saat login. Warehouse, Pool, dan Department berasal dari profil user. Field authoritative tidak boleh dipercaya dari input bebas browser.

### Detail mengikuti realitas lapangan

Data yang dapat berbeda per item—Nopol, KM akhir, tipe pengeluaran, serial—berada pada detail line, bukan dipaksakan pada header.

### Approval sebelum posting

PR dan Goods Issue disimpan lokal sebelum approval. Posting SAP baru terjadi setelah approval final. GRPO adalah direct transaction dengan validasi live PO.

### Evidence over assumption

Progress, stok, serial, item aktif, dan keberhasilan POST harus didasarkan pada evidence dari SAP/log/test, bukan pesan UI atau dugaan.

### Sederhana untuk user, ketat di server

UI hanya meminta data yang benar-benar diperlukan. Server tetap melakukan authorization, validation, idempotency, stock/open-quantity check, dan audit.

## 6. Scope produk saat ini

### Production scope

- Login multi-database SAP.
- User, role, Warehouse, Pool, Department, dan jabatan.
- Purchase Request dan approval bertingkat melalui email.
- Admin HO Check untuk cetakan PR.
- Purchase Progress PR → PQ → PO → GRPO.
- Goods Receipt PO dengan dokumentasi foto portal dan setup serial.
- Goods Issue dengan approval internal Admin HO.
- Item Master search/detail.
- Report Beban Sparepart.
- Cetakan dan QR verification.
- Integration monitor, audit, error pages, scheduler, dan queue.

### Bukan production scope

- Payment Request masih prototype dan tidak boleh dianggap siap transaksi SAP.
- Portal bukan pengganti seluruh modul SAP Business One.
- Portal tidak melakukan direct write ke database SAP.
- Portal tidak mengelola master item/serial configuration; perubahan master dilakukan di SAP.

## 7. Prioritas produk saat ini

1. Kebenaran payload PR, GRPO, dan Goods Issue pada seluruh database target.
2. Stabilitas GRPO serial-managed dan error yang dapat ditindaklanjuti.
3. Sinkronisasi master data aktif, warehouse stock, cost center, dan purchase progress.
4. Approval dan visibility lintas role/warehouse yang sesuai kebijakan.
5. Recovery aman untuk SAP disconnect dan `POSTING_UNKNOWN`.
6. Performa report/query sesuai warehouse user.
7. Konsistensi UI, cetakan, dokumentasi, dan onboarding.

## 8. Non-goals

- Meniru seluruh SAP GUI.
- Menambahkan field hanya karena tersedia di SAP jika tidak dibutuhkan user.
- Menampilkan seluruh data lintas warehouse kepada user operasional.
- Mengorbankan validasi server demi mengurangi langkah input.
- Retry otomatis tanpa idempotency dan rekonsiliasi.
- Menyimpan credential SAP pada browser atau source control.

## 9. Product success indicators

Indikator berikut harus dipantau; target numeriknya perlu disepakati dengan pemilik produk:

- persentase transaksi portal yang berhasil sinkron ke SAP;
- jumlah `SAP_ERROR` per jenis dokumen dan penyebab;
- jumlah serta usia `POSTING_UNKNOWN`;
- waktu dari submit ke approval final;
- waktu dari approval ke SAP posting;
- duplicate document rate;
- jumlah transaksi yang salah warehouse/cost center/serial;
- adoption per warehouse dan user aktif;
- waktu penyelesaian PR/GRPO/GI dibanding proses SAP GUI sebelumnya;
- jumlah error berulang setelah perbaikan/UAT.

## 10. Product governance

- User pemilik proyek adalah pengambil keputusan akhir untuk kebijakan bisnis dan scope.
- Co-Product Owner agent menjaga konsistensi requirement, evidence, acceptance criteria, dan dampak teknis.
- Perubahan mapping SAP harus dikonfirmasi melalui metadata/response nyata.
- Perubahan role/approval harus memperbarui policy, UI, tests, dan dokumentasi.
- Keputusan yang mengubah bisnis proses harus dicatat pada context/product documentation dalam commit yang sama.
