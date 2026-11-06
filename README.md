# SAP B1 Operational Web Portal

Laravel modular monolith for operational Purchase Request, purchase progress, GRPO, and Goods Issue workflows backed by SAP Business One Service Layer `/b1s/v2`.

## Current documentation

- [Start here — Co-Product Owner onboarding](docs/START-HERE.md)
- [Product charter](docs/product/product-charter.md)
- [Co-Product Owner playbook](docs/product/co-product-owner-playbook.md)
- [New chat prompt](docs/agent-handoff/new-chat-prompt.md)
- [Application documentation](docs/application/README.md)
- [Agent handoff context](docs/agent-handoff/context.md)
- [Architecture and integration](docs/application/architecture.md)
- [Business processes](docs/application/business-processes.md)
- [Operational rules](docs/application/operational-rules.md)

The older gap analysis records the initial project baseline and is historical; use the documentation above for the current implementation.

## Implemented foundation

- Laravel 13, PHP 8.3, Livewire 4, Blade, Bootstrap 5, AdminLTE 4, and Alpine through Livewire.
- Multi-database login and role-aware operational shell with Warehouse, Pool, and Department sourced from the logged-in user's profile.
- Purchase Request approval by email, Admin HO check, Goods Issue approval in the portal, and automatic SAP posting after final approval.
- Goods Receipt PO from open SAP Purchase Orders, including per-unit serial setup and portal-only receipt photos.
- SAP cost-center synchronization for Department, Pool, and Nopol, plus Control Center cache support.
- Central SAP Service Layer client, Redis-backed session reuse, one controlled re-authentication attempt, centralized field mappings, business-safe errors, and integration logs.
- Core SAP document mapping, many-to-many document relations, watcher state, normalized snapshots, idempotent command state, and append-only application audit log.
- Searchable SAP item/cost-center caches, warehouse-scoped stock and PO lookups, purchase progress polling, Item Master detail, and Report Beban Sparepart.

See [Gap Analysis](docs/gap-analysis.md) and [Integration Contracts](docs/integration-contracts.md).

## Local setup

```bash
composer install
npm install
copy .env.example .env
php artisan key:generate
```

Configure MySQL, Redis, SAP Service Layer, and Control Center credentials in `.env`, then run:

```bash
php artisan migrate
php artisan control-center:sync
php artisan queue:work
php artisan schedule:work
npm run build
php artisan serve
```

For local UI verification only, `php artisan migrate:fresh --seed` creates `admin@example.test` with password `password`. The demo account is never created outside `local` or `testing` environments.

## Verification

```bash
composer test
npm run build
php artisan schedule:list
```

SAP property names under `SAP_FIELD_*` must be verified against the target Service Layer `$metadata` before transaction features are enabled. The application never writes directly to the SAP database. Report Beban Sparepart may use configured Service Layer SQL Queries; transactional documents always use Service Layer entity endpoints.
