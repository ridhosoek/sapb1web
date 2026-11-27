# Runbook Setup, Deployment, dan Troubleshooting

## 1. Kebutuhan runtime

- PHP 8.3
- Composer
- Node.js/npm yang kompatibel dengan Vite 8
- MySQL
- Redis atau cache/database store yang sesuai environment
- SAP Business One Service Layer `/b1s/v2`
- SMTP untuk email approval
- Queue worker dan scheduler

## 2. Setup lokal

```bash
composer install
npm ci
copy .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

Untuk demo local/testing saja, seeder menyediakan akun demo sesuai `DatabaseSeeder`. Jangan menjalankan `migrate:fresh --seed` pada data yang harus dipertahankan.

## 3. Kelompok environment penting

### Aplikasi

- `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_TIMEZONE`
- koneksi `DB_*`
- `SESSION_*`, `QUEUE_CONNECTION`, `CACHE_STORE`, `REDIS_*`

### SAP

- `SAP_SERVICE_LAYER_URL`
- `SAP_COMPANY_DATABASES`
- fallback `SAP_USERNAME`, `SAP_PASSWORD`
- per database: `SAP_{DB}_USERNAME`, `SAP_{DB}_PASSWORD`
- `SAP_VERIFY_TLS`, timeout, session store/TTL
- UDF dan dimension mappings
- ready PO Series/lookback
- report SQL Query settings

### Email

- `MAIL_MAILER=smtp`
- `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`
- `MAIL_USERNAME`, `MAIL_PASSWORD`
- `MAIL_FROM_ADDRESS=no-reply@sap.indraangkola.com`

Jangan mencatat nilai password di dokumentasi, screenshot publik, issue, atau log.

## 4. Deployment

```bash
git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Setelah deploy, restart PHP-FPM/web process, queue worker, dan scheduler sesuai platform.

## 5. Worker dan scheduler

Jalankan queue worker menggunakan process manager. Jalankan scheduler setiap menit:

```bash
php artisan queue:work --tries=3 --timeout=120
php artisan schedule:run
```

Gunakan Supervisor/systemd di Linux. Jangan mengandalkan terminal interaktif untuk production.

## 6. Command operasional

Gunakan `php artisan list` dan `--help` untuk opsi aktual:

- `sap:items:sync`
- `sap:warehouse-stocks:sync`
- `sap:cost-centers:sync`
- `sap:purchase-progress:sync`
- `sap:post-approved`
- `control-center:sync`

Selalu berikan company DB yang benar pada command multi-database.

### 6.1 Atribusi pembuat GRPO Web SAP lama (TD-11)

GRPO web yang tersimpan sebelum kolom `sap_documents.web_created_by` ada tidak punya pemilik, sehingga tidak tampil di **Riwayat GRPO Web SAP** siapa pun dan fotonya tidak dapat dibuka. `sap:goods-receipts:attribute-creators` mengisi pemilik hanya bila seluruh bukti menunjuk satu pengguna yang sama: audit `goods_receipt_po.posted` untuk dokumen, company, dan DocEntry itu; pengirim pada command ber-UUID sama; dan audit `goods_receipt_po.posting_unknown` untuk command itu. Dokumen tetap tanpa pemilik bila buktinya tidak ada, menyebut lebih dari satu pengguna, atau command ber-UUID sama tidak ditemukan / berbeda company atau DocEntry. Pemilik PO, pengunggah foto, approver, dan petugas rekonsiliasi tidak pernah dipakai.

Perintah ini hanya membaca dan menulis database portal; tidak pernah memanggil SAP. Tanpa `--apply` ia tidak menulis apa pun.

**Langkah 1 — pratinjau dengan akun baca (`SELECT` saja, MySQL 8).** Hasilnya hanya angka per CompanyDB; tidak ada nama pengguna.

```sql
WITH grpo AS (
  SELECT d.id, d.company_db, d.sap_doc_entry, d.transaction_uuid,
         COALESCE(JSON_UNQUOTE(JSON_EXTRACT(d.normalized_data, '$.warehouse_code')),
                  JSON_UNQUOTE(JSON_EXTRACT(d.normalized_data, '$.lines[0].warehouse_code'))) AS warehouse_code
  FROM sap_documents d
  WHERE d.document_type = 'GOODS_RECEIPT_PO' AND d.created_from_web = 1 AND d.web_created_by IS NULL
),
cmd AS (
  SELECT g.id AS document_id, c.id AS command_id, c.requested_by,
         (c.id IS NOT NULL AND c.company_db = g.company_db AND c.command_type = 'CREATE_GOODS_RECEIPT_PO'
          AND c.sap_doc_entry IS NOT NULL AND c.sap_doc_entry = g.sap_doc_entry) AS is_consistent
  FROM grpo g LEFT JOIN sap_commands c ON c.transaction_uuid = g.transaction_uuid
),
candidates AS (
  SELECT g.id AS document_id, a.actor_id
  FROM grpo g
  JOIN audit_logs a ON a.auditable_type = 'App\\Models\\SapDocument' AND a.auditable_id = g.id
   AND a.event = 'goods_receipt_po.posted' AND a.actor_id IS NOT NULL
   AND JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.company_db')) = g.company_db
   AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.sap_doc_entry')) AS UNSIGNED) = g.sap_doc_entry
  UNION
  SELECT cmd.document_id, cmd.requested_by FROM cmd WHERE cmd.is_consistent = 1 AND cmd.requested_by IS NOT NULL
  UNION
  SELECT cmd.document_id, a.actor_id
  FROM cmd
  JOIN sap_commands c ON c.id = cmd.command_id AND cmd.is_consistent = 1
  JOIN audit_logs a ON a.event = 'goods_receipt_po.posting_unknown' AND a.actor_id IS NOT NULL
   AND JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.request_uuid')) = c.transaction_uuid
   AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.command_id')) AS UNSIGNED) = c.id
   AND JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.company_db')) = c.company_db
),
verdict AS (
  SELECT g.id, g.company_db, g.warehouse_code,
    CASE
      WHEN g.transaction_uuid IS NOT NULL AND COALESCE(MAX(cmd.is_consistent), 0) = 0 THEN 'inconsistent_command'
      WHEN COUNT(DISTINCT cand.actor_id) = 0 THEN 'no_evidence'
      WHEN COUNT(DISTINCT cand.actor_id) > 1 THEN 'multiple_senders'
      ELSE 'proven'
    END AS outcome,
    MIN(cand.actor_id) AS owner_id
  FROM grpo g
  LEFT JOIN cmd ON cmd.document_id = g.id
  LEFT JOIN candidates cand ON cand.document_id = g.id
  GROUP BY g.id, g.company_db, g.warehouse_code, g.transaction_uuid
)
SELECT v.company_db, v.outcome, COUNT(*) AS documents,
  COUNT(DISTINCT CASE WHEN v.outcome = 'proven' THEN v.owner_id END) AS distinct_senders,
  COALESCE(SUM(v.outcome = 'proven' AND u.is_active = 0), 0) AS sender_inactive,
  COALESCE(SUM(v.outcome = 'proven' AND u.role NOT IN ('ADMIN_HO', 'MANAGER_ADMIN_HO', 'ADMIN')
      AND (pr.warehouse_code IS NULL OR TRIM(pr.warehouse_code) = '' OR v.warehouse_code IS NULL
           OR UPPER(TRIM(pr.warehouse_code)) <> UPPER(TRIM(v.warehouse_code)))), 0) AS sender_warehouse_differs
FROM verdict v
LEFT JOIN users u ON u.id = v.owner_id AND v.outcome = 'proven'
LEFT JOIN user_operational_profiles pr ON pr.user_id = u.id
GROUP BY v.company_db, v.outcome
ORDER BY v.company_db, v.outcome;
```

Cara membaca: `proven` = aman diatribusikan; `multiple_senders`, `no_evidence`, `inconsistent_command` = tetap tersembunyi dan perlu keputusan manusia per dokumen. `sender_inactive` dan `sender_warehouse_differs` menghitung GRPO `proven` yang tetap **tidak** akan tampil di daftar pengirimnya setelah atribusi, karena akunnya nonaktif atau gudang profilnya sekarang berbeda dari gudang GRPO (daftar riwayat tetap dibatasi gudang untuk role non-HO).

**Langkah 2 — laporan perintah di server production (tanpa `--apply`).**

```bash
php artisan sap:goods-receipts:attribute-creators
```

Baris pertama harus menyebut database production yang dimaksud dan `read only.`. Angka per CompanyDB harus sama dengan Langkah 1. Simpan seluruh keluaran: baris `sap_documents.id can be attributed` adalah daftar baris yang akan berubah.

**Langkah 3 — otorisasi tulis (terpisah).** Product Owner menyetujui angka Langkah 1–2; yang menjalankan adalah operator dengan izin tulis production, bukan akun baca. Sebelum menulis, simpan cadangan dua daftar id berikut (atau `mysqldump` tabel `sap_documents` dan `sap_commands`):

```sql
SELECT id FROM sap_documents WHERE document_type = 'GOODS_RECEIPT_PO' AND created_from_web = 1 AND web_created_by IS NULL;
SELECT id FROM sap_commands WHERE command_type = 'CREATE_GOODS_RECEIPT_PO' AND requested_by IS NULL;
```

**Langkah 4 — tulis, satu CompanyDB per kali, mulai dari yang paling kecil.**

```bash
php artisan sap:goods-receipts:attribute-creators --company-db=DUMMY_TEST --apply
```

Ulangi untuk `IAL_DEV`, lalu `IA`, setelah hasil sebelumnya diperiksa. Jumlah `attributed` harus sama dengan `proven` pada pratinjau.

**Langkah 5 — verifikasi.** Jalankan lagi Langkah 2: `0 can be attributed`, dan sisa tanpa pemilik sama dengan jumlah tiga kategori yang tidak terbukti. Lalu UAT: (a) pengirim membuka **Riwayat GRPO Web SAP** pada company yang sama dan melihat GRPO-nya, detail, serta fotonya; (b) pengguna lain di gudang yang sama, Admin HO, dan Super Admin tidak melihatnya di daftar, dan URL detailnya memberi 404 (riwayat) atau 403 (halaman dokumen/foto); (c) GRPO yang tidak terbukti tetap tersembunyi bagi semua orang.

**Pemulihan.** Atribusi hanya mengisi kolom yang sebelumnya `NULL`, sehingga pemulihannya adalah mengosongkan kembali id pada cadangan Langkah 3 (dijalankan operator berwenang): `UPDATE sap_documents SET web_created_by = NULL WHERE id IN (...)` dan `UPDATE sap_commands SET requested_by = NULL WHERE id IN (...)`. GRPO yang dibuat setelah TD-11 tidak ada di cadangan itu dan tidak tersentuh.

## 7. Test dan quality gate

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
npm audit --audit-level=low
npm run build
git diff --check
```

Jalankan affected test lebih dahulu, lalu full suite sebelum push/deploy.

## 8. Troubleshooting

### Item baru aktif tidak muncul di PR

1. Cari dengan minimal dua karakter ItemCode/ItemName.
2. Targeted lookup akan membaca SAP dan memperbarui cache.
3. Jika masih tidak muncul, cek `Frozen=tNO`, `PurchaseItem=tYES`, database session, dan credential database.
4. Jalankan full item sync bila perlu.

### Item Goods Issue kosong

1. Pastikan user memiliki Warehouse yang benar.
2. Pastikan item inventory aktif.
3. Pastikan stok tersedia warehouse tersebut `> 0` dan warehouse tidak locked.
4. Jalankan stock sync dan cek Service Layer response.

### PO tidak muncul pada GRPO

Periksa:

- Series adalah 887 atau 888;
- DocDate dalam dua bulan;
- status PO open;
- minimal satu line warehouse sama dengan user;
- belum ada GRPO non-cancelled;
- credential/database session benar;
- cache sync interval dua menit.

### GRPO ditolak SAP

1. Baca pesan teknis SAP yang tampil.
2. Cek BaseEntry/BaseLine dan open quantity live.
3. Cek Warehouse line.
4. Untuk serial item, cek Item Master SAP dan jumlah/identifier serial.
5. Cek `sap_commands.request_payload` tanpa membocorkan data sensitif.
6. Jangan mengubah langsung database SAP.

### Email approval tidak terkirim

1. Cek SMTP `.env` dan clear config cache.
2. Pastikan queue worker hidup.
3. Periksa status email approval dan application log.
4. Pastikan approver aktif dan email valid.
5. Gunakan kirim ulang dari admin setelah penyebab diperbaiki.

### Halaman 500

- Catat Request ID dari error page.
- Cari Request ID pada application log.
- Periksa database, cache, queue, dan Service Layer.
- Jangan mengaktifkan `APP_DEBUG=true` pada production.

### 413 Request Entity Too Large

Goods Issue membatasi 3 foto × 1 MB, GRPO membatasi 5 foto × 1 MB per unggah (TD-10). Foto GRPO diunggah lewat request tersendiri setelah GRPO berhasil dibuat; 413 pada unggah foto tidak membuat atau mengulang GRPO.

Halaman 413 dibuat Nginx sebelum Laravel, jadi kode saja tidak dapat memperbaikinya. Batas total request harus cukup untuk 5 foto × 1 MB ditambah overhead multipart dan token form, tetapi tetap terbatas:

| Pengaturan | Lokasi | Nilai usulan |
| --- | --- | --- |
| `client_max_body_size` | blok `server` atau `location` Nginx situs ini | `6m` |
| `upload_max_filesize` | `php.ini` PHP-FPM | `2M` (per file; di atas 1 MB agar Laravel yang menolak dengan pesan jelas) |
| `post_max_size` | `php.ini` PHP-FPM | `8M` (harus ≥ `client_max_body_size`) |
| `max_file_uploads` | `php.ini` PHP-FPM | bawaan `20` sudah cukup |

Baca nilai efektif sebelum mengubah, lalu verifikasi sesudahnya:

1. Nginx: `nginx -T | grep client_max_body_size` (periksa juga include, proxy atau CDN di depan Nginx), lalu `nginx -t` dan reload.
2. PHP: baca nilai milik PHP-FPM (`php-fpm -i | grep -E "upload_max_filesize|post_max_size"`); `php -i` memakai php.ini CLI yang dapat berbeda. Reload PHP-FPM setelah mengubah.
3. Uji: unggah 5 foto masing-masing sekitar 1 MB pada GRPO di DUMMY_TEST; harus berhasil. Unggahan jauh di atas batas harus ditolak (browser lebih dulu, lalu Laravel). Kegagalan yang tersisa berupa halaman Nginx 413 atau 419 berarti batas server masih terlalu kecil.

Jangan menaikkan batas tanpa batas; nilai di atas sudah mencakup 5 × 1 MB plus overhead.

### `POSTING_UNKNOWN`

Hentikan retry manual. Cari apakah dokumen sudah terbentuk di SAP menggunakan web transaction UDF/UUID, lalu lakukan recovery terkontrol melalui Integration Monitor atau prosedur administrator.

### Purchase Request `POSTING_UNKNOWN` (TD-14)

Angka `#1003` di portal adalah id portal, bukan `DocNum` SAP. Hasil POST yang tidak pasti tidak pernah dikirim ulang. Rekonsiliasi hanya membaca SAP: bila tepat satu Purchase Request membawa UUID yang tersimpan pada payload, dan tanggal, item, jumlah, gudang, serta status batalnya cocok, portal mencatat DocEntry/DocNum dan menjadi `IN_SYNC`. Nol hasil, lebih dari satu, data berbeda, GET gagal, UDF tidak tersedia, atau UUID tidak ada pada payload: status tidak berubah.

**Status bisnis mengikuti `DocumentStatus` SAP:**

| `DocumentStatus` | Status dokumen portal | Progress | Watcher |
| --- | --- | --- | --- |
| `bost_Open` | `OPEN` | `WAITING_QUOTATION`, lalu disinkronkan polling progres yang sudah ada | `ACTIVE` |
| `bost_Close` | `CLOSED` | `CLOSED_IN_SAP`: tidak mengklaim PQ, PO, atau GRPO | `STOPPED`; tidak dipoll. Tombol **Sync SAP** di Purchase Progress tetap dapat dipakai |
| kosong atau nilai lain | tidak berubah, tetap tertahan | tidak berubah | tidak dibuat |

PR yang dibatalkan di SAP tetap memerlukan penanganan manual.

**Tiga cara menjalankan** (semuanya jalur yang sama):

- Halaman detail dokumen → **Periksa SAP & Rekonsiliasi** (Super Admin).
- `php artisan sap:purchase-requests:reconcile --company-db=IA --limit=5`. Command ini menulis database portal bila menemukan hasil yang cocok, dan melewati command yang belum jatuh tempo atau sudah mencapai batas percobaan.
- Scheduler tiap 10 menit per CompanyDB, **nonaktif** sampai `SAP_PR_RECONCILE_SCHEDULE_ENABLED=true`. Batas bawaan: 5 command per run, jeda 10 menit berlipat ganda sampai 6 jam, 8 percobaan per command, dan satu run berhenti pada kegagalan baca SAP pertama.

**Sebelum mengaktifkan**, per CompanyDB (`IA`, `IAL_DEV`, `DUMMY_TEST`):

1. `php artisan migrate --force` (menambah kolom `reconcile_*` pada `sap_commands`), lalu `php artisan config:cache`.
2. `php artisan sap:purchase-requests:reconcile --company-db=IA --check-field` harus menjawab `READY`. Metadata di-cache selama `SAP_METADATA_TTL_SECONDS`.
3. Buktikan di SAP bahwa satu PR baru dari portal menyimpan UUID pada `OPRQ.U_U_WebTxnId` dan dapat dibaca kembali.
4. Rekonsiliasi satu dokumen lewat tombol dan periksa hasilnya di SAP.
5. Baru setelah itu aktifkan scheduler. `SAP_PR_REQUIRE_WEB_TXN_ID_COMPANIES=IA` (opsional) menahan PR baru sebagai `PENDING_POST` bila field itu tidak dapat dikirim; isi hanya untuk CompanyDB yang sudah lolos langkah 2–3.

**Diagnosis baca saja — database portal** (ganti id sesuai kasus):

```sql
SELECT d.id, d.company_db, d.integration_status, d.transaction_uuid, d.sap_doc_entry, d.sap_doc_num,
       c.status AS command_status, c.attempts, c.last_attempt_at, c.last_error,
       JSON_UNQUOTE(JSON_EXTRACT(c.request_payload, '$.U_U_WebTxnId')) AS uuid_dalam_payload,
       JSON_UNQUOTE(JSON_EXTRACT(c.request_payload, '$.DocDate')) AS doc_date_payload,
       JSON_EXTRACT(c.request_payload, '$.DocumentLines') AS baris_payload
FROM sap_documents d
LEFT JOIN sap_commands c ON c.transaction_uuid = d.transaction_uuid AND c.company_db = d.company_db
WHERE d.id IN (1003, 1022, 827)
ORDER BY d.id;
```

**SAP HANA** — hanya setelah teks contoh diganti UUID asli dari query di atas; skema sesuai `company_db`:

```sql
SELECT "DocEntry", "DocNum", "U_U_WebTxnId", "CreateDate", "DocTime", "DocDate", "DocStatus", "CANCELED", "DataSource"
FROM "IA"."OPRQ"
WHERE "U_U_WebTxnId" IN ('<UUID_ASLI_1>', '<UUID_ASLI_2>', '<UUID_ASLI_3>')
ORDER BY "CreateDate", "DocTime";
```

Untuk menyusun query HANA langsung dari UUID yang terbukti ada pada payload (jalankan di database **portal**; hasilnya satu query per CompanyDB, dan id yang tidak muncul pada `id_portal_terverifikasi` tidak membawa UUID itu):

```sql
SELECT d.company_db,
       GROUP_CONCAT(d.id ORDER BY d.id) AS id_portal_terverifikasi,
       CONCAT('SELECT "DocEntry", "DocNum", "U_U_WebTxnId", "DocStatus", "CANCELED", "CreateDate", "DocTime", "DocDate" FROM "', d.company_db, '"."OPRQ" WHERE "U_U_WebTxnId" IN (', GROUP_CONCAT(CONCAT('''', d.transaction_uuid, '''') ORDER BY d.id), ');') AS query_hana
FROM sap_documents d
JOIN sap_commands c ON c.transaction_uuid = d.transaction_uuid AND c.company_db = d.company_db
WHERE d.id IN (827, 1003, 1022)
  AND JSON_UNQUOTE(JSON_EXTRACT(c.request_payload, '$.U_U_WebTxnId')) = d.transaction_uuid
GROUP BY d.company_db;
```

Detail Service Layer untuk setiap DocEntry yang ditemukan (GET saja): `PurchaseRequests(<DocEntry>)?$select=DocEntry,DocNum,DocDate,DocumentStatus,Cancelled,U_U_WebTxnId,DocumentLines`.

Bila UUID tidak ada pada payload atau tidak ditemukan, cari kandidat menurut `CreateDate`/`DocTime` (bukan `DocDate`, yang boleh mundur) serta seluruh item, jumlah, gudang, dan pengaju. Kemiripan bukan bukti identitas.

**Tautan manual untuk PR yang dikirim tanpa UUID.** Hanya Super Admin, hanya untuk dokumen dan command `POSTING_UNKNOWN` yang disetujui dan payload-nya tidak memuat UUID. Tidak pernah dijalankan scheduler atau command, tidak mengirim apa pun ke SAP, dan tidak memerlukan `UPDATE` manual.

1. Cari dokumennya di SAP dengan akun baca (query di bawah), catat `DocEntry` dan `DocNum`.
2. Buka dokumen portal pada CompanyDB yang sama → **Periksa untuk Ditautkan**. Portal membaca ulang `PurchaseRequests(<DocEntry>)` dan mencari semua PR yang dibuat sejak tanggal percobaan sampai 7 hari sesudahnya.
3. Tautan ditolak bila salah satu tidak cocok: DocEntry, DocNum, tidak dibatalkan, status buka/tutup, `DocDate`, seluruh baris item–qty–gudang beserta jumlah barisnya, komentar, atau waktu dibuat (`CreationDate` + `DocTime` antara 2 menit sebelum dan 10 menit sesudah percobaan portal). Ditolak juga bila DocEntry sudah dipakai, UDF berisi UUID lain, ada kandidat SAP atau dokumen portal lain yang sama kuatnya (pemetaan ambigu), SAP tidak dapat dibaca lengkap, atau command berubah.
4. Setiap kandidat lain dengan baris yang sama harus dicentang satu per satu dan alasan pilihan ditulis (minimal 20 karakter). SAP dibaca ulang saat disimpan.
5. Audit: `purchase_request.manually_linked` (bukti, hasil perbandingan, kandidat, alasan, actor, IP, waktu) atau `purchase_request.manual_link_rejected`.

Satu pemeriksaan membaca SAP minimal dua kali (detail dan pencarian, 100 baris per halaman), dan penyimpanan mengulanginya.

**UAT readback setelah operator berwenang menautkan.** Temuan pembacaan SAP IA 2026-11-27 yang harus diperiksa ulang oleh portal, bukan dianggap hasil: #827 → 27248 / 260002343 (kandidat 27278 / 260002353 berbaris sama tetapi komentar dan waktu berbeda; periksa dan tulis alasannya), #1003 → 27344 / 260002384, #1022 → 27356 / 260002388. Tautkan satu dokumen, lakukan readback, baru lanjut.

Database portal (`SELECT` saja):

```sql
SELECT d.id, d.company_db, d.integration_status, d.sap_business_status, d.sap_doc_entry, d.sap_doc_num,
       JSON_UNQUOTE(JSON_EXTRACT(d.normalized_data, '$.progress_status')) AS progress_status,
       c.status AS command_status, c.sap_doc_entry AS command_doc_entry, w.watch_status,
       a.event, a.actor_id, a.ip_address, a.created_at AS waktu_audit,
       JSON_UNQUOTE(JSON_EXTRACT(a.metadata, '$.reason')) AS alasan,
       JSON_EXTRACT(a.metadata, '$.reviewed_candidates') AS kandidat_dikonfirmasi
FROM sap_documents d
JOIN sap_commands c ON c.transaction_uuid = d.transaction_uuid AND c.company_db = d.company_db
LEFT JOIN sap_watchers w ON w.sap_document_id = d.id
LEFT JOIN audit_logs a ON a.auditable_id = d.id AND a.auditable_type = 'App\\Models\\SapDocument'
      AND a.event IN ('purchase_request.manually_linked', 'purchase_request.manual_link_rejected')
WHERE d.id IN (827, 1003, 1022)
ORDER BY d.id, a.created_at;
```

SAP HANA (`SELECT` saja):

```sql
SELECT "DocEntry", "DocNum", "DocDate", "CreateDate", "DocTime", "DocStatus", "CANCELED", "Comments"
FROM "IA"."OPRQ" WHERE "DocEntry" IN (27248, 27278, 27344, 27356) ORDER BY "DocEntry";

SELECT "DocEntry", "LineNum", "ItemCode", "Quantity", "WhsCode"
FROM "IA"."PRQ1" WHERE "DocEntry" IN (27248, 27278, 27344, 27356) ORDER BY "DocEntry", "LineNum";
```

Lulus bila: dokumen dan command `IN_SYNC` dengan DocEntry yang sama; status bisnis dan progres sesuai `DocStatus`; ada tepat satu audit `manually_linked`; nomor yang tampil di halaman dokumen sama dengan HANA; dan tidak ada PR baru di SAP maupun `POST /PurchaseRequests` di `sap_api_logs` sejak penautan.

**Korelasi dan beban Service Layer.** Sejak TD-14, POST, lookup, dan detail Purchase Request menulis `sap_api_logs.correlation_id` sama dengan `sap_commands.transaction_uuid`. Log yang lebih lama memakai id acak, sehingga hanya dapat dibandingkan menurut waktu dan itu bukan bukti satu transaksi. Login, percobaan pertama yang dijawab 401, dan pembacaan lewat semantic layer tidak tercatat di tabel ini.

```sql
-- Semua panggilan yang tercatat untuk satu transaksi
SELECT created_at, method, endpoint, response_status, duration_ms, result, error_message
FROM sap_api_logs WHERE correlation_id = '<UUID_ASLI>' ORDER BY created_at;

-- POST Purchase Request di sekitar waktu percobaan (zona waktu aplikasi)
SELECT created_at, response_status, duration_ms, result, error_message
FROM sap_api_logs
WHERE company_db = 'IA' AND method = 'POST' AND endpoint = '/PurchaseRequests'
  AND created_at BETWEEN '<last_attempt_at - 5 menit>' AND '<last_attempt_at + 5 menit>'
ORDER BY created_at;

-- Jumlah hit dan latensi per menit dan endpoint (MySQL 8)
SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS menit, method,
       REGEXP_REPLACE(endpoint, '[(][0-9]+[)]', '(n)') AS endpoint_norm,
       COUNT(*) AS hit, SUM(result = 'ERROR') AS gagal,
       ROUND(AVG(duration_ms)) AS rata_ms, MAX(duration_ms) AS maks_ms
FROM sap_api_logs
WHERE company_db = 'IA' AND created_at BETWEEN '<mulai>' AND '<selesai>'
GROUP BY menit, method, endpoint_norm
ORDER BY menit, hit DESC;
```
