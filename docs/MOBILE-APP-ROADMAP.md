# SIMS Mobile App Roadmap

React Native (Expo) Android app — **the full SIMS system on mobile, for all four roles** (Student, Supervisor, Internship Coordinator, Administrator) — using the **existing Laravel backend and the same MySQL database** through a JSON API (`/api/v1`). The website keeps working alongside it; both enforce the same rules.

**Decisions:** all roles on mobile (revised 2026-10-02; originally Student + Supervisor only) · Android first (iPhone later from the same code) · push notifications via Expo Push (free) · hosted on a VPS with domain + HTTPS.

**How we work:** one module at a time, in order. Each module: build → developer self-tests → QA tests → fixes → retest → tick the boxes below. Modules are organized **by feature**: each feature module covers every role that uses it, with the same access rules as the website.
**Team:** `backend-developer` (Laravel API) · `mobile-developer` (Expo / React Native, repo `C:\xampp\htdocs\sims-mobile`) · `qa-engineer` (testing).

## Access rules (same as the website)

| Feature | Student | Supervisor | Coordinator | Admin |
|---|---|---|---|---|
| Dashboard / Analytics | own summary | own students | system-wide | system-wide |
| Time In / Out, My Reports, My Feedback | ✓ | — | — | — |
| Pending Approvals | — | own students | — | all |
| Attendance Monitoring | — | own students (full) | view only | full |
| Progress Monitoring | — | own students | all | all |
| Report Reviews | — | own students (view only) | review | review |
| Supervisor Evaluations | — | own students (create/submit) | view only | all + lock/reopen |
| Evaluation Criteria | — | — | — | manage |
| User Management | — | — | ✓ (no Admin/Student creation) | ✓ |
| Company Management + Supervisor Roster | — | — | ✓ | ✓ |
| Internship Assignment | — | — | ✓ | ✓ |
| Notifications | ✓ | ✓ | ✓ | ✓ |

## Progress

| # | Module | Status |
|---|---|---|
| 0 | Prerequisites (hosting, accounts, tools) | Not started (you) |
| 1 | Backend API foundation | Done — QA passed after fixes 323f1fd, b9ebec6, b00d5e7, bc64b5b, 8d67052 |
| 2 | Shared rules refactor | Done — QA passed (41 ID-manipulation checks; suite 459 passed, only the 49 Westpoint failures); fixes a5faaf6, 9b0d626, 594664b (attendance review only on pending legs) |
| 3 | Mobile app foundation | Done (static QA passed) — real-phone test pending |
| 4 | Notifications | Done — QA passed (sims 068345c, 3d1e925, 45a8de9; sims-mobile 3f4d2f1, dc98d80, d711edf, b836009, de74e7d, 8fd9782; suite 581 passed + 49 Westpoint) — real-phone test pending |
| 5 | Dashboards & Analytics | Done — QA passed (sims 06ff430, 918e4eb, ec918dc, 493e89b; sims-mobile 67d504f…b05eba2, c0cc292; suite 644 passed + 49 Westpoint) — real-phone test pending |
| 6 | Student: Attendance (Time In/Out) | Done — QA passed (sims 65704ab, 4a5ef71, df7e113, 3f8b829, e399d51, ab5ce91; web e334ffb, 01eec7d; sims-mobile b396861…27fbec5; suite 761 passed + 49 Westpoint) — real-phone test pending |
| 7 | Student: Reports | Done — QA passed (sims e82532f, b1898d2, 988cf5e, 65bf239, 0ae657c, 67f5108, 6e070c3, 4b285c9, 975b6ae; sims-mobile 2dd2d66…2cac3cc; suite 841 passed + 49 Westpoint) — real-phone test pending |
| 8 | Student: My Feedback | Done — QA passed, no bugs (sims d525328, aee3c7e; sims-mobile 4831a63, b0795af; suite 851 passed + 49 Westpoint) — real-phone test pending |
| 9 | Pending Approvals | Done — QA passed (sims b3dc1a9, d9942ab, cc76847, cca7ffc; sims-mobile a46bf56, 84b5433, dd44d16, 4b4d16c; suite 901 passed + 49 Westpoint) — real-phone test pending |
| 10 | Attendance & Progress Monitoring | Done — QA passed (sims 6543d24, 6cb4cf2, e74cb1b, 5609a82, 8d48f6d + date bail; sims-mobile 2a992e5…a2f0dc2, ca9c808, 43a17ac; suite 971 passed + 49 Westpoint; export left out for now) — real-phone test pending |
| 11 | Report Reviews | Done — QA passed, no bugs (sims e3bc568, 5212a7a; sims-mobile 56ccc23…f98844e; suite 1011 passed + 49 Westpoint) — real-phone test pending |
| 12 | Supervisor Evaluations & Evaluation Criteria | Done — QA passed (sims 08cda85, 53f54de, ebfe072, e687fe3; sims-mobile ccd9748…e37be52; suite 1116 passed + 49 Westpoint) — real-phone test pending |
| 13 | User Management | Done — QA passed (sims 7be2b52, f74a3ed, 738debe, 3b0932a, 2d062f7, c29f548; sims-mobile e12aa88…1e23a6d; suite 1188 passed + 49 Westpoint) — real-phone test pending |
| 14 | Company Management & Supervisor Roster | Done — QA passed (sims 7c6c966, e4e2a26, f810282, 4cc81ec, web 3eaed80; sims-mobile c229720, a128de0, 4fd6234; suite 1238 passed + 49 Westpoint) — real-phone test pending |
| 15 | Internship Assignment | Done — QA passed (sims 92f80d1, 80ed1d6, 6a58a65, 844619e, 7e5e686, 57d9e69; sims-mobile 65f2623, 1a1bc81, fd1da1f, 8b40509, 8d32080, e6e7812, f2706ee, 03f688d; suite 1287 passed + 49 Westpoint) — real-phone test pending |
| 16 | Push notifications | On hold (owner, 2026-10-07) |
| 17 | Full QA, build & release | Local full QA passed 2026-10-07 (fixes sims 4dcf60e, 8fbc751, 0a7e062, 7da0e60; sims-mobile 18ac8f8; suite 1299 passed + 49 Westpoint) — hosted QA, EAS build, pilot pending (need hosting + Expo account) |

---

## Module 0 — Prerequisites (you)
- [ ] Hosting: VPS or Laravel-capable host with PHP 8.2+, Composer, MySQL 8 / MariaDB 10.6+, SSH access
- [ ] Domain pointed to the server, free HTTPS (Let's Encrypt)
- [ ] Deploy the SIMS website; `APP_TIMEZONE=Asia/Manila`, `APP_DEBUG=false`, upload limits ≥ 6 MB, writable `storage/`
- [ ] Run `php artisan config:cache` (and `route:cache`) after each deploy — without it, Apache with threaded PHP on Windows can occasionally lose `.env` values under parallel requests (seen in Module 10 QA)
- [ ] Production security (Module 17 QA): `APP_DEBUG=false`; cap request size (nginx `client_max_body_size` ~10M, PHP `post_max_size`/`upload_max_filesize` ~10M — locally 2G); `expose_php=Off`; restrict CORS (app needs none; currently `*`); `TRUSTED_PROXIES` = only the real proxy; multi-worker PHP (php-fpm), not `artisan serve`
- [ ] Daily backups of the database and `storage/`
- [ ] Queue worker (`php artisan queue:work` under Supervisor or cron) — needed for push (Module 16)
- [ ] Free accounts: Expo (expo.dev) and Firebase (Android push credentials)
- [ ] On your PC: Node.js 20 LTS, Git, an Android phone with USB debugging (or Android Studio emulator)

**Done when:** the website loads over `https://your-domain` and you can log in.

## Module 1 — Backend API foundation (backend-developer)
- [x] Sanctum tokens, `/api/v1` routing, JSON-only errors, rate limits
- [x] `POST /login`, `POST /logout`, `GET /me`
- [x] **Revision:** allow **all active accounts** (roles 1–4) to log in; `GET /me` returns the right profile per role (student profile, supervisor `students_count`, staff permission flags); tests and `docs/api/v1.md` updated (ab5430d)
- [x] The 3 suspected issues confirmed and fixed (99b569e): forwarded headers trusted only from `TRUSTED_PROXIES` (default `127.0.0.1,::1`; list a remote load balancer/Cloudflare there when hosting), `email` array → 422, rate-limit key based on the matching account
- [x] QA passed: tokens revoked on password change/reset/self-delete (323f1fd), invalid UTF-8 → 422 (b9ebec6), one login counter shared by web + API (b00d5e7), per-IP limit on bad-token requests (bc64b5b)
- [x] NEW-1: valid tokens on a shared IP are never throttled by others' junk tokens (8d67052, QA passed; suite 462 passed + 49 Westpoint)

**Done when:** each of the four roles can log in by curl and `GET /me` returns the right shape; QA passes.

## Module 2 — Shared rules refactor (backend-developer)
- [x] Policies: Attendance, InternshipReport, Evaluation, Student (rules moved out of controllers)
- [x] Services: attendance, internship reports, evaluations
- [x] Revoke a user's app tokens when staff deactivate them or change their role
- [x] Finish verification: full test suite green with existing tests unmodified (only new test files added)
- [x] Found + fixed: approved time-outs and manual entries saved **negative** rendered hours (a5faaf6, predates the refactor); Internship Assignment deactivation now revokes app tokens (9b0d626)
- [ ] On the hosted/live database: repair any negative rows (`rendered_hours < 0`) — none locally
- [ ] Extend policies/services as needed for staff features (users, companies, assignments, approvals, report review) when those modules start
- [x] QA: website behaves as before, 41 ID-manipulation checks pass; found + fixed: approve/reject now only on pending legs, hours credited in either order (594664b — a reviewed leg can no longer be re-decided)

**Done when:** the website is unchanged and every rule lives in one place used by both web and API.

## Module 3 — Mobile app foundation (mobile-developer)
- [x] Expo + TypeScript + Expo Router, NativeWind, TanStack Query, axios, secure token storage
- [x] API client (Bearer token, 401/403/422/429/network handling), login, logout, profile, BCC branding
- [x] **Revision:** navigation for all four roles — Student: Home, Attendance, Reports, Feedback, Profile · Supervisor: Home, Approvals, Students, Evaluations, Profile · Coordinator: Home, Students, Reports, Companies, More · Admin: Home, Approvals, Students, Companies, More ("More" driven by `/me` `staff` flags; Admin's More also has Report Reviews). Visibility lives in `src/navigation/access.ts`
- [x] Verification: `tsc`, `expo-doctor` 21/21, Android export (4.1 MB bundle)
- [x] QA review (static): passed after fixes 625a9eb (web screen titles), 58a4fd7 (semibold), 1c016c1 (revoke token if SecureStore fails), f471ad4 (Module 4 bell plan for every role)
- [ ] Real-phone test (each role logs in, sees its tabs/More, logs out)
- [x] Design alignment: shared `StatusBadge` from the web map (4efab00), `slate` → `gray` (63bb7e0)
- [ ] Figtree font — deferred to a design pass that can be checked on a real phone (NativeWind/Android needs one family per weight)
- [ ] Supervisor has no More: reach Notifications via a bell (Module 4) and view-only reports from Students (Modules 10/11)

**Done when:** on a real Android phone each of the four roles can log in, sees its own navigation, and can log out.

## Module 4 — Notifications (all roles)
- [x] API: list (cursor-paginated), unread count, mark read, mark all read
- [x] Mobile target resolver `targetFor()` → `{screen, params}` from the same type × role table as the web `urlFor()`
- [x] App: bell with unread badge for every role, notifications screen, tap → marks read and opens the right screen (only screens the role can use; Supervisor progress/monitoring/report targets open their Students tab)
- [x] QA passed (3 bugs fixed: forged cursor 500 → 422, empty params `{}`, one unread-count poller)
- [ ] Real-phone test: tap opens the right tab without stacking tabs; Back from an Admin More screen returns to More; badge refreshes about once a minute

**Done when:** a notification created on the website appears in the app for the right role and opens the right screen.

## Module 5 — Dashboards & Analytics (all roles)
- [x] API: role dashboards reusing `DashboardAnalyticsService` (student summary; supervisor KPIs; coordinator and admin KPIs, action items, recent activity; analytics charts data with filters, supervisor-scoped)
- [x] App: dashboard per role (cards, progress bars, action items linking to screens), analytics charts for staff and supervisors, pull-to-refresh
- [x] QA passed: web dashboards unchanged after the shared-service refactor; app = web numbers for all 4 roles × 7 filter sets; supervisor scoping holds (ID probing closed in the API, 493e89b)
- [ ] Website: supervisor can still tell missing vs other supervisors' student/company ids on web analytics (low; needs a web change)
- [ ] Real-phone test: numbers match the website per role; charts on a narrow screen; date picker; large font; offline

**Done when:** numbers match the website's dashboard for the same account in every role.

## Module 6 — Student: Attendance (Time In/Out)
- [x] API: today + history; time-in, time-out, emergency time-out (multipart: photo, latitude, longitude, accuracy, note); photo download — through `AttendanceService`
- [x] Decided: store fake-GPS detection (`time_in_mocked` / `time_out_mocked`) and warn reviewers on web + app
- [x] App: live front camera + high-accuracy GPS → resize photo → submit; history with photo + map; clear permission/GPS/network/validation messages

- [x] QA passed: parallel submissions now locked (one wins), negative hours closed (no time-in re-submit after time-out; hours never below 0), photos ≤ 8000 px; app: lost-response check, photo cache cleared on logout, minimal permissions
- [ ] Real-phone test: permissions, GPS off, airplane mode, double-tap, fake GPS, portrait photo upright

**Done when:** a student times in from the phone, it appears in Pending Approvals (web and app) with photo and map, and every rule still holds.

## Module 7 — Student: Reports
- [x] API: list, create, update (pending only), delete, attachment download — through `InternshipReportService`
- [x] App: list with status, create/edit form (daily/weekly, period, content, photo/PDF attachment), reviewer comment
- [x] QA: 4 backend bugs fixed (array dates 500, review twice/after delete, missing file 500 + legacy public attachments command, JSON remove_attachment) + 1 app (emoji char count)
- [x] Ran `php artisan reports:secure-attachments --delete-orphans` (2026-10-04): report #3's file moved to the private disk, 2 unreferenced files deleted
- [x] Decided: weekly report period stays flexible (no day limit)
- [ ] Real-phone test

**Done when:** a report from the app can be reviewed (web or app) and the comment shows in the app.

## Module 8 — Student: My Feedback
- [x] API: submitted/locked evaluations with ratings and comments
- [x] App: feedback list and detail

**Done when:** it matches the website's My Feedback page.

## Module 9 — Pending Approvals (Supervisor: own students · Admin: all)
- [x] API: pending list with photo + location; approve / reject (with reason)
- [x] App: list, evidence view (full photo, open in Google Maps), approve / reject
- [x] QA passed: supervisor scoping on every endpoint, web+app reviewers racing → one decision, garbled JSON reject now 422 (cca7ffc, applies to the whole API)
- [ ] Real-phone test

**Done when:** approvals in the app update the website and notify the student; Coordinators get 403; a supervisor can't act on another supervisor's students.

## Module 10 — Attendance & Progress Monitoring (Supervisor: own · Coordinator: view only · Admin: full)
- [x] API: per-student attendance logs with evidence; manual entry add/edit/delete and required hours (Supervisor, Admin only); progress (rendered vs required hours)
- [x] App: student list → attendance log, edit forms for allowed roles, progress list
- [x] QA passed; shared web fixes: strict entry dates (2000-01-01 … today, no future entries), required hours ≤ INT max, row-locked edit/delete, duplicate-date race → 422
- [ ] Optional later: PDF/Excel export via the phone's share sheet
- [ ] Real-phone test

**Done when:** it matches the website for each role; Coordinator write attempts → 403.

## Module 11 — Report Reviews (Supervisor: view only · Coordinator, Admin: review)
- [x] API: report list (supervisor-scoped), detail, attachment download, review (comment + mark reviewed) for Coordinator/Admin
- [x] App: list with filters, detail, open attachment, review form for allowed roles (supervisor: Students tab → Reports view, read-only)
- [ ] Real-phone test

**Done when:** reviews from the app notify the student; Supervisor review attempts → 403.

## Module 12 — Supervisor Evaluations & Evaluation Criteria
- [x] API: evaluations list/show/criteria/create/update draft/submit (Supervisor; Admin), view-only (Coordinator), lock/reopen (Admin); criteria CRUD (Admin)
- [x] App: evaluation list, 1–5 rating form by category with strengths/areas/recommendations/remarks, draft/submit; admin lock/reopen; criteria management screen ("New Evaluation" supervisor-only; status colours match the web evaluation pages)
- [x] QA passed; shared web fixes: row-locked submit/lock/reopen, strict evaluation dates (2000–2099), blank criteria sort order → 0, analytics array-date 500 → 422 (e687fe3)
- [ ] Real-phone test

**Done when:** an evaluation from the app shows on the website and in the student's My Feedback; role limits enforced by the API.

## Module 13 — User Management (Coordinator, Admin)
- [x] API: list with search/filters/pagination, create (no Student role; Coordinator can't create/promote Admins), edit, activate/deactivate (revokes app tokens); Admin accounts hidden from non-Admins
- [x] App: user list, create/edit forms, status toggle
- [x] QA passed (no escalation path found); fixes: last active Admin protected, deactivate/role/password also end web sessions
- [x] Owner decisions (c29f548, website too): deactivated accounts logged out of the website on next request; Student accounts' role locked; admin ids 404 for coordinators on the web
- [x] QA retest of c29f548 passed (note: a User Management deactivation logs the user out without the "deactivated" message, since their session is deleted; other paths show it)
- [ ] Real-phone test

**Done when:** same restrictions as the website, proven by API tests (including privilege-escalation attempts).

## Module 14 — Company Management & Supervisor Roster (Coordinator, Admin)
- [x] API: company list/search, create, edit, activate/deactivate, delete; roster attach/detach supervisors
- [x] App: company list, company form, roster screen
- [x] QA passed; website changes (owner approved 2026-10-06): delete refused while students/evaluations reference the company, slots ≥ assigned students; Internship Assignment now locks the company (no slot overfill, no 500 when racing a delete)
- [ ] Real-phone test

**Done when:** it matches the website's Company Management.

## Module 15 — Internship Assignment (Coordinator, Admin)
- [x] API: student list, assign company / supervisor (must be on the company roster) / status / schedule; slot capacity enforced
- [x] App: student list, assignment form with company → roster-filtered supervisor picker
- [x] QA passed; website changes (owner approved 2026-10-06): no NEW assignment to an inactive company or inactive supervisor (incl. taking an inactive supervisor to a new company); unchanged re-saves still allowed; malformed ids → 422
- [ ] Real-phone test

**Done when:** assignments from the app follow the same roster and slot rules and notify the student and supervisor.

## Module 16 — Push notifications (all roles)
- [ ] Backend: `device_tokens` table, register/remove endpoints, `NotificationService::notify()` queues an Expo push with `targetFor()` data, prune invalid tokens
- [ ] App: permission prompt, register after login, remove on logout, tap → opens the target screen even when the app was closed (Expo development build, queue worker from Module 0)

**Done when:** a student times in → the supervisor's (and admin's) phone buzzes with the app closed → tap opens Pending Approvals.

## Module 17 — Full QA, build & release
- [x] Local QA end to end (2026-10-07): all four roles, every module, 78 endpoints × no token/wrong role, ID manipulation, privilege escalation, uploads, rate limits — PASS; fixed: User Management malformed id 500, last admin could self-delete via Profile, JSON `true` accepted as an id, attendance notification highlight in app, monitoring order in docs
- [ ] QA end to end on the hosted server: all four roles, every module, ID manipulation and privilege escalation through the API, uploads, poor/no network, denied permissions
- [ ] EAS build: APK for a pilot group; AAB for Google Play ($25 one-time)
- [ ] Pilot with students, a supervisor, a coordinator and an admin; fix feedback; roll out

**Done when:** the pilot runs a full week of internship operations through the app.

---

## Checks for every module
- `php artisan test`: existing web tests stay green, plus API tests for the module (each role allowed/denied, no token, cross-supervisor / cross-student IDs, privilege escalation)
- QA tests the API live with curl as every role that uses the module; the app is tested on a real Android phone against the hosted HTTPS API
- App screens follow the `mobile-design` skill + `.claude/skills/mobile-design/references/sims-web-alignment.md`: same colours, status badges, type and wording as the matching website page
- Tick the module's boxes and update the Progress table
