# Gap Analysis

## Baseline

The workspace contains only `SYSTEM REQUIREMENT SAP WEB.docx`. There is no existing Laravel application, source code, configuration, database schema, test suite, or Git metadata to preserve.

## Requirement gaps

| Area | Current state | Required direction |
| --- | --- | --- |
| Application foundation | Missing | Laravel modular monolith using Blade, Livewire, Alpine.js, Bootstrap 5, and a customized AdminLTE 4 shell |
| Authentication and authorization | Missing | Server-side authentication, role permissions, policies, login rate limiting, and operational profile enforcement |
| Operational context | Missing | Pool and Department derived from the authenticated user and never trusted from transaction input |
| Control Center integration | Missing | Gateway abstraction and synchronized local cache for pools, departments, vehicles, and user context |
| SAP integration | Missing | Central Service Layer client, Redis-backed session manager, field mapping, document gateways, structured error mapping, and no direct SAP database access |
| Transaction lifecycle | Missing | Purchase Request, document progress, GRPO from open PO only, and Goods Issue modules |
| Reliability | Missing | Transaction UUID idempotency, ambiguous-post recovery, queued polling, retry control, audit trail, and integration logs |
| User experience | Missing | Desktop-first operational layout, segmented headers, dense detail grids, clear read-only states, sticky actions, loading/empty/error states |
| Quality assurance | Missing | Automated feature/unit tests and acceptance coverage for each phase |

## Implementation decision

The requirement explicitly prescribes phase-by-phase delivery and warns against a single large rebuild. This implementation starts with Phase 1 and establishes stable contracts and schema boundaries for later phases. Unconfirmed SAP Service Layer fields and Control Center payloads remain centralized in configuration and mapping classes; they are not guessed inside controllers or UI components.

## Phase 1 completion target

- Laravel application foundation and environment templates.
- Customized operational AdminLTE-style shell using Bootstrap 5, Inter, and JetBrains Mono.
- Authentication and role-aware navigation.
- User operational profiles with Pool and Department enforcement.
- Control Center contracts, HTTP gateway, cache sync command/job, and local cache tables.
- SAP Service Layer client, Redis-backed session lifecycle, centralized endpoint/field configuration, and business-safe exception mapping.
- SAP items cache and searchable item endpoint/component boundary.
- Core document, relationship, watcher, command, log, and append-only audit tables needed by later phases.
- Foundation tests covering operational context isolation, vehicle scoping, SAP configuration, and health behavior.

## Deferred according to the prescribed order

- Phase 2: Purchase Request creation and SAP posting.
- Phase 3: Purchase lifecycle polling and line-level progress.
- Phase 4: GRPO from open Purchase Orders.
- Phase 5: Goods Issue.
- Phase 6: Operational and integration dashboards.
- Phase 7: end-to-end hardening and acceptance completion.
