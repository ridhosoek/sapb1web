# Prompt pertama untuk Claude Code — Paket 0 dan 1

Sebelum menempel prompt, pilih model `sonnet` dengan effort `high` di sesi Claude Code (`/model sonnet`, lalu `/effort high`; atau jalankan `claude --model sonnet --effort high`). Periksa pilihan yang benar-benar aktif di `/model` dan `/effort status`. Jika model atau level itu tidak tersedia pada akun/provider, gunakan pilihan terkuat yang tersedia dan laporkan nilainya. Teks prompt saja tidak mengubah pengaturan model atau effort.

Salin teks di bawah garis ke Claude Code pada workspace `D:\Ridho\Source Code\sapb1web`. Prompt ini **menggantikan versi awal** yang meminta semua temuan dikerjakan sekaligus. Paket berikutnya diberikan setelah hasil paket ini direview.

---

Anda membantu pengembangan SAP B1 Operational Web Portal sebagai technical delivery partner. Saya ingin seluruh backlog GRPO dan Goods Issue dikerjakan **bertahap** menurut `docs/agent-handoff/claude-code-delivery-plan.md`, tanpa asumsi tentang status SAP, stok, idempotency, atau keputusan bisnis.

**Pada giliran pertama ini kerjakan hanya Paket 0 dan Paket 1 (TD-03, isolasi workflow approval).** Jangan mulai paket 2–7 sebelum saya meminta lanjut.

1. Baca `AGENTS.md`, `docs/START-HERE.md`, dokumen terkait di `docs`, khususnya `docs/product/tech-debt.md`, `docs/product/business-process-register.md`, dan rencana delivery. Baca `.ai/rules/index.md` serta rule/skill yang relevan jika ada. Periksa versi package dan konvensi proyek sebelum memakai API Laravel. Laporkan model dan effort yang benar-benar aktif untuk sesi ini; jangan menganggap instruksi dalam prompt telah mengubah setting Claude Code.
2. Catat `git status --porcelain=v1 -uall`, branch, commit terakhir, dan file lokal user. Jangan reset, hapus, atau menimpa perubahan yang sudah ada.
3. Buat evidence ledger singkat: untuk TD-03, bedakan aturan bisnis, perilaku source, test yang ada, dan risiko company lain terpilih. Sertakan referensi file/baris. Jangan menyebut data SAP nyata sebagai terverifikasi bila hanya berasal dari fake test atau log portal.
4. Implementasikan TD-03 saja: `StartDocumentApproval` harus memilih workflow aktif dengan `company_db` yang **sama persis** dengan company dokumen, jenis dokumen, dan submitter. Jika tidak ada workflow atau step, hentikan sebelum membuat approval, mengirim email, atau posting. Tambahkan test bermakna untuk workflow company cocok, tidak ada workflow cocok tetapi company lain punya workflow, dan workflow tanpa step.
5. Jalankan test terfokus, Pint setelah perubahan PHP, dan `git diff --check`. Perbarui dokumentasi hanya bila diperlukan untuk mencerminkan perilaku yang benar-benar berubah.
6. Laporkan file dan baris yang berubah, hasil test, acceptance criteria `Lulus/Gagal/Belum diverifikasi`, serta perubahan lokal yang masih ada. **Berhenti setelah Paket 1** agar saya dapat mereview sebelum memberi paket berikutnya.

Batas: kode dan test lokal boleh diubah untuk Paket 1. Tidak boleh commit, push, deploy, mengubah database production, atau mengirim transaksi SAP production. Jika membaca MySQL production, hanya `SELECT`. Jangan menampilkan atau menyimpan secret. Jangan meminta keputusan user untuk hal yang dapat dibuktikan dari source/test; tanyakan hanya bila perubahan menyentuh kebijakan bisnis yang belum diputuskan.

---
