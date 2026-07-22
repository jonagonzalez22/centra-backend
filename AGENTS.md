# AGENTS.md

## Project

Laravel 12 backend for **Centra** — a multi-tenant SaaS platform. PHP 8.2+, MySQL 8.0, Redis, Docker.

## Quick Commands

```bash
composer run setup          # full bootstrap: install deps, copy .env, key:generate, migrate, npm install/build
composer run dev            # start all: artisan serve + queue:listen + pail + vite (concurrent)
php artisan test            # run all tests (Pest/PHPUnit)
php artisan test --filter=TestName   # run single test
./vendor/bin/pint           # code formatting (PSR-12)
php artisan l5-swagger:generate      # regenerate Swagger docs
```

## Architecture

- **Models use UUIDs** — two patterns: `HasUuids` trait or manual `(string) Str::uuid()` in `booted()`. All need `$keyType = 'string'`, `$incrementing = false`
- **Exceptions with auto-increment IDs**: `BusinessType`, `CustomerCounter`, `SkuCounter` (utility/lookup tables)
- **Multi-tenant via `store_id`** — Users belong to a Store; always scope queries by store (use `scopeForStore` or manual `where('store_id', ...)`)
- **Roles**: `SUPER_ADMIN`, `BACKOFFICE_USER`, `STORE_ADMIN`, `STORE_USER` (Spatie). **Permissions** follow `{module}.{action}` pattern: `sales.view`, `sales.create`, `sales.edit`, `sales.delete`, etc.
- **API versioning**: All routes under `/api/v1/...` in `routes/api.php`
- **Nginx**: Frontend SPA at `/`, backend Laravel at `/api` — separate apps

## Key Patterns

- Controllers in `app/Http/Controllers/Api/V1/Admin/` (backoffice) and `.../Store/` (tenant)
- Form Requests in `app/Http/Requests/Api/V1/`
- API Resources in `app/Http/Resources/` — always use these for response transformation (extend `JsonResource`, use `whenLoaded()` for relations)
- OpenAPI/Swagger annotations in `app/OpenApi/` — regenerate with `php artisan l5-swagger:generate` after changing endpoints
- **Middleware registered in `bootstrap/app.php`** (no Kernel.php in Laravel 12). Includes aliases: `feature`, `role`, `permission`
- **Feature flags via `feature:{code}` middleware** — feature codes: `cash`, `customers`, `deliveries`, `inventory`, `messaging`, `multi_user`, `reports`, `route_mapping`, `sales`. Backoffice users (null `store_id`) bypass feature checks
- **Model Observers** in `app/Observers/` — auto-generate sequential codes and maintain denormalized `search_text` field
- **Service classes** in `app/Services/` — plain PHP classes using `DB::transaction()` + `lockForUpdate()` for critical operations (never put complex business logic in controllers)

## Observers & Auto-maintained Fields

- `CustomerObserver` auto-populates: `customer_code` (C-000001), `document_number_normalized` (strips non-digits), `search_text` (see below), `store_id`, `created_by`/`updated_by`
- `CustomerAddressObserver` enforces single `is_main` address per customer

### `search_text` field (customers table)

Auto-generated on create/update. Contains (lowercased, accent-stripped): `display_name`, `document_number_normalized`, `customer_code`, and all address `street` values. Accent normalization: `á→a`, `é→e`, `í→i`, `ó→o`, `ú→u`, `ñ→n`. This is the field queried by `?search=` parameter — **search terms with accents will NOT match unless normalized**. If search filtering breaks, check the Observer is registered and `search_text` is being populated.

## Testing

- Framework: **Pest** (not raw PHPUnit)
- Uses **SQLite in-memory** (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) — configured in `phpunit.xml`, no external DB needed
- CI runs `php artisan test` on push/PR to `develop` and `master` (PHP 8.4 in CI, 8.2+ required locally)

## Deployment

- Deploys to **DigitalOcean** via SSH on push to `develop`
- Runs inside Docker Compose (`docker compose exec -T app php artisan ...`)
- Deploy steps: `git pull`, `docker compose up -d --build`, `composer install --no-dev`, `storage:link`, `migrate --force`, `db:seed --class=RoleSeeder --force`, `permission:cache-reset`, `l5-swagger:generate`, `optimize:clear`
- **RoleSeeder is idempotent** (`firstOrCreate`) — safe to re-run

## Inventory / Stock

- **Product fields**: `stock` (physical), `stock_reserved` (allocated), `stock_min` (alert threshold)
- **Available stock**: `available_stock` computed attribute = `max(0, stock - stock_reserved)`
- **InventoryMovementService** (`app/Services/InventoryMovementService.php`): handles all stock changes. Uses `DB::transaction()` + `lockForUpdate()`. Types: `input`, `output`, `adjustment`
- **Movement types**: `input` → `stock += qty`; `output` → `stock -= qty`; `adjustment` → `stock += qty` (qty can be negative in concept)
- **Sales module** (in development): will add types `sale`, `sale_return`, `reserve`, `reserve_release` to `inventory_movements.type` enum. Service will need to handle `stock_reserved` separately from `stock`

## Gotchas

- `.env` DB host is `db` (Docker service name), not `localhost`
- Swagger docs route is `/api/docs` (not `/docs`)
- `generate_always` is `true` in dev — docs regenerate on each request
- Seeders: `DatabaseSeeder` runs `RoleSeeder` + `BusinessTypeSeeder` always; additional seeders only in `local` env
- `.gitignore` excludes `.env`, `.env.develop`, `.env.prod`, `.env.local` — never commit these
- `document_number_normalized` strips all non-digit characters via Observer — always query/compare against this field, not `document_number`
- **Stock integrity**: `stock_reserved` must never exceed `stock`. Validate with `Product::validateStockIntegrity()` before any reserve operation
