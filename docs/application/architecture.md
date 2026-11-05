# Arsitektur dan Integrasi

## 1. Arsitektur tingkat tinggi

```text
Browser
  │ HTTPS + Laravel session
  ▼
Laravel Web Portal
  ├── HTTP layer: Controller, FormRequest, Middleware, Policy
  ├── Application layer: workflow dan use case
  ├── Domain/model: status, role, dokumen, approval, audit
  ├── Integration layer: SAP Service Layer + Control Center
  └── Blade/Livewire UI
  │
  ├── MySQL: user, profile, workflow, cache, document snapshot, audit
  ├── Cache/Redis atau database: session SAP, locks, cache report
  ├── Queue worker: email approval
  └── Scheduler: polling dan sync master data
          │
          ▼
SAP Business One Service Layer /b1s/v2
```

Browser tidak pernah menerima credential SAP, `B1SESSION`, atau `ROUTEID`.

## 2. Lapisan aplikasi

| Lapisan | Lokasi | Tanggung jawab |
| --- | --- | --- |
| HTTP | `app/Http` | Validasi, middleware, authorization, response |
| Application | `app/Application` | Use case dan aturan transaksi |
| Domain | `app/Domain` | Enum role/status dan konsep domain |
| Model | `app/Models` | Persistensi MySQL dan relasi |
| SAP Integration | `app/Integration/Sap` | Session, gateway, payload, mapping, report |
| Control Center | `app/Integration/ControlCenter` | Sinkronisasi master operasional eksternal |
| UI | `resources/views`, `resources/css`, `resources/js` | Halaman, komponen, interaksi |

Controller tidak boleh membangun payload SAP sendiri. Payload harus melalui builder/gateway pada integration layer.

## 3. Data utama di MySQL portal

- `users`: identitas, role, jabatan asli, status.
- `user_operational_profiles`: Pool, Department, Warehouse.
- `control_pools`, `control_departments`, `control_vehicles`: cache konteks operasional.
- `sap_cost_center_caches`: cost center SAP per database/dimensi.
- `sap_items_cache`: cache Item Master per database.
- `sap_item_warehouse_stocks`: stok item per database+warehouse.
- `sap_documents`: snapshot dokumen portal/SAP.
- `sap_document_relations`: relasi PR/PQ/PO/GRPO.
- `sap_commands`: intent dan hasil integrasi idempotent.
- `sap_watchers`: polling purchase progress.
- `approval_workflows`, `approval_workflow_steps`: template approval per submitter.
- `document_approvals`, `document_approval_steps`: snapshot approval transaksi.
- `sap_document_attachments`: metadata file privat portal.
- `audit_logs`: audit tindakan user.
- `sap_api_logs`: telemetry request SAP yang aman.

## 4. Multi-database SAP

Database dipilih saat login dan disimpan dalam session `sap_company_db`. `SapCompanyContext` menjadi satu-satunya sumber database aktif untuk request tersebut.

Aturan:

- query cache/dokumen selalu diberi scope `company_db`;
- SAP session cache key dipisah per database;
- user tidak boleh mengirim nama database melalui form transaksi;
- credential per database dibaca dari environment;
- pergantian database membutuhkan login/session baru.

## 5. SAP Service Layer

Semua request melewati `SapServiceLayerClient`:

1. `SapSessionManager` memperoleh session server-side.
2. Request dikirim ke `/b1s/v2` dengan cookie SAP.
3. Respons 401 memicu lupa session dan satu kali re-login/retry.
4. Error SAP dipetakan menjadi exception bisnis.
5. Metadata aman dicatat ke `sap_api_logs`.

Endpoint utama:

- `PurchaseRequests`
- `PurchaseQuotations`
- `PurchaseOrders`
- `PurchaseDeliveryNotes`
- `InventoryGenExits`
- `Items`, `ItemGroups`, `Warehouses`, `ProfitCenters`
- `CompanyService_GetAdminInfo`

## 6. Reliability dan idempotency

- Setiap PR/GI memiliki `transaction_uuid`.
- Intent POST disimpan ke `sap_commands` sebelum request SAP.
- Submit ulang UUID yang sama tidak membuat dokumen baru.
- Lock mencegah dua proses memposting dokumen yang sama bersamaan.
- `SAP_ERROR` berarti SAP menolak secara pasti dan dapat diperbaiki.
- `POSTING_UNKNOWN` berarti hasil POST tidak diketahui akibat timeout/connection drop.
- Pada `POSTING_UNKNOWN`, lakukan rekonsiliasi dengan DocEntry/DocNum/UDF transaksi sebelum retry.

## 7. Authorization dan data scope

- Semua halaman transaksi dilindungi auth, session authentication, database selection, dan operational profile.
- Policy memastikan database dokumen sama dengan database session.
- Role non-HO melihat dokumen warehouse sendiri.
- Role HO/Super Admin dapat melihat lintas warehouse.
- Create hanya untuk Admin Operasional dan Super Admin.
- Goods Issue approval hanya Admin HO dan Super Admin.
- Workflow approval hanya Super Admin.

## 8. Security controls

- CSRF Laravel dan session regeneration.
- Password di-hash dan minimal 10 karakter dengan huruf besar/kecil+angka pada admin form.
- Login throttling dan throttling endpoint sensitif.
- Signed URL untuk email approval dan QR verification.
- Attachment private, tervalidasi MIME/extension/size.
- Security headers dan HSTS saat HTTPS.
- Secret hanya di environment.
- Error production tidak menampilkan stack trace.
- Request ID tersedia pada response/log untuk korelasi.

## 9. Integrasi report

Report Beban Sparepart memakai gateway dan SQL Query Service Layer yang dibatch+cache. Query harus selalu menerima warehouse user sebagai filter. Jangan menjalankan query besar lintas warehouse pada request user biasa.
