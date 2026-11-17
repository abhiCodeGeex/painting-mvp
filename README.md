# Paint MVP

Artwork quotation and approval platform: customers send enquiries for catalog paintings, custom creations or framing, preview them on a wall, and approve or reject a formal quotation.

- Full product spec: [docs/SPEC.md](docs/SPEC.md)
- Stack: Laravel 12 · Filament 5 (admin) · Inertia + React + TypeScript (customer portal) · PostgreSQL 16 · Redis · Gotenberg (PDF)
- Locale: English, INR (₹, Indian digit grouping), GST, timezone Asia/Kolkata

## Running locally (Docker)

Everything runs in Docker; you don't need PHP, Composer or PostgreSQL installed on Windows.

```bash
cp .env.example .env
docker compose build
docker compose up -d
docker compose run --rm app composer install
docker compose run --rm app npm ci
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate --seed
```

`vendor/` and `node_modules/` live in Docker volumes (not on the Windows drive) because reading them through the bind mount makes every request several seconds slower. Always run `composer` and `npm` through `docker compose run --rm app …`. If your editor needs `vendor/` for autocompletion, you can also run `composer install` on the host; the container never uses that copy.

For frontend hot reload, queue worker and scheduler, also start the `dev` profile:

```bash
docker compose --profile dev up -d
```

Without the `dev` profile, build the frontend once with `docker compose run --rm app npm run build`.

| What | URL |
|---|---|
| Customer portal | http://localhost:8090 |
| Admin panel | http://localhost:8090/admin |
| Mailpit (test emails) | http://localhost:18026 |
| Horizon (queues) | http://localhost:8090/horizon |
| PostgreSQL from host | `127.0.0.1:25432`, db `paint_mvp`, user `paint` / `secret` |

### Default logins (local only)

| Role | Email | Password |
|---|---|---|
| Super Admin | `admin@paint-mvp.test` (from `ADMIN_EMAIL`) | `password` (from `ADMIN_PASSWORD`) |
| Demo customer | `customer@paint-mvp.test` | `password` |

Change the admin password after first login.

## Common commands

```bash
docker compose run --rm app php artisan <command>
docker compose run --rm app ./vendor/bin/pest --parallel     # tests (uses paint_mvp_test DB)
docker compose run --rm app ./vendor/bin/pint                # PHP code style
docker compose run --rm app npm run lint                     # ESLint
docker compose run --rm app php artisan pdf:smoke-test       # check PDF rendering
docker compose run --rm app php artisan shield:generate --all --panel=admin   # permissions for new admin resources
```

## Project layout

```
app/Models            Eloquent models
app/Enums             UserType, Role, statuses
app/Domain/*          Business logic (pricing engine, workflows) — no HTTP
app/Filament          Admin panel resources, pages, widgets
app/Http/Controllers  Customer portal (Inertia) controllers
app/Settings          Company & app settings (spatie/laravel-settings)
app/Support           Small helpers (Money)
resources/js          React portal (pages, components)
docker/               Dev image and Postgres init
docs/SPEC.md          Product specification
```

## Users and roles

- `users.type` is `staff` or `customer`. Only active staff can enter `/admin`; staff opening the portal are redirected to `/admin`.
- Roles: `super_admin`, `manager`, `sales`, `catalog_manager`, `customer`. Super Admin bypasses all permission checks; other roles get permissions in **System → Roles**.
- Users are soft-deleted, never hard-deleted.
- Money is stored as integer **paise**; format it with `App\Support\Money::format()`.
