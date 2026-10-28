# Integration Contracts

## SAP Business One Service Layer

All SAP calls pass through `SapServiceLayerClient`. Controllers and Livewire components must call an application service or a document gateway; they must not call SAP directly.

The client uses `/b1s/v2`, reuses a server-side session through Redis, and retries an authenticated request only once after an HTTP 401. It does not rely on ETag, webhook, SQLQueries, or direct database access.

SAP entity endpoints and environment-specific field names live in `config/sap.php`. Before enabling a transaction module, verify every configured field against the target environment's `$metadata` and an actual response. Unknown fields must remain encapsulated in the mapping layer.

Every SAP POST in later phases must first persist a `sap_commands` row with a unique `transaction_uuid`. If a timeout makes the result ambiguous, set `POSTING_UNKNOWN`, search SAP by the configured web transaction UDF, and recover `DocEntry` and `DocNum` before considering a retry.

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
