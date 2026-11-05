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
