# Handoff Context — SAP B1 Operational Web Portal

Dokumen ini menyimpan konteks keputusan dan implementasi untuk Co-Product Owner berikutnya. Mulai dari [`docs/START-HERE.md`](../START-HERE.md), lalu gunakan dokumen ini untuk memahami tujuan proyek, keputusan bisnis, arsitektur, integrasi SAP, fitur, konvensi, dan kondisi repository.

Terakhir diperbarui: 5 November 2026.

## 1. Tujuan proyek

Portal ini dibuat karena tim Admin Operasional harus bergantian memakai satu user SAP Business One akibat keterbatasan lisensi. Aplikasi web menjadi jalur operasional terkontrol untuk membuat dan memantau dokumen tanpa membagikan credential SAP kepada user lapangan.

Sasaran utama:

- Admin Operasional bekerja melalui portal menggunakan akun masing-masing.
- Database SAP dipilih saat login dan disimpan di session.
- Warehouse, Pool, Department, role, dan identitas user membatasi data yang dapat dilihat/dibuat.
- SAP Business One tetap menjadi system of record.
- Dokumen yang memerlukan approval tidak dikirim ke SAP sebelum approval final.
- Semua transaksi penting memiliki audit trail, status integrasi, dan identitas pembuat web (`U_UserWebSAP`).
- Daftar dan form input berada di halaman terpisah.

### Mandat agent/chat berikutnya

Agent berikutnya adalah **Co-Product Owner sekaligus technical delivery partner**. Ia bertanggung jawab menjaga outcome bisnis, requirement traceability, acceptance criteria, batas role/warehouse/database, integritas payload SAP, reliability, security, user experience, testing, dan dokumentasi.

Agent harus kritis berbasis evidence, bukan hanya menuruti request secara literal. Namun agent tidak memiliki kewenangan otomatis untuk transaksi SAP production, destructive action, deployment, commit, atau push.

Rujukan cara kerja: [`docs/product/co-product-owner-playbook.md`](../product/co-product-owner-playbook.md).

## 2. Kondisi repository saat handoff

- Repository: `https://github.com/ridhosoek/sapb1web.git`
- Branch aktif: `main`
- Baseline terakhir yang sudah dipush saat konteks diperbarui: `6e05b25` (`feat: refine SAP document flows and add handoff docs`). Status repository bersifat dinamis; selalu verifikasi dengan Git sebelum bekerja.
- Framework: Laravel 13, PHP 8.3, Livewire 4, Blade, Bootstrap 5, AdminLTE 4.
- QR: `endroid/qr-code` 5.1.
- Database aplikasi: MySQL.
- Integrasi SAP: SAP Business One Service Layer `/b1s/v2`, bukan write langsung ke database SAP.

Perubahan transaksi `U_VTPI`, line KM, item reactivation, perbaikan GRPO, test, dan dokumentasi dasar sudah termasuk pada commit tersebut. File lokal yang berubah setelah baseline harus dilihat dengan `git status --short`. Jangan memakai `git reset --hard` atau membuang file user.

## 3. Keputusan bisnis yang sudah disepakati

### 3.1 Database dan login

- User memilih database SAP pada halaman login.
- Daftar database berasal dari `SAP_COMPANY_DATABASES`.
- Credential dapat berbeda per database menggunakan pola `SAP_{NAMA_DB}_USERNAME` dan `SAP_{NAMA_DB}_PASSWORD`.
- Jangan hardcode database perusahaan di controller atau view.
- Session browser tidak pernah menerima password SAP atau session cookie SAP.

Database yang pernah dipakai selama pengembangan: `DUMMY_TEST`, `IA`, dan `IAL_DEV`. Daftar aktif harus mengikuti `.env` deployment; jangan menyalin secret ke dokumentasi atau Git.

### 3.2 Konteks user

Setiap user memiliki:

- role;
- jabatan asli (`job_title`) untuk cetakan;
- Warehouse;
- Pool (cost center dimensi 2);
- Department;
- status aktif/nonaktif.

Warehouse adalah batas utama visibilitas Admin Operasional dan Manager Operasional. Admin HO, Manager Admin HO, dan Super Admin dapat melihat lintas warehouse sesuai policy saat ini.

Nopol adalah cost center dimensi 3 dan sekarang berada di detail transaksi, bukan header. Nopol bersifat opsional karena satu dokumen dapat memuat beberapa kendaraan atau tidak membutuhkan kendaraan.

### 3.3 Role saat ini

| Role | Nilai enum | Kewenangan utama |
| --- | --- | --- |
| Admin Operasional | `POOL_USER` | Membuat PR, GRPO, dan Goods Issue; melihat data warehouse sendiri |
| Manager Operasional / Transport | `SUPERVISOR` | Melihat transaksi warehouse dan menjadi approver PR |
| Admin HO | `ADMIN_HO` | Melihat seluruh dokumen, check PR, approval Goods Issue, kelola user operasional |
| Manager Admin HO | `MANAGER_ADMIN_HO` | Monitoring seluruh transaksi dan dapat menjadi approver PR tingkat HO |
| Super Admin | `ADMIN` | Akses penuh, integrasi, konfigurasi approval, dan semua user |

Source of truth: `app/Domain/Shared/Enums/UserRole.php` dan `app/Policies/SapDocumentPolicy.php`.

### 3.4 Approval

- Workflow dipilih berdasarkan Admin Operasional pembuat dokumen, bukan berdasarkan Pool saja.
- Approver PR dapat lintas warehouse agar manager pengganti dapat menangani wilayah lain.
- PR memakai email approval dengan signed link yang berlaku 48 jam dan sekali proses.
- Approval bertingkat diproses berurutan.
- Setelah approval final PR, portal mengirim PR ke SAP.
- Goods Issue tidak memakai email; Admin HO/Super Admin melakukan approval di portal.
- Setelah approval final Goods Issue, stok diverifikasi ulang lalu Goods Issue dikirim ke SAP.
- Admin HO memiliki tombol `Check` pada detail PR. Check ini merekam siapa dan kapan pemeriksaan dilakukan untuk cetakan; check bukan pengganti approval manager.
- Cetakan memiliki Requested by, Checked by, Approved by, dan ruang kosong Verified by (Manager Admin HO).

### 3.5 Menu Payment Request

Kode prototype Payment Request tetap ada tetapi tidak ditampilkan di sidebar. Route dibatasi Super Admin dan penyimpanan ke UDO SAP belum diaktifkan. Jangan menganggap modul ini production-ready atau mengaktifkannya tanpa konfirmasi user.

## 4. Modul yang sudah dibangun

### Purchase Request

- Daftar dan halaman input terpisah.
- Item purchase aktif dari SAP; pencarian remote memperbarui item yang baru diaktifkan.
- Priority dikirim ke `U_VPRIOR` (`NORMAL`/`URGENT`).
- Department/Pool/Nopol dikirim sebagai cost center dimensi 1/2/3 pada baris.
- Nopol opsional dan per baris.
- KM akhir berada per baris dan dikirim ke `U_KM1`.
- UoM master ditampilkan dan user dapat memasukkan UoM manual.
- Histori pemakaian sparepart per item+nopol disimpan bersama snapshot PR.
- Pembuat web dikirim ke `U_UserWebSAP`.
- PR baru disimpan lokal dengan status menunggu approval; baru diposting setelah approval final.

### Purchase Progress

- Mengikuti relasi PR → Purchase Quotation → Purchase Order → GRPO dari data SAP.
- Tahap hanya ditandai selesai jika dokumen turunan benar-benar ditemukan di SAP.
- Sinkronisasi dapat manual dan terjadwal setiap dua menit.
- Watcher harus berhenti pada terminal state; jangan menebak progress dari status lokal saja.

### Goods Receipt PO

- Menarik PO SAP yang masih open, Series `887` atau `888`, maksimum dua bulan terakhir, sesuai warehouse user, dan belum memiliki GRPO.
- Qty diterima tidak boleh melebihi open quantity.
- GRPO dikirim ke entity `PurchaseDeliveryNotes` dengan referensi BaseType/BaseEntry/BaseLine PO.
- `U_UserWebSAP` diisi pada GRPO bila field tersedia di metadata SAP.
- Foto penerimaan disimpan privat di portal dan tidak dikirim ke attachment SAP.
- Item serial-managed menggunakan setup serial per unit; jumlah serial harus sama dengan Qty Diterima.
- Item group BAN yang belum diset `Manage Item by Serial Numbers` + `On Every Transaction` di SAP ditolak.
- Payload serial memakai koleksi `SerialNumbers`, bukan satu kolom teks pada document line.
- Jika konfigurasi Unique Serial Number tidak dapat dibaca, portal tidak langsung memblokir transaksi; minimal salah satu identifier serial wajib diisi dan validasi terakhir tetap dilakukan SAP.

### Goods Issue

- Hanya menampilkan item inventory dengan stok tersedia `> 0` pada warehouse user.
- Tanggal FPK harus hari ini.
- Tipe pengeluaran ada per baris, berasal dari daftar UDF `U_VTPI`, dan kode diposting ke `U_VTPI`.
- KM akhir berada per baris dan diposting ke `U_KM1`.
- Nopol opsional dan per baris.
- Qty issue tidak boleh melebihi Qty FPK atau stok tersedia.
- Tipe serial ban memerlukan jumlah serial sama dengan Qty Issue.
- Remarks dan Journal Memo: `Nomor FPK - Nopol - Nama Pool`.
- Lampiran FPK hanya JPG/JPEG/PNG, maksimum tiga file dan 1 MB per file, disimpan privat di portal.
- Approval dilakukan Admin HO di portal; stok dicek ulang sebelum POST SAP.
- Pembuat web dikirim ke `U_UserWebSAP`.

### Item Master

- Halaman tidak memuat seluruh master item sekaligus.
- User harus mencari ItemCode/ItemName, lalu membuka detail.
- Detail mengambil metadata item dan stok warehouse yang relevan.

### Report Beban Sparepart

- Menggantikan menu Monitoring Stok.
- Menampilkan histori beban dan pemakaian berdasarkan warehouse user.
- Digunakan juga dari form PR untuk melihat Goods Issue terakhir item terhadap nopol, nilai beban, dan lama pemakaian.
- Menggunakan query Service Layer/SQL Query yang dibatch dan cache; jangan mengembalikan semua warehouse untuk user operasional.

### Cetak dan QR verifikasi

- Cetakan PR mengikuti format internal perusahaan, A4 portrait, padat, bagian approval satu baris.
- Jabatan yang ditampilkan adalah `job_title`, bukan nama role aplikasi.
- QR hanya memuat signed verification URL, bukan seluruh data approval.
- Checked by dan Approved by memiliki QR.
- QR dibuat dengan `endroid/qr-code`, ECC Medium, tanpa logo di tengah agar mudah dipindai.
- Halaman verifikasi menampilkan nama, waktu, jabatan, dan informasi dokumen dari data server.

## 5. Mapping penting SAP

| Konteks | Field/Entity |
| --- | --- |
| Purchase Request | `PurchaseRequests` |
| Purchase Quotation | `PurchaseQuotations` |
| Purchase Order | `PurchaseOrders` |
| GRPO | `PurchaseDeliveryNotes` |
| Goods Issue | `InventoryGenExits` |
| Priority PR | `U_VPRIOR` |
| Pembuat Web | `U_UserWebSAP` |
| Tipe Pengeluaran GI | `U_VTPI` pada line |
| KM Akhir PR/GI | `U_KM1` pada line |
| Department | `CostingCode` / dimensi 1 |
| Pool | `CostingCode2` / dimensi 2 |
| Nopol | `CostingCode3` / dimensi 3 |
| Web transaction ID | `U_WebTxnId` bila tersedia |

Field harus tetap configurable melalui `config/sap.php` dan `.env`. Sebelum menambah UDF baru, cek `$metadata` target SAP.

## 6. Arsitektur dan prinsip integrasi

- Controller hanya mengatur HTTP/authorization dan memanggil application service.
- Aturan bisnis berada di `app/Application`.
- Detail SAP berada di `app/Integration/Sap`.
- Semua request SAP melewati `SapServiceLayerClient`.
- SAP session disimpan server-side per company DB dan login ulang hanya sekali setelah 401.
- `sap_commands` mencatat intent, payload, attempt, hasil, DocEntry, dan DocNum.
- `transaction_uuid` mencegah submit duplikat.
- Timeout POST menghasilkan `POSTING_UNKNOWN`; jangan langsung POST ulang sebelum rekonsiliasi.
- `sap_api_logs` dan `audit_logs` tidak boleh berisi password/token/session cookie.
- Attachment disimpan privat dan hanya diakses melalui controller yang mengotorisasi dokumen.

## 7. State dan status penting

- Integration: `PENDING_POST`, `POSTING`, `IN_SYNC`, `SAP_ERROR`, `POSTING_UNKNOWN`.
- Approval: `PENDING`, `APPROVED`, `REJECTED`.
- Watcher: aktif saat PR sudah berada di SAP dan menunggu dokumen lanjutan.
- `POSTING_UNKNOWN` adalah kondisi sensitif: hasil SAP belum pasti. Periksa Integration Monitor dan cari transaksi melalui UUID/UDF sebelum retry.

## 8. Scheduler dan worker

Scheduler saat ini:

- Post approved document: setiap dua menit.
- Sync purchase progress per database: setiap dua menit.
- Sync item SAP: harian pukul 01:00.
- Sync stok warehouse: setiap 15 menit.
- Sync cost center dimensi 1/2/3: harian.
- Refresh Control Center: per jam.

Production harus menjalankan queue worker dan scheduler secara terus-menerus.

## 9. Security yang sudah ada

- Laravel auth, CSRF, session regeneration, active-user check.
- Login rate limit lima percobaan.
- Authorization Gate/Policy berdasarkan role, database, dan warehouse.
- Signed+throttled approval link dan QR verification link.
- TLS verification configurable dan seharusnya aktif di production.
- Security headers: HSTS pada HTTPS, deny framing, nosniff, no-referrer, restricted permissions.
- Request ID untuk korelasi error.
- Halaman error ramah user; detail exception masuk log, bukan browser production.
- File upload dibatasi jenis, jumlah, dan ukuran.
- Secret hanya di `.env`, tidak boleh di Git atau dokumentasi.

Rekomendasi lanjutan yang belum dibuktikan selesai: 2FA untuk akun privileged, IP/VPN allowlist, secret manager, central log/SIEM, malware scanning upload, backup/restore drill, audit retention policy, dan vulnerability scan CI.

## 10. Riwayat keputusan UI

- List dan input tidak boleh digabung pada satu halaman.
- Semua list utama mempunyai filter pencarian.
- UI desktop-first, padat, tidak meniru SAP GUI secara literal.
- Field authoritative (Warehouse, Pool, Department, requester, tanggal sistem) ditampilkan read-only.
- Item dan Nopol memakai remote searchable combobox.
- Error dari SAP ditampilkan dalam bahasa operasional, tetapi pesan teknis SAP yang aman harus tersedia untuk troubleshooting.
- Logo perusahaan tampil pada login, sidebar, dan cetakan dengan ukuran eksplisit agar konsisten di production.
- Halaman 404/419/429/500/503 dan database-down menggunakan tampilan portal, bukan Laravel exception page.

## 11. Verifikasi terakhir

Sebelum dokumen handoff dibuat:

- Full suite: 203 test lulus, 1.078 assertions.
- Target GRPO/serial: 14 test lulus, 77 assertions.
- `vendor/bin/pint --dirty --format agent`: lulus.
- `php artisan view:cache`: lulus.
- `git diff --check`: lulus.

Catatan lingkungan Windows saat pengujian: command standar PHPUnit pernah salah mendeteksi readability file. Workaround yang berhasil adalah menjalankan binary PHPUnit dengan environment testing+SQLite in-memory. Ini masalah lingkungan agent, bukan kegagalan aplikasi.

## 12. Instruksi aman untuk agent berikutnya

1. Mulai dari `docs/START-HERE.md`, baca `AGENTS.md`, lalu cek `.ai/rules/index.md` jika folder `.ai/rules` ada.
2. Baca product charter, Co-Product Owner playbook, dan seluruh folder `docs/application` sebelum mengubah flow bisnis.
3. Jalankan `git status --short`; pertahankan perubahan lokal milik user.
4. Jangan mengubah dependency tanpa persetujuan user.
5. Jangan menulis langsung ke database SAP.
6. Jangan mengirim transaksi nyata ke SAP production hanya untuk testing tanpa instruksi eksplisit.
7. Gunakan fake HTTP pada automated test.
8. Untuk perubahan PHP, jalankan Pint dan affected tests.
9. Setelah migration/config berubah, deployment membutuhkan `php artisan migrate --force` dan `php artisan optimize:clear`.
10. Jangan push ke GitHub kecuali user meminta secara eksplisit.

## 13. Dokumentasi lanjutan

- [Indeks dokumentasi aplikasi](../application/README.md)
- [Arsitektur dan integrasi](../application/architecture.md)
- [Design system](../application/design-system.md)
- [Folder link tree](../application/folder-tree.md)
- [Bisnis proses](../application/business-processes.md)
- [Yang boleh dan tidak boleh dilakukan](../application/operational-rules.md)
- [Runbook deployment dan troubleshooting](../application/runbook.md)
- [Product charter](../product/product-charter.md)
- [Co-Product Owner playbook](../product/co-product-owner-playbook.md)
- [Prompt chat baru](new-chat-prompt.md)
