# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state — read this first

This repo was repurposed from a different application and shares the Laravel installation with its leftovers:

1. **"Westpoint"** — a multi-branch pharmacy POS / medicine inventory system that used to live here. Its controllers, models, and migrations (Product, MedicineProduct, ProductQty, StockIn/StockOut/StockTransfer, Pos, Order, Quotation, Branch, Customer, Table, etc.) have been deleted from the working tree.
2. **SIMS (Student Internship Management System, referred to as "Student Information Management System" in `APP_NAME`)** — the app actively being built now, for Bacolod City College's OJT/internship program (see `PRINTER_STORE_NAME` in `.env`). Domain: Students, Companies (internship host companies), Users with roles (`Student`, `Internship Coordinator`, `Supervisor`, `Administrator` — role IDs 1–4, see `database/seeders/RoleSeeder.php`).

Because the old system's models/controllers were deleted but not every reference to them was cleaned up, **some currently-committed files still point at classes that no longer exist and are dead code**:

- `app/Services/{InventoryStockService,InventoryMovementLogger,ReceiptPrinterService,DocumentNumberService}.php`, `app/Console/Commands/ExpireLapsedBatches.php`, `app/Exceptions/{InsufficientStockException,InvalidPackSizeException}.php`, and `app/Enums/UnitType.php` reference deleted models (`ProductQty`, `MedicineProduct`, etc.).
- Several tests reference the old domain and will not run: `tests/Feature/{WestpointFeatureTest,PosSplitBatchSaleTest,StockBatchMergeTest,StockInDuplicateLotTest,BatchDeactivationTest,QuotationMedicineSearchTest}.php`, `tests/Support/SeedsWestpoint.php`. `phpunit.xml` still points `DB_DATABASE` at `db_westpoint_testing`.
- `docs/QA-AUDIT-REPORT.md` is a QA audit of the old pharmacy system — historical reference only, not current.

When working on SIMS features, don't assume these files compile/run correctly, and don't be surprised by orphaned references — check whether a class you're about to use was actually part of the cleanup before trusting it. If asked to finish the cleanup, these are the pieces still needing removal.

## Agent Team

This repo has three named Claude Code agents (`.claude/agents/`):

- **backend-developer** — Laravel side: routes, controllers, services, models, migrations, validation, role-based authorization, PHP feature tests. Owns the Inertia props contract (what each controller passes to `Inertia::render`).
- **frontend-developer** — React/Inertia side: everything under `resources/js` (pages, partials, hooks, shared/shadcn components, charts, responsive layout). Consumes the backend's props contract; doesn't edit PHP.
- **qa-engineer** — read-only; independently tries to break what was built (validation, role-based authorization, frontend/backend/DB behavior, regressions), files bug reports to whichever developer owns the broken layer, retests after fixes. Never edits code.

Workflow: spawn backend-developer and frontend-developer together (parallel `Agent` calls, each with a `name`) → backend sends the planned props contract early so frontend builds in parallel → both self-test (`php artisan test` / `npm run build`) → QA independently tests → bugs go to the owning developer → fix → QA retests → repeat until QA passes it. `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1` is enabled locally (`.claude/settings.local.json`). Agents message each other by spawned name via `SendMessage`; this only reaches an agent that is currently running, so each agent also puts undeliverable messages in its final report for the lead to relay.

## Commands

Backend (PHP 8.2 / Laravel 11):
```
composer install
php artisan migrate          # requires DB configured in .env (MySQL; DB_DATABASE=db_sims)
php artisan serve
php artisan test                                  # full suite
php artisan test --filter=TestClassName           # single test class
php artisan test --filter=TestClassName::test_method  # single test method
vendor/bin/pint                                   # code style (no custom pint.json — Laravel defaults)
```

Frontend (Inertia + React + Vite):
```
npm install
npm run dev      # Vite dev server
npm run build    # production build
```

Run both together (server + queue + logs + Vite):
```
composer run dev
```
or
```
npm run serve    # php artisan serve + vite concurrently
```

There is no configured JS test runner or linter (no eslint config present).

## Architecture

**Stack:** Laravel 11 backend, Inertia.js v2 bridging to a React 18 SPA (no separate API layer/JSON contracts — controllers return `Inertia::render(...)` and pages receive props directly). Styling via Tailwind + shadcn/ui (`components.json`, `new-york` style, components under `resources/js/Components/ui`). Vite alias `@` → `resources/js`.

**Routing** is split by domain instead of one `web.php`:
- `routes/web.php` — dashboard + profile, and requires the others
- `routes/auth.php` — login/register/password reset (Breeze-based), plus a separate student self-registration flow (`StudentRegisteredUserController`, distinct from the admin-created-user flow in `RegisteredUserController`)
- `routes/user.php` — `/user-management/*`, admin-only (`role:4`)
- `routes/company.php` — `/company-management/*`, coordinator + admin (`role:2,4`)

**Role-based access** is enforced by `App\Http\Middleware\CheckRoleMiddleware`, registered as the `role:` middleware alias, taking role IDs as params (e.g. `role:2,4`). Role IDs are not an enum — they're plain integers matched against `users.role_id`; the canonical mapping lives in `RoleSeeder`. `User::dashboardRouteName()` decides which dashboard a role lands on after login (`admin-dashboard` for role 4, `dashboard` otherwise) — there's no dedicated Student/Supervisor dashboard route yet, they fall through to the generic one.

**Domain model:** `User` (auth + role_id) → optionally has one `Student` → optionally `belongsTo` a `Company`. `Company` `hasMany` `Student`. Users who aren't students (coordinators, supervisors, admins) have no `Student` row.

**Inertia shared props** (`HandleInertiaRequests::share`) expose `auth.user` and flash messages (`success`/`error`/`sale_id` — `sale_id` is a leftover from the POS flow).

**Page/controller naming convention:** Inertia views live under `resources/js/Pages/<Feature>/{Index,...}`, matching the controller/route group name (e.g. `CompanyManagement/Index`, `UserManagement/Index`), each with local `Hooks/` and `Partials/` subfolders for feature-specific hooks and sub-components.
