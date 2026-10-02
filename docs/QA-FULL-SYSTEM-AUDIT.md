# SIMS Full-System QA Audit

Status: IN PROGRESS (multi-session). Maintained by qa-engineer, relayed via lead to full-stack-developer.
Last updated: 2026-09-21 (session 1)

Do not confuse with docs/QA-AUDIT-REPORT.md (dead Westpoint-pharmacy-era doc, historical only).

## 1. Module inventory (from routes/*.php + app/Http/Controllers + resources/js/Pages)

| Module | Route file | Controller | Roles (intended) |
|---|---|---|---|
| Auth (login/register/password) | routes/auth.php | Auth AuthenticatedSessionController, Auth StudentRegisteredUserController, Auth RegisteredUserController, Auth PasswordResetLinkController, Auth NewPasswordController, Auth PasswordController | guest / auth (mixed) |
| Dashboard and Analytics | routes/web.php | DashboardController (+ DashboardAnalyticsService, NotificationService) | all authenticated (role-scoped content); /admin-dashboard = role:4 |
| User Management | routes/user.php | UserController, Auth RegisteredUserController | role:2,4 |
| Company Management (+ supervisor roster) | routes/company.php | CompanyController | role:2,4 |
| Internship Assignment (incl. merged Student Management) | routes/internship-assignment.php | InternshipAssignmentController | role:2,4 |
| Attendance (student self-service) | routes/attendance.php | AttendanceController | role:1 |
| Attendance Monitoring | routes/attendance-monitoring.php | AttendanceMonitoringController | view: role:2,3,4; write: role:3,4 |
| Pending Approvals (attendance approvals) | routes/attendance-approvals.php | AttendanceApprovalController | role:3,4 (Coordinator explicitly excluded) |
| Internship Reports (student self-service) | routes/internship-reports.php | InternshipReportController | role:1 |
| Report Reviews | routes/report-reviews.php | ReportReviewController | view: role:2,3,4; review (mutate): role:2,4 |
| Progress Monitoring (read-only) | routes/progress-monitoring.php | ProgressMonitoringController | role:2,3,4 |
| Supervisor Evaluations (Monitoring and Feedback) | routes/evaluations.php | EvaluationController | view: role:2,3,4; create/submit: role:3,4; lock/reopen: role:4 |
| Evaluation Criteria (admin config) | routes/evaluations.php | EvaluationCriteriaController | role:4 |
| My Feedback (student view of own evaluations) | routes/evaluations.php | EvaluationController myFeedback | role:1 |
| Notifications | routes/notifications.php | NotificationController | all authenticated (self-scoped) |
| Profile | routes/web.php | ProfileController | all authenticated (self-scoped) |

Models: Attendance, Company, Evaluation, EvaluationCriteria, EvaluationResponse, InternshipReport, Notification, Role, Student, User.
Services: DashboardAnalyticsService, NotificationService (+ dead Westpoint-era services per CLAUDE.md, not in scope).

Known pre-existing noise (not reported as bugs, per instructions): WestpointFeatureTest, PosSplitBatchSaleTest, StockBatchMergeTest, StockInDuplicateLotTest, BatchDeactivationTest, QuotationMedicineSearchTest, tests/Support/SeedsWestpoint.php, phpunit.xml db_westpoint_testing reference.

## 2. Baseline: existing automated test suite

php artisan test run in full: 182 passed, 49 failed - all 49 failures are in the six known-dead Westpoint test files above (confirmed via "Class App\Models\Branch not found" etc.), zero failures in any SIMS test. This is a clean baseline; not itself a finding.

Existing SIMS feature-test files already give solid coverage of the access matrix and cross-supervisor IDOR isolation for: Evaluation (EvaluationTest, AnalyticsAuthorizationTest), Dashboard/Analytics (AnalyticsTest, AnalyticsAuthorizationTest, DashboardTest), Attendance Monitoring / Progress Monitoring (SupervisorMonitoringTest), Attendance Approvals (AttendanceApprovalTest), Report Reviews (ReportReviewTest), Internship Assignment (InternshipAssignmentTest), Company Supervisor Roster (CompanySupervisorRosterTest, CompanySupervisorRosterBackfillTest), User Management (UserManagementTest), Notifications (NotificationControllerTest, NotificationTriggersTest). QA effort below focuses on gaps beyond this baseline: adversarial HTTP-level probing, edge cases, and paths not covered by the existing suite.

## 3. Checklist (per module x role x normal/edge/validation/authorization/ID-manipulation/responsive)

Legend: [x] verified this session, [ ] queued/not yet done.

### 3.1 Authorization matrix sweep (all modules x all 4 roles, GET + representative mutating routes)
- [x] GET-level sweep of all 15 protected route groups x 4 roles via live HTTP session (curl), matches intended matrix exactly. All PASS, no bugs.
- [x] Mutating-route spot checks: Coordinator blocked from attendance-monitoring writes, Supervisor blocked from report-reviews review, Coordinator blocked from evaluation store, Supervisor blocked from lock/reopen, Coordinator blocked from attendance-approvals entirely, Student blocked from internship-assignment/company-management writes. All PASS.
- [ ] Full mutating-route sweep for every remaining PATCH/POST/DELETE x disallowed roles - partially covered by existing feature tests, not yet independently re-verified via live HTTP by QA.

### 3.2 Cross-supervisor IDOR (most important category per user emphasis)
- [x] Evaluation module: dev own AnalyticsAuthorizationTest/EvaluationTest already assert Supervisor A cannot view/update/submit Supervisor B evaluation via direct ID, and query-param widening is rejected/silently overridden on the Analytics endpoint. Verified by reading plus full suite green.
- [x] Attendance Approvals: AttendanceApprovalTest cross-supervisor direct-ID test already covers this.
- [ ] Attendance Monitoring direct-ID cross-supervisor mutation - covered by SupervisorMonitoringTest, re-verify independently live not yet done.
- [ ] Report Reviews cross-supervisor read via direct ID - module has no show route, only index+review; index is properly scoped per ReportReviewController index method (confirmed by code read, no live HTTP re-test yet).

### 3.3 Evaluation and Dashboard/Analytics (priority - least tested by dev)
- [x] Read EvaluationController, EvaluationCriteriaController, DashboardAnalyticsService, DashboardController line-by-line.
- [x] Adversarial probe: duplicate evaluation_criteria_id entries in the responses array on evaluation store/update - FOUND BUG (500 crash, see Finding 2).
- [ ] Adversarial probe: rating boundary values (0, 6, negative, non-integer, string) on responses - validation rule is integer/min:1/max:5, expect 422; not yet independently re-verified live.
- [ ] Adversarial probe: evaluation_period_end before evaluation_period_start - rule is after_or_equal, expect 422; not yet live-tested.
- [ ] Adversarial probe: submitting a response for an inactive/retired criterion ID - validation only checks exists in evaluation_criteria, does NOT check is_active. Business-rule gap, not yet confirmed as exploitable or harmful - queued.
- [ ] Dashboard Analytics: Inertia defer partial-reload edge cases (loading state in React) - not yet reviewed (frontend only, resources/js/Pages/Dashboard).
- [ ] Analytics filters: malformed date_from/date_to, oversized or negative company_id/student_id - validation exists, expect clean 422; not yet independently re-tested live.
- [ ] Evaluation lock/reopen state-machine edge cases (lock a draft, reopen a draft, double-lock) - abort_unless guards exist; adversarial state-transition fuzzing not yet done.

### 3.4 User Management
- [x] Role-based route access (Admin/Coordinator only) - PASS.
- [x] Admin-account protection confirmed by code read plus UserManagementTest green.
- [x] FOUND BUG: creating a role_id=1 (Student) user via the normal Register User form on /user-management never creates a Student profile row, and the student-only self-service pages (/my-attendance, /my-reports) then hard-crash with a 500 error. See Finding 1 (High).
- [x] Orphaned/dead route: GET /user-management/create renders the wrong Inertia component and is not linked from anywhere in the UI. See Finding 3 (Low).
- [ ] Pagination/filter edge cases on /user-management (role_id filter with non-existent role id, status filter with invalid value, page beyond last page) - not yet tested.
- [ ] Duplicate email on create/update - validation has unique, expect 422; not yet independently re-tested live.

### 3.5 Company Management
- [x] Role gate (role:2,4) - PASS.
- [x] Read CompanyController fully - slots validation (min:0), supervisor attach restricted to role_id=3, company delete uses nullOnDelete FK so students orphaned-to-null safely (confirmed via migration read, not a bug).
- [ ] Live retest of slot-capacity edge cases (0 slots, negative via raw payload) - not yet tried on the company side directly.
- [ ] Detach a supervisor who currently has active students assigned to that company - behavior not yet probed.

### 3.6 Internship Assignment
- [x] Read controller fully - extensive existing test coverage. Code looks correct.
- [ ] Live adversarial retest (oversized strings, malformed dates, XSS-payload in name/section fields reflected anywhere unescaped) - not yet done.

### 3.7 Attendance (student self-service) / Attendance Monitoring / Pending Approvals
- [x] Role gates - PASS (see 3.1).
- [x] FOUND BUG: Emergency Time-Out does not require the day time-in to be approved (only checks it is non-empty), unlike normal Time-Out. See Finding 4 (Medium). Reproduced live end-to-end.
- [ ] Duplicate time-in/time-out same-day double submission spam / race conditions - not yet fuzzed.
- [ ] Attendance Monitoring manual entry: duplicate date for same student - unique rule exists; not yet live-retested.
- [ ] Attendance Monitoring manual entry: time_out before time_in - after:time_in rule exists; not yet live-retested.

### 3.8 Internship Reports / Report Reviews
- [x] Read controllers fully.
- [ ] File upload edge cases: oversized file greater than 5MB, wrong mime type, mime-spoofed file, missing file - validation rule exists; not yet live-tested with actual multipart upload.
- [ ] Duplicate report same period and type - guardAgainstDuplicate exists; not yet live-retested.
- [ ] Edit/delete a report after it has been reviewed (status not pending) - abort_unless guard exists; not yet live-retested.
- [ ] Student A editing/deleting Student B report via direct ID - abort_unless guard exists; not yet live IDOR-retested (high priority, queued next).

### 3.9 Progress Monitoring
- [x] Read-only, role gate confirmed (3.1). No mutating routes exist - matches spec.

### 3.10 Notifications
- [x] markRead scoped with abort_unless ownership check - confirmed by code read.
- [ ] Live IDOR retest: User A marking User B notification as read via direct ID - logic looks correct, not yet live-confirmed.

### 3.11 Auth / Registration
- [x] Read StudentRegisteredUserController, RegisteredUserController.
- [x] Confirmed register routes are under guest middleware (an authenticated user cannot reach the self-registration POST handler, so the dead-route issue in 3.4/Finding 3 is UX-broken but not an account-takeover/security bug).
- [ ] Password reset flow edge cases (expired token, reused token, token for wrong email) - not yet tested.
- [ ] Duplicate student_number / email on self-registration - unique rules exist; not yet live-retested.

### 3.12 Responsive UI
- [ ] Not yet started (requires browser/viewport testing, queued for a later pass).

## 4. Findings log

### Finding 1 - Creating a Student-role user via User Management leaves no Student profile, crashing their own self-service pages
- Module: User Management / Attendance (student self-service) / Internship Reports (student self-service)
- Test case: Admin or Coordinator uses the Register User form on /user-management, selects role Student, submits. Then that new account logs in and visits /my-attendance or /my-reports.
- Result: FAIL
- Severity: High
- Bug description: RegisteredUserController::store() (bound to POST /user-management/create, used by UserManagement/Partials/RegistrationForm.jsx) creates a User row with any selected role_id, including 1 (Student), but never creates a corresponding Student row - only StudentRegisteredUserController (the separate public self-registration flow) does that. AttendanceController (index/timeIn/timeOut/emergencyTimeOut) and InternshipReportController (index/store/update/destroy) all do "student = Auth user student" and immediately call a relationship method on it with no null check, unlike DashboardController (which null-checks and renders a noProfile state) and EvaluationController::myFeedback (which also null-checks). The result is an uncaught Error: "Call to a member function attendances()/internshipReports() on null", i.e. an HTTP 500 for every page central to that account purpose.
- Steps to reproduce (reproduced live end-to-end against the running dev server):
  1. As Admin or Coordinator, create a user via /user-management with role Student (no student_number/course/section is ever collected by this form).
  2. Log in as that new user.
  3. /dashboard loads fine (shows a "no profile" state).
  4. Visit /my-attendance -> HTTP 500, "Call to a member function attendances() on null" in AttendanceController.php.
  5. Visit /my-reports -> HTTP 500, "Call to a member function internshipReports() on null" in InternshipReportController.php.
  6. Any POST to /my-attendance/time-in, /time-out, /emergency-time-out, or /my-reports would fail the same way.
- Expected result: Either (a) the User Management Student role option should require/collect the same profile fields as self-registration and create a Student row atomically, or (b) a Student-role user with no profile should get the same graceful "no profile" handling on every student-only page that DashboardController already gives it, instead of a 500.
- Actual result: Hard 500 error (Whoops debug page in this environment) on the two core student self-service pages; the account is effectively unusable, and in production with APP_DEBUG=false would show a blank/generic error page with no indication of the cause.
- Affected files:
  - app/Http/Controllers/AttendanceController.php (index around line 18-20, timeIn around 38, timeOut around 63, emergencyTimeOut around 99 - all "Auth user student" with no null guard)
  - app/Http/Controllers/InternshipReportController.php (index around 22-24, store around 37, update around 58, destroy around 86 - same pattern)
  - app/Http/Controllers/Auth/RegisteredUserController.php store() (root cause - does not create a Student row for role_id=1)
  - resources/js/Pages/UserManagement/Partials/RegistrationForm.jsx (lets an operator pick Student with no profile fields collected)
- Recommended fix: Simplest and safest: in RegisteredUserController::store(), if role_id equals 1, either (a) reject Student as a selectable role in this admin flow entirely (students should only self-register, which is arguably the intended design given StudentRegisteredUserController exists separately - remove role 1 from the options list this form sends, both server-side validation and the roles prop feeding the dropdown), or (b) collect student_number/course/section in this form and create the Student row in the same transaction, mirroring StudentRegisteredUserController::store(). Recommend (a) as the smaller, lower-risk fix. As defense-in-depth regardless of which is chosen, also add null-guards plus graceful redirects (or a noProfile Inertia state) in AttendanceController and InternshipReportController, matching the pattern already used in DashboardController/EvaluationController::myFeedback, so this class of account can never hard-crash even if created some other way in the future.
- Retest status: **PASS (session 2)** - fixed in commit `ffc0d25` (Student removed as a selectable/assignable role in the admin flow; `role_id=1` rejected with 422 on both create and promote-via-`update()`; defense-in-depth null guards added to AttendanceController/InternshipReportController). Independently retested live: role_id=1 rejected on both endpoints; editing an already-Student (self-registered) user without changing their role still works end-to-end, verified against a real self-registered student (id 3) - the update guard does not false-positive on unchanged-role edits.

### Finding 2 - Duplicate evaluation_criteria_id in an evaluation responses payload crashes with 500 instead of a validation error
- Module: Supervisor Evaluations (Evaluation module)
- Test case: Supervisor submits/updates an evaluation with two entries in responses[] sharing the same evaluation_criteria_id.
- Result: FAIL
- Severity: Medium
- Bug description: evaluation_responses has a DB-level unique constraint on (evaluation_id, evaluation_criteria_id) (good schema design), but EvaluationController::validateEvaluation() never enforces distinctness on responses.*.evaluation_criteria_id, and syncResponses() just loops and creates each entry without deduplication. A payload with a duplicate criteria id therefore passes validation, then throws an uncaught QueryException (SQLSTATE[23000]: Duplicate entry for key evaluation_responses_evaluation_id_evaluation_criteria_id_unique) mid-transaction. The DB::transaction() wrapper does correctly roll back (verified - no partial or corrupt data was left behind), but the request itself surfaces as an unhandled 500 rather than a normal 422 validation error.
- Steps to reproduce (reproduced live against the running dev server):
  1. Log in as a Supervisor (or Admin) with a draft evaluation they own.
  2. PATCH /supervisor-evaluations/ID with body including responses[0][evaluation_criteria_id]=21, responses[0][rating]=1, responses[1][evaluation_criteria_id]=21, responses[1][rating]=5 (same criteria id twice, different ratings).
  3. Observe HTTP 500 with SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry.
- Expected result: HTTP 422 with a clean validation message such as a duplicate-value error on responses.1.evaluation_criteria_id, form re-renders with the error, no exception in logs.
- Actual result: HTTP 500 unhandled exception; would show a generic error page to the Supervisor in production with no actionable feedback, and pollutes logs with SQL-level stack traces for what is really a validation problem.
- Affected files: app/Http/Controllers/EvaluationController.php validateEvaluation() (around line 214-234), syncResponses() (around 236-247); schema constraint at database/migrations/15_create_evaluation_responses_table.php.
- Recommended fix: Add a distinct rule to the nested field, e.g. responses.*.evaluation_criteria_id gets rules required, integer, exists:evaluation_criteria,id, distinct, so Laravel rejects duplicate values across the array before it ever reaches the DB layer.
- Retest status: **PASS (session 2)** - fixed in commit `1afe146` (`distinct` rule added to `responses.*.evaluation_criteria_id`). Independently retested live: duplicate submission now returns a clean 422 with a per-field message, no 500, no QueryException in laravel.log, original DB rows untouched; a legitimate non-duplicate follow-up submission still persists correctly and `overall_rating` recalculates as expected, confirming the new rule doesn't interfere with normal use.

### Finding 3 - GET /user-management/create is a dead route rendering the wrong page
- Module: User Management / Auth
- Test case: Navigate directly to /user-management/create as Admin or Coordinator.
- Result: FAIL (functional, not security)
- Severity: Low
- Bug description: RegisteredUserController::create() renders the Auth/Register Inertia component (the public self-registration page: name/email/student_number/course/section/password, no role selector) instead of anything resembling the actual working Register User flow, which lives inline on UserManagement/Index.jsx via UserManagement/Partials/RegistrationForm.jsx (posts to user-management.store with a role selector). Nothing in the app links to route user-management.create, confirmed via full grep of resources/js, so this is dead/orphaned but still routable. If reached directly, submitting the rendered form posts to route register, which is wrapped in guest middleware, so an authenticated Admin/Coordinator hitting submit is just redirected away. The page is a non-functional dead end, not an account-takeover risk: guest middleware blocks any authenticated user from reaching StudentRegisteredUserController::store(), so a scenario where it posts to the wrong controller and calls Auth::login cannot actually execute against an authenticated session.
- Steps to reproduce:
  1. Log in as Admin or Coordinator.
  2. Navigate to /user-management/create directly.
  3. Observe the self-registration page renders with no role field and no indication it is for admin-driven creation.
  4. Submitting it goes nowhere useful, blocked by guest middleware on the register POST route.
- Expected result: Either this route/controller method is removed entirely (creation already works fine inline on /user-management), or it renders the actual UserManagement creation form correctly.
- Actual result: Confusing dead-end page, unreachable from any UI affordance.
- Affected files: app/Http/Controllers/Auth/RegisteredUserController.php create(), routes/user.php (user-management.create route).
- Recommended fix: Remove the orphaned GET/POST /user-management/create routes and RegisteredUserController::create() method if the inline UserManagement/Index flow is the sole intended creation path. POST user-management.store is still needed and is correctly wired; only the GET create page and its wrong render target looks like leftover scaffolding.
- Retest status: **PASS (session 2)** - fixed in commit `ffc0d25` (route and `RegisteredUserController::create()` removed entirely; `POST /user-management/create` untouched). Independently retested live: `GET /user-management/create` now returns 405, the real POST create flow still works end-to-end.

### Finding 4 - Emergency Time-Out does not require an approved time-in, unlike normal Time-Out
- Module: Attendance (student self-service) / Attendance Approvals
- Test case: Student time-in for today is rejected. Student attempts normal time-out, then emergency time-out.
- Result: FAIL
- Severity: Medium
- Bug description: AttendanceController::timeOut() correctly blocks with a check requiring time_in_status equal to approved. AttendanceController::emergencyTimeOut() only checks that the record exists and time_in is non-empty; it never checks time_in_status. So a student whose time-in was explicitly rejected by their supervisor can still successfully submit an emergency time-out for that day, creating a pending time-out request that a supervisor could approve, producing a day with time_in_status=rejected but time_out_status=approved, a state the normal flow is specifically designed to prevent.
- Steps to reproduce (reproduced live end-to-end against the running dev server):
  1. Student times in; supervisor/admin rejects the time-in (time_in_status = rejected).
  2. Student POST /my-attendance/time-out, correctly blocked, no DB change, time_out_status stays null.
  3. Student POST /my-attendance/emergency-time-out with a note, succeeds; DB shows time_in_status=rejected, time_out_status=pending, is_emergency=1.
- Expected result: Emergency time-out should be gated the same way as normal time-out (require time_in_status equal to approved, or at minimum block when it is rejected). An emergency time-out is meant to cover forgetting to clock out, not to work around a supervisor rejection of the clock-in itself.
- Actual result: Emergency time-out succeeds regardless of time-in approval state, creating an inconsistent, approvable record for a day whose time-in was rejected.
- Affected files: app/Http/Controllers/AttendanceController.php emergencyTimeOut() (around line 93-127).
- Recommended fix: Add the same time_in_status equal to approved (or not equal to rejected) guard used in timeOut() to emergencyTimeOut(), with an appropriate error message such as requiring an approved time-in before an emergency time-out.
- Retest status: **PASS (session 2)** - fixed in commit `93f9ca5` (`emergencyTimeOut()` now blocks when `time_in_status === 'rejected'`, still allows it while `pending`). Independently retested live: emergency time-out still succeeds while time-in is merely pending (no overcorrection breaking the legitimate case), and is now blocked with a distinct error message when time-in was rejected; normal `timeOut()`'s separate rejection message is unaffected.

### Finding 5 - Internship Report attachments are served with zero authentication via the public storage symlink
- Module: Reports / Report Reviews
- Test case: Fetch a report attachment's URL with no authentication at all (bare `curl`, no session cookie).
- Result: FAIL
- Severity: Medium
- Bug description: Report attachments are stored on the public disk and served directly through Laravel's default `/storage/` symlink, with no route-level auth or ownership check in front of them at all. Confirmed live: a bare `curl` request (no cookie jar, not logged in as anyone) to an attachment URL returns HTTP 200 and the actual file content, completely bypassing every role/ownership check verified at the controller layer for the report itself (student-owns-report, supervisor-scoped-to-own-students, etc.). Mitigated in practice by filenames being random 40-character strings (not enumerable/guessable), so rated Medium rather than High/Critical - but it's a real authorization gap: anyone who obtains or guesses a URL (e.g. via a leaked link, browser history, referrer header, or a future enumeration bug) can read a student's report attachment with no authentication whatsoever.
- Steps to reproduce (reproduced live against the running dev server):
  1. As a student, upload a report with an attachment via the normal Internship Reports flow.
  2. Note the stored attachment's public URL (`/storage/<random-40-char-name>.<ext>`).
  3. From a bare `curl` with no cookies/session at all, request that URL directly.
  4. Observe HTTP 200 with the actual file content returned - no login, no role check, no ownership check.
- Expected result: Attachment downloads should require an authenticated session and the same ownership/role checks already enforced on the report itself (student owns it, or their supervisor/coordinator/admin per the existing Report Reviews access matrix) - a 401/403 for anyone else, authenticated or not.
- Actual result: Fully public, unauthenticated file access for anyone with the URL.
- Affected files: wherever `InternshipReportController` stores the upload (public disk) and whatever currently renders the raw `/storage/...` URL to the frontend (`InternshipReports`/`ReportReviews` pages).
- Recommended fix: Add an authenticated download route (e.g. `GET /my-reports/{report}/attachment` and/or `GET /report-reviews/{report}/attachment`) that re-checks the same ownership/role rules as the report itself, then streams the file (`Storage::download()`/`response()->file()`) instead of exposing the raw public-disk URL to the frontend at all. Consider moving the disk to `local` (private) rather than `public` so the symlink path is no longer reachable by URL guessing even as defense-in-depth.
- Retest status: **PASS (session 2)** - fixed in commit `8de50e6`. Independently retested live via curl across all 8 scenarios: private-disk storage confirmed, old raw `/storage/` URL unreachable, unauthenticated request blocked, owner download works, cross-student blocked, missing-attachment 404s, supervisor scoping correct, coordinator/admin full access. Full suite green (204 passed, 49 pre-existing known-dead Westpoint failures only).

### Note - Coordinator has full, unscoped access to Report Reviews across all companies
Confirmed live with two real supervisor accounts (each correctly seeing only their own students' reports) vs. a Coordinator account (seeing every report system-wide, not scoped to any company). Per the architecture-alignment plan earlier this session, this is **intentional, not a bug**: the resolved access matrix explicitly gives Coordinator/Admin full, unscoped access everywhere (only Supervisor is ever scoped to "own students"). No fix needed - noted here only so it isn't mistaken for a gap in a future QA pass.

### Finding 6 - Detaching a supervisor from a company roster permanently blocks unrelated edits to a student they're still assigned to
- Module: Company Management (Supervisor Roster) / Internship Assignment
- Test case: A student is assigned a supervisor who is on their company's roster. An Admin/Coordinator detaches that supervisor from the company's roster (without reassigning the student first). Someone then tries to edit any field on that student (e.g. just their name), without touching supervisor_id at all.
- Result: FAIL
- Severity: Medium
- Bug description: `InternshipAssignmentController::update()` re-validates that `supervisor_id` is on the target company's roster on **every** save of the consolidated student-edit form, not only when `supervisor_id` is actually being changed. Once a supervisor is detached from a company's roster while still assigned to one of that company's students (a state the detach action itself doesn't prevent or cascade-clear), the student's `supervisor_id` value fails the roster-membership re-check on the very next save, even for a save that doesn't touch `supervisor_id` at all - e.g. just correcting a typo in the student's name. The student record becomes stuck: uneditable via the normal form until someone manually re-adds the supervisor to the roster or explicitly reassigns the student to a different supervisor first.
- Steps to reproduce (reproduced live end-to-end):
  1. Company C has Supervisor S on its roster; Student X is assigned to Company C with Supervisor S.
  2. Admin/Coordinator detaches Supervisor S from Company C's roster (Student X's `supervisor_id` is left unchanged - the detach action doesn't cascade-clear or block on this).
  3. Admin/Coordinator attempts to edit Student X's record, changing only an unrelated field (e.g. name), `supervisor_id` submitted unchanged.
  4. Observe the save is rejected by the roster-membership validation rule, even though `supervisor_id` didn't change in this request.
- Expected result: Roster-membership validation should only apply when `supervisor_id` is actually being set/changed in the request (e.g. via a `sometimes`/conditional rule, or by comparing against the student's current value), so unrelated edits to an already-existing (if now-stale) assignment aren't blocked. Separately worth considering: should detaching a supervisor from a roster warn about or handle their currently-assigned students at all (e.g. block the detach, or prompt to reassign) - noted as a UX/business-rule question for the dev/user to decide, not dictating the fix.
- Actual result: Student record is permanently stuck/uneditable for any field until a supervisor-roster/assignment fix-up happens first, which isn't obvious from the error message alone.
- Affected files: `app/Http/Controllers/InternshipAssignmentController.php::update()` (roster-membership validation rule), `app/Http/Controllers/CompanyController.php` (supervisor detach action - no cascade/warning today).
- Recommended fix: Scope the roster-membership check to only run when `supervisor_id` is present and different from the student's current value (or use `sometimes` semantics), so saves that don't touch supervisor assignment aren't blocked by a stale roster state. Consider also whether `CompanyController`'s detach action should check for/warn about currently-assigned students before allowing the detach.
- Retest status: **PASS (session 2)** - fixed in commit `c55fe91`. Independently retested live: positive case (unrelated field edit now succeeds when supervisor/company are resubmitted unchanged after a roster drift) and negative case (reassigning to a genuinely different off-roster supervisor still correctly rejected with 422) both confirmed. QA restored the environment afterward (re-attached the test supervisor to the test company, reverted the test name change).

## 5. Queued for next session (priority order)
1. Responsive UI pass (in progress - session interrupted by an account-wide rate limit, resuming session 3).

## 6. Bugs reported to full-stack-developer
Findings 1 through 4 reported 2026-09-21 (session 1), fixed same day in commits `ffc0d25` (Findings 1 + 3), `93f9ca5` (Finding 4), `1afe146` (Finding 2), all independently retested live by QA in session 2 and confirmed PASS with no regressions.

Finding 5 (unauthenticated report-attachment access, Medium) found session 2, fixed same day in commit `8de50e6`, independently retested live by QA and confirmed PASS across all 8 scenarios.

Finding 6 (stale supervisor-roster validation blocks unrelated student edits, Medium) found session 2, fixed same day in commit `c55fe91`, independently retested live by QA and confirmed PASS on both the positive (unrelated edit succeeds) and negative (genuine reassignment to off-roster supervisor still rejected) cases.

**All 6 findings from this audit are now fixed and independently retested clean. No open bugs.**

Also cleared this session with no new bugs: cross-student/cross-supervisor IDOR on Report Reviews/Internship Reports, file-upload adversarial testing on Internship Reports attachments (oversized/wrong-mime/spoofed-extension all correctly rejected via real content-sniffing), evaluation rating-boundary and period-date edge cases, Analytics filter edge cases (malformed dates, negative/nonexistent IDs, cross-supervisor scope-widening attempts), full evaluation lock/reopen state machine, Company slot-capacity edge cases (negative/zero/non-numeric), remaining mutating-route spot-check sweep (evaluation-criteria, supervisor-evaluations submit/store - all correctly role-gated), Notifications cross-user IDOR (correctly scoped), password reset flow edge cases (valid reset invalidates old password immediately; wrong email/bogus token/reused token all correctly rejected with 422).

One non-bug FYI noted by QA, not filed as a finding: the "forgot password" endpoint reveals via a distinct error message whether an email exists in the system - this is stock Laravel Breeze scaffolding behavior, not app-specific code, flagged only as a possible future hardening item if ever wanted.

Session 2 was interrupted by an account-wide rate limit mid-way through the final queued item (responsive UI pass) - resuming as session 3.
---

## Session 3 - Responsive UI pass (final queued item)

Method: code inspection only (no browser/screenshot tool available to this QA agent) - checked Tailwind responsive classes, table/overflow wrappers, flex/grid direction changes, and modal/dialog height handling across the layout shell and every major page listed in scope, at conceptual phone (~375px), tablet (~768px), and desktop widths.

### 3.12 Responsive UI - completed this session

PASS - no issues found:
- Sidebar / mobile nav (resources/js/Components/app-sidebar.jsx, resources/js/Components/ui/sidebar.jsx, resources/js/hooks/use-mobile.jsx, resources/js/Components/site-header.jsx): AppSidebar uses collapsible="offcanvas" on the shadcn Sidebar primitive, which switches to a Radix Sheet overlay below the 768px breakpoint (useIsMobile(), MOBILE_BREAKPOINT = 768). SiteHeader always renders SidebarTrigger, so the toggle is reachable at every breakpoint - this is the standard shadcn mobile-sidebar pattern correctly wired up, not a desktop-only assumption.
- All data tables (Attendance Monitoring, Supervisor Evaluations list, Report Reviews, Notifications, Progress Monitoring, User Management, Company Management, Internship Assignment): every one is built on the shared resources/js/Components/ui/table.jsx Table primitive, which wraps <table> in a div with class "relative w-full overflow-auto" (line 6). Every table in the app already gets horizontal scroll for free on narrow viewports with no extra per-page work needed - confirmed via the shared DataTable.jsx (Attendance Monitoring/Internship Assignment/Progress Monitoring) plus inline Table usage on SupervisorEvaluations/Index.jsx, ReportReviews/Index.jsx, Notifications/Index.jsx.
- Dashboard KPI grids (AdminSummary.jsx, CoordinatorSummary.jsx, SupervisorSummary.jsx): grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 - correctly single-column below 640px.
- Dashboard charts (InternshipCharts.jsx, AttendanceCharts.jsx, EvaluationCharts.jsx, ReportsCharts.jsx): grid grid-cols-1 lg:grid-cols-3 combined with shadcn's ChartContainer (wraps Recharts ResponsiveContainer, resources/js/Components/ui/chart.jsx line 40) - charts fill their column correctly at every width, single column on mobile/tablet.
- Dashboard root container (Dashboard/Index.jsx): mx-auto w-full min-w-0 max-w-full px-4 sm:px-6 lg:px-8 - the explicit min-w-0 guards against flex/grid children forcing horizontal page overflow, good practice.
- Analytics FilterBar (Dashboard/Partials/FilterBar.jsx): flex flex-wrap items-end gap-3 with each filter min-w-[10rem] - wraps cleanly instead of overflowing on narrow widths.
- Supervisor Evaluations create form (EvaluationFormFields.jsx, CriteriaRatingGroup.jsx): rendered inline on the page (not height-constrained), sm:grid-cols-2 fields, flex flex-wrap rating-score buttons - stacks/wraps correctly, page itself scrolls.
- Two modals already implement a correct defensive internal-scroll pattern for tall content: InternshipAssignment/Partials/StudentModal.jsx (max-h-[65vh] overflow-y-auto around the field list, header/footer stay pinned) and AttendanceMonitoring/Partials/LogModal.jsx (max-h-[50vh] overflow-y-auto around the attendance table). These are the two largest-content modals in the app and both were built correctly.

FOUND - Finding 7, see section 4 below.

Explicitly needs real browser/viewport confirmation, not just code inspection (flagging per instructions rather than guessing pass/fail):
- The exact pixel height at which any given modal in Finding 7 actually starts clipping content depends on real font metrics, browser chrome height, and OS-level viewport quirks (iOS Safari's dynamic toolbar, Android Chrome's URL bar collapse behavior) - the code-level analysis below establishes that the risk exists and roughly which modals are worst, but not the precise breakpoint. A real device/emulator pass would be needed to confirm.
- Tailwind CSS is compiled via Vite/JIT from source, so class presence in the .jsx files reflects what will ship, but actual rendered layout (font wrapping, exact line-heights) can only be confirmed visually.

### 4. Findings log (addendum)

### Finding 7 - Most modals have no internal scroll/max-height guard, risking unreachable content on short viewports
- Module: Company Management (Add/Edit Company, Supervisor Roster), User Management (Edit User), Internship Reports (Edit Report), Report Reviews (Review), Evaluation Criteria (Add/Edit) - i.e. every Dialog-based modal in the app except StudentModal.jsx and LogModal.jsx.
- Test case (code inspection): compare every DialogContent-based modal's content height against the base DialogContent primitive's CSS.
- Result: FAIL (structural risk, not yet visually reproduced - see caveat above)
- Severity: Medium for CompanyManagement/Partials/AddModal.jsx / EditModal.jsx (7 fields, no scroll wrapper - the most content-heavy of the group) and CompanyManagement/Partials/SupervisorRosterModal.jsx (severity scales with roster size, not just viewport - will eventually break for any company with a long enough supervisor roster, independent of device); Low for the remaining smaller modals (UserManagement/Partials/EditModal.jsx, InternshipReports/Partials/EditReportModal.jsx, ReportReviews/Partials/ReviewModal.jsx, EvaluationCriteria/Partials/AddModal.jsx and EditModal.jsx) since their content is shorter and more likely to fit typical phone-portrait viewport heights.
- Bug description: The base DialogContent component (resources/js/Components/ui/dialog.jsx lines 26-44) has no max-height or overflow-y-auto of its own - it is fixed-positioned, w-full, max-w-lg, p-6, with no height constraint at all. Radix Dialog (at version ^1.1.15, confirmed in package.json) locks page/body scroll by default while a dialog is open. So if a given modal's total content height (header + fields + footer) exceeds the visible viewport height, the overflowing portion becomes genuinely unreachable: there is no scrollbar anywhere (not on the modal, not on the page behind it) to get to it. Two modals in the codebase already defend against this correctly with their own max-h-[Xvh] overflow-y-auto wrapper around the scrollable part while pinning header/footer (StudentModal.jsx, LogModal.jsx) - proving the team is aware of the pattern, it is just not applied consistently everywhere it is needed.
- Steps to reproduce (code-level; needs a real short-viewport browser/emulator pass to visually confirm the exact clipping point):
  1. Open CompanyManagement/Partials/AddModal.jsx (or EditModal.jsx) on a narrow, short viewport - e.g. a phone in landscape orientation (~375-415px tall), or any viewport shorter than roughly 650-700px once browser chrome is accounted for.
  2. The modal renders all 7 fields (Company Name, Address, Contact Person, Contact Number, Email, Industry, Internship Slots) plus header and footer with no internal scroll container.
  3. If total rendered height exceeds the viewport, the Submit/Cancel footer buttons (and/or the last field(s)) are pushed below the visible area, with no scrollbar on the modal or the page (Radix has locked body scroll) to reach them.
  4. Separately, for SupervisorRosterModal.jsx: attach enough supervisors to a single company's roster (no artificial limit in the UI/backend) - the roster list block has no max-h/overflow-y-auto of its own, so a long roster pushes the "Add Supervisor" form and "Close" button down with the same unreachable-footer risk, regardless of device height.
- Expected result: Every modal's scrollable content (fields list, roster list, etc.) should be wrapped the same way StudentModal.jsx/LogModal.jsx already do - a max-h-[Xvh] overflow-y-auto (or similar) around the variable-height content, with DialogHeader/DialogFooter staying pinned outside that wrapper - so the footer action buttons are always reachable regardless of viewport height or list length.
- Actual result: No such guard on 7 of the app's ~9 modals; on a sufficiently short viewport (or, for the roster modal, a sufficiently long roster) the primary action button becomes unreachable with no way to scroll to it.
- Affected files: resources/js/Components/ui/dialog.jsx (base primitive, no max-height by default), resources/js/Pages/CompanyManagement/Partials/AddModal.jsx, resources/js/Pages/CompanyManagement/Partials/EditModal.jsx, resources/js/Pages/CompanyManagement/Partials/SupervisorRosterModal.jsx, resources/js/Pages/UserManagement/Partials/EditModal.jsx, resources/js/Pages/InternshipReports/Partials/EditReportModal.jsx, resources/js/Pages/ReportReviews/Partials/ReviewModal.jsx, resources/js/Pages/EvaluationCriteria/Partials/AddModal.jsx, resources/js/Pages/EvaluationCriteria/Partials/EditModal.jsx.
- Recommended fix: Apply the same max-h-[65vh] overflow-y-auto (or a viewport-relative value tuned per modal) wrapper already used in StudentModal.jsx/LogModal.jsx to the variable-height content block in each of the modals listed above, keeping DialogHeader and DialogFooter outside/pinned. For SupervisorRosterModal.jsx specifically, wrap just the roster list div (space-y-2) in its own bounded-height scroll container (e.g. max-h-[40vh] overflow-y-auto) since that is the part whose height is data-driven and unbounded. Lowest-effort alternative: add a sane default (e.g. max-h-[85vh] overflow-y-auto) directly to the base DialogContent in dialog.jsx so every current and future modal gets the guard automatically - but that is a shared-component change worth a quick regression pass across all modals since it changes their default sizing behavior everywhere at once.
- Retest status: **fix landed (commit `d96c448`), awaiting QA's independent retest/visual confirmation.** Same `max-h-[Xvh] overflow-y-auto` wrapper pattern from `StudentModal.jsx`/`LogModal.jsx` applied to all 8 flagged files (`SupervisorRosterModal.jsx` wraps just the roster list, per the recommendation). Dev also grepped for every `DialogContent`-based modal to confirm nothing else was missed - found one more not on QA's list (`AttendanceMonitoring/Partials/RequiredHoursModal.jsx`, single label+input, smaller than any flagged Low-severity entry) and deliberately left it untouched as out of scope for this finding. `npm run build` clean, full PHP suite unaffected (pure frontend change). Exact visual clipping thresholds still unconfirmed by either agent (neither has a browser/screenshot tool) - the structural fix matches the two known-good reference modals exactly.

## 5. Queued for next session (priority order)
1. Responsive UI pass - DONE this session (session 3), see 3.12 above. One new finding (Finding 7, Low/Medium) opened, reported to devfix-batch1, not yet fixed/retested.

## 6. Bugs reported to full-stack-developer (addendum)

Finding 7 (modal dialogs missing internal max-height/scroll guard on short viewports or long data lists - Medium for Company Management Add/Edit/Supervisor Roster, Low for the remaining smaller modals) found session 3 via code inspection, reported to devfix-batch1 2026-09-22. Not yet fixed/retested - this is the only open item as of the end of session 3.

## 7. Final status (session 3 close-out)

Module inventory: 15 protected route groups / feature modules covering Auth, Dashboard+Analytics, User Management, Company Management, Internship Assignment, Attendance (self-service + monitoring + approvals), Internship Reports + Report Reviews, Progress Monitoring, Supervisor Evaluations + Evaluation Criteria + My Feedback, Notifications, Profile (full list and role matrix in section 1).

Total findings across the full audit: 7.
- Findings 1-6: all fixed same-day by the developer and independently retested clean by QA (functionality/validation, authorization, cross-supervisor IDOR, business-rule edge cases, unauthenticated file access). Zero open issues among these.
- Finding 7 (this session, responsive UI / modal overflow risk): open, reported to devfix-batch1, not yet fixed or retested. Severity Medium (Company Management Add/Edit, Supervisor Roster modal) / Low (remaining smaller modals) - a usability degradation on short viewports or companies with long supervisor rosters, not a data-integrity, security, or authorization issue, and not a full feature blocker (the rest of every page renders and functions correctly at all three tested widths).

Coverage completed across all sessions: full GET-level and representative mutating-route authorization matrix sweep across all 4 roles; cross-supervisor/cross-student IDOR probing (Evaluations, Attendance Approvals, Attendance Monitoring, Report Reviews, Internship Reports, Notifications); adversarial validation probing (duplicate payload entries, rating/date boundaries, file-upload spoofing, slot-capacity edge cases, lock/reopen state machine, roster-membership staleness); password-reset edge cases; full existing automated test suite baseline (182 SIMS tests passing, 49 pre-existing known-dead Westpoint failures unrelated to this audit); and this session's responsive/UI code-inspection pass across every major page and modal.

Status: Audit substantively complete. One open, non-blocking finding (Finding 7) remains pending a developer fix and QA retest; once that lands, the audit should be considered fully closed with zero open items.
## 8. Finding 7 retest (session 3, continued)

Retested independently after devfix-batch1's fix (commit d96c448), by direct code review (not trusting the commit message alone) plus a clean build/test run:

- Read all 8 modified files in full: CompanyManagement/Partials/AddModal.jsx, CompanyManagement/Partials/EditModal.jsx, CompanyManagement/Partials/SupervisorRosterModal.jsx, UserManagement/Partials/EditModal.jsx, InternshipReports/Partials/EditReportModal.jsx, ReportReviews/Partials/ReviewModal.jsx, EvaluationCriteria/Partials/AddModal.jsx, EvaluationCriteria/Partials/EditModal.jsx. Confirmed each now wraps its variable-height content in max-h-[65vh] overflow-y-auto (max-h-[40vh] for the data-driven SupervisorRosterModal roster list specifically, per the recommendation that one scales with data not viewport), with DialogHeader/DialogFooter correctly left pinned outside the scroll wrapper in every case - byte-for-byte the same pattern as the two known-good reference modals (StudentModal.jsx, LogModal.jsx).
- Confirmed AttendanceMonitoring/Partials/RequiredHoursModal.jsx was correctly left untouched (single label+input, smaller than any flagged modal, outside the scope of Finding 7).
- Ran npm run build independently: builds clean, no errors, across all 8 edited files.
- Ran php artisan test independently: 206 passed, 49 failed - all 49 failures confirmed to be the pre-existing known-dead Westpoint test files (Class App\Models\Branch not found, etc.), zero SIMS regressions, same baseline as every prior session.
- As previously flagged, the exact pixel viewport height where clipping would have occurred pre-fix was never visually confirmed (no browser tool available to either QA or dev) - but that was a caveat on the finding's precision, not on whether the fix is structurally correct, and the fix is structurally correct and complete against 100% of the files named in the finding.

Result: **PASS.** Finding 7 fixed and independently retested clean.

## 9. Full-system QA audit - CLOSED

All 7 findings across all 3 sessions are now fixed and independently retested clean. Zero open findings.

Final tally: Findings 1-6 (functionality/validation, authorization matrix, cross-supervisor IDOR, business-rule edge cases, unauthenticated file access) fixed same-day each, retested clean in session 2. Finding 7 (responsive UI - modal scroll guard) fixed same-day, retested clean in session 3.

The full-system QA audit is complete: authorization matrix (all 4 roles x all protected routes, GET and mutating), cross-supervisor/cross-student IDOR, adversarial validation and edge-case probing, password-reset flow, the existing automated suite baseline, and this session's responsive/UI pass have all been covered with no remaining open items.
