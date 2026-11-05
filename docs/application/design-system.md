# Design System

## 1. Prinsip desain

- Desktop-first untuk pekerjaan operasional berbasis tabel.
- Padat namun tetap terbaca; hindari ruang kosong berlebihan.
- List dan form input selalu halaman terpisah.
- SAP GUI hanya referensi bisnis, bukan template visual yang harus ditiru.
- Data authoritative ditandai read-only/locked.
- Status, error, loading, empty state, dan retry harus eksplisit.
- Aksi utama berada pada sticky action bar untuk form panjang.

Source of truth CSS: [`resources/css/app.css`](../../resources/css/app.css).

## 2. Teknologi visual

- Bootstrap 5.3
- AdminLTE 4
- Font Awesome 7
- Inter untuk UI
- JetBrains Mono untuk kode item, nomor dokumen, nilai teknis
- Blade + JavaScript ringan; Livewire dipakai pada bagian yang membutuhkan state server-side

## 3. Design tokens

| Token | Nilai | Pemakaian |
| --- | --- | --- |
| `--portal-navy` | `#17324d` | identitas utama |
| `--portal-navy-strong` | `#10263a` | sidebar dan heading kuat |
| `--portal-accent` | `#2d6a8f` | tombol utama, link aktif, highlight |
| `--portal-border` | `#d7dee5` | garis tabel, panel, field |
| `--portal-surface` | `#f4f6f8` | background aplikasi |
| `--portal-muted` | `#667788` | teks sekunder dan helper |
| `--portal-sidebar-width` | `248px` | lebar sidebar desktop |

Warna status:

- success: `#24804f`
- error: `#bd3434`
- warning: `#d49b22`
- muted: `#9aa7b2`

## 4. Tipografi

- Body: Inter 14px.
- Page title: 22px, weight 600.
- Panel title: 16px, weight 600.
- Section heading: 14px, weight 650.
- Table: 13px; header 0.71rem uppercase.
- Kode/nomor: JetBrains Mono 11–12px.
- Label field: 13px, weight 500.

## 5. Struktur halaman

```text
Header
  ├── tombol sidebar
  ├── Warehouse · Department
  ├── nama · role · database
  ├── ganti password
  └── logout
Sidebar
  ├── Operasional
  └── Administrasi (berdasarkan role)
Content
  ├── page title + subtitle + page actions
  ├── alert/preview notice
  ├── filter atau transaction sections
  └── sticky action bar
Footer
```

## 6. Komponen baku

### Panel

Gunakan `.operational-panel`, `.panel-header`, `.panel-title`, dan `.panel-body`. Border radius 6px dan shadow sangat ringan.

### Section transaksi

Gunakan `.transaction-section`, `.section-heading`, dan `.transaction-body`. Header section memberi pemisahan antara data permintaan, konteks SAP, detail item, dan attachment.

### Tabel

Gunakan `.table-operational`. Kolom kode memakai `.mono`. Tabel detail lebar dibungkus `.table-responsive`; jangan mengecilkan kolom sampai informasi terpotong tanpa jalan melihat detail.

### Locked/read-only value

Gunakan `.locked-field` atau `.readonly-value`. Jangan hanya men-disable field tanpa menjelaskan bahwa nilainya berasal dari profil/SAP.

### Remote combobox

Item dan Nopol memakai combobox searchable. Nama item harus dapat wrap dan tampil penuh. Query dilakukan setelah user mengetik agar Item Master besar tidak dimuat sekaligus.

### Alerts dan state

- info: aturan/sumber data;
- success: proses selesai;
- warning: data cache/fallback digunakan;
- danger: validasi atau penolakan SAP;
- empty state: jelaskan mengapa kosong dan tindakan berikutnya;
- loading: nonaktifkan submit untuk mencegah double submit.

### Filter list

Semua daftar utama menggunakan pola Cari Data + filter status yang relevan + tombol Cari + Reset. Filter harus dipertahankan pada pagination.

## 7. Logo

- File utama: `public/images/logo-indra-angkola.jpg`.
- Sidebar: 52×38px, `object-fit: contain`.
- Login: 100×72px, `object-fit: contain`.
- Selalu tetapkan `width`, `height`, `max-width`, dan `max-height` agar deployment tidak memperbesar gambar mengikuti dimensi asli.

## 8. Cetakan

- A4 portrait.
- Konten utama ditargetkan cukup pada setengah sampai satu halaman jika jumlah item wajar.
- Approval cards satu baris: Requested, Checked, Approved, Verified.
- QR Checked dan Approved menggunakan signed URL dan harus cukup besar untuk pemindaian.
- Jangan memasukkan gambar signature user.
- Verified by Manager Admin HO sengaja kosong untuk tanda tangan basah.
- Nama perusahaan harus persis `PT INDRA ANGKOLA`.
- Jabatan memakai `job_title`, bukan role aplikasi.

## 9. Accessibility dan responsif

- Semua tombol ikon memiliki `aria-label`.
- Kontras teks tidak boleh bergantung pada warna saja.
- Focus state harus tetap terlihat.
- Pada mobile, layout boleh bertumpuk, tetapi data transaksi tidak boleh hilang; tabel tetap dapat horizontal scroll.
