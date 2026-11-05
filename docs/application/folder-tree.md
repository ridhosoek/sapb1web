# Folder Link Tree

Tree ini menunjukkan bagian penting repository dan link menuju source code aktual.

```text
sapb1web/
├── app/
│   ├── Application/        use case dan alur bisnis
│   ├── Domain/             enum dan aturan domain
│   ├── Http/               controller, request, middleware
│   ├── Integration/        SAP dan Control Center adapter
│   ├── Jobs/               pekerjaan queue
│   ├── Mail/               email approval
│   ├── Models/             model MySQL
│   ├── Policies/           authorization dokumen
│   └── Providers/          binding dan Gate
├── bootstrap/              bootstrap middleware/error handling
├── config/                 konfigurasi aplikasi dan SAP
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── docs/                   dokumentasi proyek
├── public/                 asset publik
├── resources/
│   ├── css/                design system
│   ├── js/                 interaksi frontend
│   └── views/              Blade UI, email, print, errors
├── routes/                 web dan scheduler
└── tests/                  feature/unit regression tests
```

## Link utama

### Business use cases

- [Approval](../../app/Application/Approvals)
- [Purchase Request dan purchase progress](../../app/Application/Purchasing)
- [Goods Receipt PO](../../app/Application/Receiving)
- [Goods Issue dan stock](../../app/Application/Inventory)
- [Item synchronization](../../app/Application/Items)
- [Cost center synchronization](../../app/Application/CostCenters)
- [Dashboard](../../app/Application/Dashboard)

### Integrasi

- [SAP integration root](../../app/Integration/Sap)
- [SAP document gateways dan payloads](../../app/Integration/Sap/Documents)
- [SAP master data gateways](../../app/Integration/Sap/MasterData)
- [SAP inventory](../../app/Integration/Sap/Inventory)
- [SAP reports](../../app/Integration/Sap/Reports)
- [SAP field/cost-center mapping](../../app/Integration/Sap/Mapping)
- [Control Center](../../app/Integration/ControlCenter)
- [SAP configuration](../../config/sap.php)

### HTTP dan authorization

- [Controllers](../../app/Http/Controllers)
- [Form requests](../../app/Http/Requests)
- [Middleware](../../app/Http/Middleware)
- [Document policy](../../app/Policies/SapDocumentPolicy.php)
- [Role enum](../../app/Domain/Shared/Enums/UserRole.php)
- [Web routes](../../routes/web.php)
- [Scheduler](../../routes/console.php)
- [Bootstrap/error renderer](../../bootstrap/app.php)

### Data

- [Models](../../app/Models)
- [Migrations](../../database/migrations)
- [Seeders](../../database/seeders)
- [Factories](../../database/factories)

### UI

- [Application layout](../../resources/views/layouts/app.blade.php)
- [Design system CSS](../../resources/css/app.css)
- [Frontend JavaScript](../../resources/js/app.js)
- [Authentication views](../../resources/views/auth)
- [Operational views](../../resources/views/operations)
- [Admin views](../../resources/views/admin)
- [Approval view](../../resources/views/approvals)
- [Email approval](../../resources/views/mail/approvals/requested.blade.php)
- [Error pages](../../resources/views/errors)
- [Print template](../../resources/views/operations/documents/print.blade.php)

### Tests

- [Feature tests](../../tests/Feature)
- [Unit tests](../../tests/Unit)

## Pola penempatan file baru

- Use case baru: `app/Application/{Domain}`.
- Endpoint HTTP: controller tipis di `app/Http/Controllers`.
- Validasi input: FormRequest di `app/Http/Requests`.
- SAP entity/payload: `app/Integration/Sap/Documents`.
- SAP master data: `app/Integration/Sap/MasterData`.
- View: kelompokkan sesuai menu pada `resources/views`.
- Regression behavior: utamakan Feature test pada `tests/Feature`.

Jangan membuat folder base baru tanpa persetujuan karena struktur modular monolith ini sudah menjadi konvensi proyek.
