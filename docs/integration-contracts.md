# Integration Contracts

## SAP Business One Service Layer

Transaction and standard entity calls pass through `SapServiceLayerClient`. Serial-stock reads use `SapSemanticLayerClient` and a separate IT01 session. Controllers and Livewire components must call an application service or a document gateway; they must not call SAP directly.

The client uses `/b1s/v2`, reuses a server-side session through Redis, and retries an authenticated request only once after an HTTP 401. It does not rely on ETag, webhook, SQLQueries, or direct database access.

SAP entity endpoints and environment-specific field names live in `config/sap.php`. Before enabling a transaction module, verify every configured field against the target environment's `$metadata` and an actual response. Unknown fields must remain encapsulated in the mapping layer.

Every SAP POST in later phases must first persist a `sap_commands` row with a unique `transaction_uuid`. If a timeout makes the result ambiguous, set `POSTING_UNKNOWN`, search SAP by the configured web transaction UDF, and recover `DocEntry` and `DocNum` before considering a retry.

### Sumber serial Goods Issue (BP-12 / TD-13) — kontrak untuk tim SAP

Fakta terverifikasi pada IA (GET, 2026-11-13): `Items` memuat `ManageSerialNumbers` dan `SRIAndBatchManageMethod`; `SerialNumberDetails` memuat identitas serial (`DocEntry` = AbsEntry, `SystemNumber`, `MfrSerialNo`, `SerialNumber`, `LotNumber`, `AdmissionDate`) tetapi **tidak** warehouse, kuantitas, maupun status; `SQLQueries` tidak tersedia (v1 maupun v2). Karena itu portal tidak dapat membaca "serial yang tersedia di warehouse X" dari entitas standar.

Tim SAP menyediakan semantic view `SERIALNUMBERBANQUERY` di `/b1s/v1/sml.svc`. Portal membaca view ini melalui sesi khusus IT01 yang dipisahkan per CompanyDB dari sesi transaksi. Kredensial diatur lewat `SAP_GI_SERIAL_VIEW_USERNAME/PASSWORD`, dengan override per CompanyDB jika diperlukan. View harus menghasilkan satu baris per serial per warehouse, dibatasi oleh CompanyDB sesi, yang setara OSRQ digabung OSRN:

| Field (default `config/sap.php`) | Isi | Aturan |
| --- | --- | --- |
| `ItemCode` | kode item | filter `eq` |
| `WhsCode` | kode warehouse | filter `eq` |
| `SysNumber` | System Number serial (identitas stabil yang dikirim saat POST) | wajib, numerik, filter `eq`/`or` |
| `AbsEntry` | AbsEntry OSRN | opsional, untuk audit |
| `DistNumber` | Serial internal | salah satu dari `DistNumber`/`MnfSerial` wajib terisi |
| `MnfSerial` | Serial pabrikan | idem |
| `Quantity` | kuantitas di warehouse | filter `gt 0`; baris tanpa stok tidak ditawarkan |

Portal membaca dengan `$filter` (`ItemCode eq … and WhsCode eq … and Quantity gt 0`; pencarian memakai `contains` pada nomor internal/pabrikan; pemeriksaan ulang memakai `SysNumber eq 'n' or …`), `$select`, `$orderby`, `$top`, dan `$skip` bila SAP memberi `@odata.nextLink`. Respons IA yang diberikan tim SAP memiliki `ItemCode`, `WhsCode`, `SysNumber`, `AbsEntry`, `DistNumber`, `MnfSerial`, dan `Quantity`. SAP membuktikan `SysNumber` bertipe `Edm.String`: filter angka tanpa tanda petik ditolak dengan error 201; `AbsEntry` juga dikirim sebagai string JSON. GET IA dengan `contains(DistNumber,'202006')` berhasil mengembalikan baris, dan filter `ItemCode eq 'I00150' and WhsCode eq 'CMR-01' and Quantity gt 0 and SysNumber eq '1'` menghasilkan HTTP 200 dengan satu baris. GET IAL_DEV berhasil dengan `value: []`; pemilik sistem mengonfirmasi belum ada stok serial positif di sana. Baris tanpa `SysNumber`, tanpa teks serial, atau tanpa stok tidak pernah ditawarkan; jumlah yang tidak lengkap ditampilkan agar tim SAP melengkapinya. Nama field dapat diubah lewat `SAP_GI_SERIAL_FIELD_*`. Fitur dihidupkan dengan `SAP_GI_SERIAL_SELECTION_ENABLED=true` hanya setelah filter/paging view terbukti pada IA dan POST GI serial lulus UAT; IAL_DEV perlu UAT ketika ada stok serial. Selama mati, alur lama (textarea serial dan aturan BAN-MT) tetap berlaku.

Hal yang **belum terbukti** dan harus dibuktikan pada UAT SAP: bentuk `SerialNumbers` pada POST `InventoryGenExits` (portal mengirim `SystemSerialNumber` + `Quantity 1`), perilaku metode `On Every Transaction` dan `On Release Only`, dan apakah koleksi dari tim SAP selalu sinkron dengan stok serial nyata saat pemeriksaan ulang sebelum POST.

## Control Center

The application depends on `ControlCenterGateway`, not a controller-specific HTTP call. The HTTP adapter expects collection payloads under `data`, `value`, or the top-level array and supports common source field aliases only inside the synchronization service.

The following fields must be confirmed with the real Control Center API before production synchronization:

- Pool: external ID, code, name, active state.
- Department: external ID, code, name, active state, optional Pool code.
- Vehicle: external ID, license plate, description, last known KM, active state.
- User context: user identity mapped to Pool and Department.

Frontend requests never select or submit an authoritative Pool or Department. Vehicle results are filtered using `auth()->user()->operationalProfile->pool_id` on the server.

## Secrets and logging

SAP and Control Center credentials exist only in environment secrets. API logs store endpoint, response status, duration, correlation ID, and safe error details; they do not store session cookies, passwords, bearer tokens, or full response bodies.
