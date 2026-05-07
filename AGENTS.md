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

- **Users use UUIDs** — `$keyType = 'string'`, `$incrementing = false`, auto-generated in `booted()` event
- **Multi-tenant via `store_id`** — Users belong to a Store; scoping/filtering by store is expected
- **Roles**: `SUPER_ADMIN`, `STORE_ADMIN` (Spatie Permission). Route middleware: `role:SUPER_ADMIN|STORE_ADMIN`
- **API versioning**: All routes under `/api/v1/...`
- **Nginx**: Frontend SPA at `/`, backend Laravel at `/api` — they are separate apps

## Key Patterns

- Controllers live in `app/Http/Controllers/Api/V1/Admin/` for admin endpoints
- Form Requests in `app/Http/Requests/Api/V1/`
- API Resources in `app/Http/Resources/` — always use these for response transformation
- OpenAPI annotations in `app/OpenApi/` — update these when changing API endpoints
- Custom middleware: `CheckFeature` (feature flags), `BlockSuspiciousAgents`

## Testing

- Framework: **Pest** (not raw PHPUnit)
- Uses **SQLite in-memory** (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`)
- No external DB needed for tests — configured in `phpunit.xml`
- CI runs `php artisan test` on push/PR to `develop` and `master`

## Deployment

- Deploys to **DigitalOcean** via SSH on push to `develop`
- Runs inside Docker Compose containers
- Deploy script runs: `composer install --no-dev`, `storage:link`, `migrate`, `db:seed --class=RoleSeeder`, `permission:cache-reset`, `l5-swagger:generate`, `optimize:clear`
- **RoleSeeder is idempotent** (`firstOrCreate`) — safe to re-run

## Gotchas

- `.env` DB host is `db` (Docker service name), not `localhost`
- Swagger docs route is `/api/docs` (not `/docs`) — configured in `config/l5-swagger.php`
- `generate_always` is `true` in dev — docs regenerate on each request
- Seeders: `DatabaseSeeder` runs `RoleSeeder` + `BusinessTypeSeeder` always; additional seeders only in `local` env
- `.gitignore` excludes `.env`, `.env.develop`, `.env.prod`, `.env.local` — never commit these
