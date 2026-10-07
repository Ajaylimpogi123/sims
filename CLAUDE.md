# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project state — read this first

**SIMS (Student Internship Management System, referred to as "Student Information Management System" in `APP_NAME`)** — for Bacolod City College's OJT/internship program. Domain: Students, Companies (internship host companies), Users with roles (`Student`, `Internship Coordinator`, `Supervisor`, `Administrator` — role IDs 1–4, see `database/seeders/RoleSeeder.php`).

This repo was repurposed from an old pharmacy POS app ("Westpoint"). Its code and tests were fully removed on 2026-10-07 (commits 0b23616…53349e9); `docs/QA-AUDIT-REPORT.md` remains as historical reference only. The full test suite (`db_sims_testing`) is expected to pass with **0 failures**. The `PRINTER_*` keys in `.env` are unused leftovers.

Only one `php artisan test` run at a time across agents — every run shares `db_sims_testing`, and concurrent runs produce false failures.

## Agent Team

A mobile app (Expo/React Native, Android, all four roles) is being built against a new `/api/v1` JSON API in this Laravel app — see `docs/MOBILE-APP-ROADMAP.md`. The app code lives in a separate repo at `C:\xampp\htdocs\sims-mobile`, owned by the **mobile-developer** agent.

This repo has these named Claude Code agents (`.claude/agents/`), plus `mobile-developer`:

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
- `routes/user.php` — `/user-management/*`, coordinator + admin (`role:2,4`); coordinators can't see, create or promote Admins, nobody creates Students (self-registration only)
- `routes/company.php` — `/company-management/*`, coordinator + admin (`role:2,4`)

**Role-based access** is enforced by `App\Http\Middleware\CheckRoleMiddleware`, registered as the `role:` middleware alias, taking role IDs as params (e.g. `role:2,4`). Role IDs are not an enum — they're plain integers matched against `users.role_id`; the canonical mapping lives in `RoleSeeder`. `User::dashboardRouteName()` decides which dashboard a role lands on after login (`admin-dashboard` for role 4, `dashboard` otherwise) — there's no dedicated Student/Supervisor dashboard route yet, they fall through to the generic one.

**Domain model:** `User` (auth + role_id) → optionally has one `Student` → optionally `belongsTo` a `Company`. `Company` `hasMany` `Student`. Users who aren't students (coordinators, supervisors, admins) have no `Student` row.

**Inertia shared props** (`HandleInertiaRequests::share`) expose `auth.user` and flash messages (`success`/`error`).

**Page/controller naming convention:** Inertia views live under `resources/js/Pages/<Feature>/{Index,...}`, matching the controller/route group name (e.g. `CompanyManagement/Index`, `UserManagement/Index`), each with local `Hooks/` and `Partials/` subfolders for feature-specific hooks and sub-components.
