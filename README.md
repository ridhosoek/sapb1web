# SAP B1 Operational Web Portal

Laravel modular monolith for operational Purchase Request, purchase progress, GRPO, and Goods Issue workflows backed by SAP Business One Service Layer `/b1s/v2`.

## Implemented foundation

- Laravel 13, PHP 8.3, Livewire 4, Blade, Bootstrap 5, AdminLTE 4, and Alpine through Livewire.
- Authenticated, role-aware operational shell with Pool and Department sourced only from the logged-in user's operational profile.
- Control Center gateway and queued cache synchronization for Pools, Departments, and Vehicles.
- Central SAP Service Layer client, Redis-backed session reuse, one controlled re-authentication attempt, centralized field mappings, business-safe errors, and integration logs.
- Core SAP document mapping, many-to-many document relations, watcher state, normalized snapshots, idempotent command state, and append-only application audit log.
- Searchable local SAP item cache and vehicle lookup that is always scoped to the authenticated user's Pool.

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

SAP property names under `SAP_FIELD_*` must be verified against the target Service Layer `$metadata` before transaction phases are enabled. The application does not use direct SAP database writes, SQLQueries, ETag synchronization, webhooks, or a web approval module.
