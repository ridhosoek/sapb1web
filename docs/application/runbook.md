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

Goods Issue sudah membatasi foto 1 MB, tetapi web server harus mengizinkan request sedikit di atas total maksimum form. Sesuaikan `client_max_body_size` Nginx secukupnya; jangan menaikkannya tanpa batas.

### `POSTING_UNKNOWN`

Hentikan retry manual. Cari apakah dokumen sudah terbentuk di SAP menggunakan web transaction UDF/UUID, lalu lakukan recovery terkontrol melalui Integration Monitor atau prosedur administrator.
