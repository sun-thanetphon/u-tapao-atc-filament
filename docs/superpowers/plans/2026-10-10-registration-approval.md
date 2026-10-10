# Registration, Approval and Password Reset Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let staff self-register into a `pending` state that only `super-admin`/`admin` can approve, and let users recover forgotten passwords by email or by an admin-assisted request.

**Architecture:** A `status` column on `users` gates panel access on top of the existing Spatie roles. Registration, password-reset request and reset pages are Filament auth pages extending the existing custom login look. Approval and admin-assisted reset live inside Filament resources guarded by a new `user.approve` permission. All email goes through one fail-safe helper so SMTP failure never breaks a flow.

**Tech Stack:** Laravel 12, Filament 3.3, spatie/laravel-permission 6, PHPUnit 11 with Livewire test helpers, MySQL (tests on `db_u_tapao_test`).

**Spec:** `docs/superpowers/specs/2026-10-10-registration-approval-design.md`

## Global Constraints

- Work on branch `feature/registration-approval` (branch from `main`); commit after every task.
- Production is shared hosting: no `npm run build`, no queue worker. Send mail synchronously. Do not add front-end build steps; any styling goes in `resources/css/filament-theme.css` (plain CSS, inlined by `AdminPanelProvider`).
- Run tests inside Docker: `docker compose exec app php artisan test --filter=<Name>`. `tests/TestCase.php` refuses databases not ending in `_test`.
- Migrations must never delete or rewrite existing user data. Existing users become `status = active`.
- `status`, `approved_by`, `approved_at`, `rejected_reason`, `must_change_password` must NOT be in `User::$fillable`. `email` is added to `$fillable`.
- `username` and `email` uniqueness is enforced in code ignoring soft-deleted rows: `Rule::unique('users', col)->whereNull('deleted_at')`. No DB unique index (SoftDeletes).
- Password minimum 8 characters. Role at approval: only `RoleEnum::USER` or `RoleEnum::ADMIN`, never `RoleEnum::SUPERADMIN`.
- Rate limit 3 attempts per hour (register: per IP; reset request: per IP).
- Forgot-password responses are identical whether or not the account exists. Reset link TTL 60 minutes, single use.
- SMTP: `MAIL_PORT=587`, `MAIL_SCHEME=null`, host `smtp.hostinger.com`, from `noreply@support.u-tapaoatc.com`. Mail timeout 10 seconds. Tests use `MAIL_MAILER=array` (already in `phpunit.xml`).
- `User` with `id == 1` is protected: non-super-admins cannot reset or alter it.
- Existing helper `Tests\TestCase::makeUser(int $sectionId, string $role)` creates active users; use it. Extend it only as stated in Task 1.
- UI text is Thai, matching existing screens.

## Review Focus

- Username differing only by case or surrounding spaces from an existing one (`" Tower01 "` vs `tower01`): must be rejected as duplicate (trim first; MySQL collation is case-insensitive). Pinned in Task 3.
- An already logged-in user whose status becomes `rejected` (or whose role is removed): next panel request must be denied, not served from the live session. Pinned in Task 1.
- Approving or notifying a user who has no email (all legacy users): must succeed with no mail and no error. Pinned in Task 4.
- Password-reset request for a soft-deleted or non-active account, or an email shared with nobody: same response, no mail sent. Pinned in Task 5 and Task 6.
- A user holding a temporary password opening any other panel URL directly: must be redirected to the change-password page, and logout must still work. Pinned in Task 6.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Enums/UserStatus.php` (new) | `pending` / `active` / `rejected` constants |
| `database/migrations/2026_10_10_000001_add_registration_columns_to_users_table.php` (new) | new `users` columns, duplicate-username guard |
| `database/migrations/2026_10_10_000002_create_password_reset_requests_table.php` (new) | admin-assisted reset queue |
| `database/migrations/2026_10_10_000003_add_user_approve_permission.php` (new) | create `user.approve`, grant to super-admin/admin |
| `app/Models/User.php` (modify) | fillable email, status helpers, `canAccessPanel` status check |
| `app/Models/PasswordResetRequest.php` (new) | model for the queue |
| `app/Support/SafeMail.php` (new) | send mail, swallow and log failure |
| `app/Mail/*.php` (new) | `NewRegistrationMail`, `RegistrationApprovedMail`, `RegistrationRejectedMail` |
| `app/Providers/Filament/Auth/CustomLogin.php` (modify) | status-aware login messages |
| `app/Providers/Filament/Auth/CustomRegister.php` (new) | registration page |
| `app/Providers/Filament/Auth/CustomRequestPasswordReset.php` (new) | email reset request + admin-help request |
| `app/Providers/Filament/Auth/CustomResetPassword.php` (new) | set new password from link, kill old sessions |
| `app/Filament/Resources/UserResource.php` + `UserResource/Pages/ListUsers.php` (modify) | tabs, badge, approve/reject actions, email field |
| `app/Filament/Resources/PasswordResetRequestResource.php` (+ `Pages/ListPasswordResetRequests.php`) (new) | admin handles reset requests |
| `app/Http/Middleware/ForcePasswordChange.php` (new) | redirect when `must_change_password` |
| `app/Providers/Filament/Profile/ProfileEditCustom.php` (modify) | email field, clear `must_change_password` on save |
| `app/Providers/Filament/AdminPanelProvider.php` (modify) | register pages, middleware, login/register links |
| `app/Enums/PermissionEnum.php`, `database/seeders/RolePermissionSeeder.php` (modify) | `USER_APPROVE` |
| `tests/Feature/Registration*.php`, `Approval*.php`, `PasswordReset*.php` (new) | tests per task |

---

### Task 1: Status model, schema and panel gating

**Files:**
- Create: `app/Enums/UserStatus.php`, migration `..._add_registration_columns_to_users_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/UserStatusTest.php`

**Interfaces:**
- Produces: `UserStatus::PENDING|ACTIVE|REJECTED` (string consts `'pending'|'active'|'rejected'`); `User::isActive(): bool`; `User::scopeStatus(Builder $q, string $status)`; `canAccessPanel` now also requires `isActive()`; `users` columns `email` (nullable), `status` (default `active`), `approved_by`, `approved_at`, `rejected_reason`, `must_change_password` (bool default false).

- [ ] **Step 1: Write failing tests** in `UserStatusTest` (use `RefreshDatabase`, `seedReferenceData()` in `setUp`, `Filament::setCurrentPanel(Filament::getPanel('admin'))`):
  - `test_new_users_default_to_active` — `makeUser(...)->fresh()->status === 'active'`.
  - `test_pending_user_with_role_cannot_access_panel` — set status `pending` via `forceFill()->save()`; `canAccessPanel($panel)` is false.
  - `test_rejected_active_session_is_denied_on_next_request` — `actingAs($user)->get('/admin')` returns 200; then `forceFill(['status'=>'rejected'])->save()`; second `get('/admin')` is not 200 (redirect to login or 403).
  - `test_status_and_approval_columns_are_not_mass_assignable` — `User::create([... , 'status' => 'pending'])` yields `status === 'active'`.
- [ ] **Step 2: Run** `docker compose exec app php artisan test --filter=UserStatusTest` — expect FAIL (unknown column/constant).
- [ ] **Step 3: Implement** the enum, migration and `User` changes. Migration: before altering, abort with an exception listing duplicate usernames (case-insensitive, `deleted_at IS NULL`) if any exist; add columns; backfill is unnecessary because default is `active`. In `User`: add `email` to `$fillable`, cast `approved_at` to datetime and `must_change_password` to boolean, `canAccessPanel` = `isActive() && hasRole([...])`.
- [ ] **Step 4: Run** the test class and the full suite (`php artisan test`) — expect PASS, no regressions.
- [ ] **Step 5: Commit** `feat: add user status columns and gate panel access on status`.

### Task 2: `user.approve` permission and fail-safe mail helper

**Files:**
- Create: migration `..._add_user_approve_permission.php`, `app/Support/SafeMail.php`, `app/Mail/NewRegistrationMail.php`, `app/Mail/RegistrationApprovedMail.php`, `app/Mail/RegistrationRejectedMail.php`
- Modify: `app/Enums/PermissionEnum.php` (`USER_APPROVE = 'user.approve'`), `database/seeders/RolePermissionSeeder.php`
- Test: `tests/Feature/ApprovePermissionTest.php`, `tests/Feature/SafeMailTest.php`

**Interfaces:**
- Produces: `PermissionEnum::USER_APPROVE`; `SafeMail::send(string $to, \Illuminate\Mail\Mailable $mail): bool` (true if sent, false if SMTP threw; logs `Log::warning`); mailables constructed as `new NewRegistrationMail(User $applicant)`, `new RegistrationApprovedMail(User $user, string $role)`, `new RegistrationRejectedMail(User $user, ?string $reason)`.

- [ ] **Step 1: Write failing tests.** `ApprovePermissionTest`: after `seedReferenceData()`, `admin` and `super-admin` users `can('user.approve')`, plain `user` cannot. Also assert running the migration `up()` on an already-seeded DB does not throw (idempotent: use `findOrCreate`). `SafeMailTest`: `Mail::fake()`, `SafeMail::send('a@b.test', new NewRegistrationMail($u))` returns true and `Mail::assertSent(NewRegistrationMail::class)`; with `Mail::shouldReceive('to->send')->andThrow(new \RuntimeException)` it returns false and does not throw.
- [ ] **Step 2: Run** both classes — expect FAIL.
- [ ] **Step 3: Implement.** Migration uses `Permission::findOrCreate`, grants only to roles that exist (fresh test DBs have no roles at migration time; the seeder covers them), and calls `app(PermissionRegistrar::class)->forgetCachedPermissions()`. Seeder: `Permission::findOrCreate(USER_APPROVE)` and add it to `$roleAdmin->givePermissionTo` list (super-admin is covered by `Gate::before`, but also grant it explicitly if the role has permissions listed). `SafeMail::send` wraps `Mail::to($to)->send($mail)` in try/catch `Throwable`. Mailables: Thai subject/body with app name, simple markdown view or inline text; Approved includes login URL `route('filament.admin.auth.login')`.
- [ ] **Step 4: Run** both classes and full suite — expect PASS.
- [ ] **Step 5: Commit** `feat: add user.approve permission and fail-safe mail helper`.

### Task 3: Registration page

**Files:**
- Create: `app/Providers/Filament/Auth/CustomRegister.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (`->registration(CustomRegister::class)`)
- Test: `tests/Feature/RegistrationTest.php`

**Interfaces:**
- Consumes: `UserStatus`, `SafeMail`, `NewRegistrationMail` (Tasks 1–2), `PermissionEnum::USER_APPROVE`.
- Produces: page class `CustomRegister` extending `Filament\Pages\Auth\Register`; form state keys `rank_id, section_id, username, firstname, lastname, email, password, passwordConfirmation, website` (`website` = honeypot, must be empty).

- [ ] **Step 1: Write failing tests** (Livewire::test(CustomRegister::class), guest, `Mail::fake()`):
  - `test_valid_registration_creates_pending_user_without_role_and_does_not_log_in` — assert DB row with `status = pending`, `$user->roles` empty, `auth()->check()` false, row's `email` saved.
  - `test_duplicate_username_is_rejected_ignoring_case_and_spaces` — existing `tower01`; submitting `" Tower01 "` has form error on `username`.
  - `test_username_of_soft_deleted_user_can_be_reused`.
  - `test_duplicate_email_is_rejected`.
  - `test_role_and_status_cannot_be_injected` — `set('data.status', 'active')`/`set('data.role', 'admin')` has no effect; created user still `pending`, no role.
  - `test_password_must_be_8_chars_and_confirmed`.
  - `test_filled_honeypot_creates_no_user`.
  - `test_fourth_registration_from_same_ip_is_rate_limited`.
  - `test_approvers_with_email_are_notified` — an admin with email gets `NewRegistrationMail`; an admin without email does not break registration.
  - `test_mail_failure_does_not_block_registration` — SMTP exception still yields the pending user.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement** `CustomRegister`: `protected static string $layout = 'filament.layouts.login'`; `getForms()` with `Select` for `rank_id`/`section_id` (relationships, required), trimmed `username`/`email` (`->dehydrateStateUsing(trim)`), password pair, hidden honeypot `TextInput::make('website')`; override `register(): ?RegistrationResponse` to apply rate limit (`$this->rateLimit(3)` keyed by IP is already the Filament behavior; verify it is hourly or use `RateLimiter` with 3600s decay), create the user with `status` set explicitly in code, notify approvers via `SafeMail`, then redirect to a success state (render a "สมัครสำเร็จ รอผู้ดูแลอนุมัติ" notification or view) without calling `Filament::auth()->login`.
- [ ] **Step 4: Run** `RegistrationTest` and full suite — expect PASS.
- [ ] **Step 5: Commit** `feat: add self-registration into pending status`.

### Task 4: Approval workflow in UserResource and status-aware login

**Files:**
- Modify: `app/Filament/Resources/UserResource.php`, `app/Filament/Resources/UserResource/Pages/ListUsers.php`, `app/Providers/Filament/Auth/CustomLogin.php`
- Test: `tests/Feature/ApprovalTest.php`, `tests/Feature/LoginStatusTest.php`

**Interfaces:**
- Consumes: Tasks 1–3 interfaces.
- Produces: table actions named `approve` (form field `role`, options `['user' => ..., 'admin' => ...]`, default `user`), `reject` (form field `reason`, optional), `reopen` (rejected → pending), bulk action `approveSelected` (field `role`); `ListUsers::getTabs()` keys `pending`, `active`, `rejected`; `UserResource::getNavigationBadge(): ?string` (count of pending, only for users who can `user.approve`).

- [ ] **Step 1: Write failing tests** (`Livewire::test(ListUsers::class)` as acting user):
  - `test_admin_and_super_admin_can_approve_with_chosen_role` — status becomes `active`, `approved_by`/`approved_at` set, role assigned (`user` and `admin` both covered), approved mail sent when the user has an email.
  - `test_approve_without_email_succeeds_without_mail` (legacy shape: `email` null; `Mail::assertNothingSent()`).
  - `test_plain_user_cannot_see_or_call_approve` (`assertTableActionHidden`, and calling it is forbidden).
  - `test_super_admin_role_is_not_an_option_and_is_rejected_server_side` — `callTableAction('approve', $u, ['role' => 'super-admin'])` has form error / no role assigned.
  - `test_only_pending_rows_can_be_approved` — second approval of an already-active user changes nothing and notifies "handled".
  - `test_reject_stores_reason_status_and_sends_mail`; `test_reopen_moves_rejected_back_to_pending`.
  - `test_bulk_approve_applies_one_role_to_all_selected_pending_users`.
  - `test_tabs_and_navigation_badge_count_pending` (badge null for users without `user.approve`).
  - `LoginStatusTest` (`Livewire::test(CustomLogin::class)`): correct password with `pending` shows the "รอการอนุมัติ" message; `rejected` shows the "ไม่ได้รับอนุมัติ ... ติดต่อผู้ดูแลระบบ" message; wrong password for a pending user shows only the generic failure message; active user logs in as before.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.** Approve/reject use a single guarded update `User::where('id',$id)->where('status','pending')->update([...])` and treat 0 affected rows as "already handled". Check `can(PermissionEnum::USER_APPROVE)` inside each action's `authorize`/`visible`. Role validation uses `Rule::in([RoleEnum::USER, RoleEnum::ADMIN])`. Mail only when `filled($user->email)`, via `SafeMail`; on `false` send a Filament warning notification "ส่งอีเมลไม่สำเร็จ". `CustomLogin::authenticate()`: after credentials succeed, branch on status BEFORE completing login (log out, then throw the status-specific validation message); keep the existing generic message for bad credentials.
- [ ] **Step 4: Run** both classes and full suite — expect PASS.
- [ ] **Step 5: Commit** `feat: add approval tabs, actions and status-aware login`.

### Task 5: Password reset by email

**Files:**
- Create: `app/Providers/Filament/Auth/CustomRequestPasswordReset.php`, `app/Providers/Filament/Auth/CustomResetPassword.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (`->passwordReset(CustomRequestPasswordReset::class, CustomResetPassword::class)`)
- Test: `tests/Feature/PasswordResetEmailTest.php`

**Interfaces:**
- Consumes: `UserStatus`, `User::isActive()`.
- Produces: `CustomRequestPasswordReset` form key `email`; `CustomResetPassword` invalidates the user's other sessions (delete rows in `sessions` where `user_id`, rotate `remember_token`).

- [ ] **Step 1: Write failing tests:**
  - `test_active_user_with_email_receives_reset_link` (`Notification::fake()` or `Mail::fake()` depending on the Filament notification used; assert sent once).
  - `test_unknown_email_gets_same_response_and_no_mail`.
  - `test_pending_rejected_and_soft_deleted_users_get_same_response_and_no_mail`.
  - `test_link_works_once` — reset succeeds, password changes (`Hash::check`), second use of the token fails with the expired/used message.
  - `test_reset_invalidates_existing_sessions` — insert a `sessions` row for the user, reset, row gone and `remember_token` changed.
  - `test_expired_token_after_61_minutes_is_rejected` (`Carbon::setTestNow`).
  - `test_fourth_request_from_same_ip_is_rate_limited`.
  - `test_page_shows_check_spam_hint` (assert text "สแปม" on the request page).
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement** by extending the Filament classes. Use Laravel's `Password::broker()` with config `auth.passwords.users.expire = 60`; override the request action so non-active/unknown accounts short-circuit to the same success notification without calling the broker. Login layout `filament.layouts.login`. Wrap the broker's mail send so an SMTP exception is logged and the user still sees the same neutral message.
- [ ] **Step 4: Run** the class and full suite — expect PASS.
- [ ] **Step 5: Commit** `feat: add password reset by email`.

### Task 6: Admin-assisted reset and forced password change

**Files:**
- Create: migration `..._create_password_reset_requests_table.php`, `app/Models/PasswordResetRequest.php`, `app/Filament/Resources/PasswordResetRequestResource.php` (+ `Pages/ListPasswordResetRequests.php`), `app/Http/Middleware/ForcePasswordChange.php`
- Modify: `CustomRequestPasswordReset.php` (second action), `AdminPanelProvider.php` (`authMiddleware` + resource registration is automatic via discovery), `ProfileEditCustom.php`
- Test: `tests/Feature/PasswordResetByAdminTest.php`

**Interfaces:**
- Consumes: Tasks 1, 2, 5.
- Produces: `PasswordResetRequest` (`user_id`, `status` `open|done`, `handled_by`, `handled_at`; `belongsTo(User)`); request form key `username`; resource actions `sendLink` and `setTemporaryPassword` (shows generated password once); `ForcePasswordChange::handle($request, Closure $next)` redirecting to the profile route when `must_change_password` is true, except for the profile route and logout.

- [ ] **Step 1: Write failing tests:**
  - `test_request_by_username_creates_one_open_request_and_repeat_reuses_it`.
  - `test_unknown_or_non_active_username_gets_same_response_and_creates_nothing`.
  - `test_fourth_request_is_rate_limited`.
  - `test_admin_sets_temporary_password` — password changes, `must_change_password` true, request `done` with `handled_by`/`handled_at`, the generated password is shown in the action result once and never appears in `storage/logs` (assert log file does not contain it).
  - `test_admin_can_send_link_only_if_user_has_email`.
  - `test_plain_user_cannot_see_or_open_the_resource` and badge visible only to `user.approve` holders.
  - `test_admin_cannot_reset_super_admin_id_1` (`id == 1` action hidden/forbidden for non-super-admin).
  - `test_temp_password_user_is_redirected_from_any_panel_url` — `get('/admin')` redirects to the profile page; logout route still works.
  - `test_changing_password_on_profile_clears_must_change_password`.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement.** Temporary password via `Str::password(12)`; store through the model's `hashed` cast; show it once via `Notification` or action modal. The middleware reads `Filament::auth()->user()` and compares route names. `ProfileEditCustom`: override `mutateFormDataBeforeSave` (or `afterSave`) to set `must_change_password = false` when a password was provided; require the password field when `must_change_password` is true.
- [ ] **Step 4: Run** the class and full suite — expect PASS.
- [ ] **Step 5: Commit** `feat: add admin-assisted password reset and forced password change`.

### Task 7: Email field for existing users, final regression and deploy notes

**Files:**
- Modify: `app/Providers/Filament/Profile/ProfileEditCustom.php` (email input), `app/Filament/Resources/UserResource.php` (email input in the form)
- Test: `tests/Feature/UserEmailTest.php`

**Interfaces:**
- Consumes: all earlier tasks.

- [ ] **Step 1: Write failing tests:** user can add own email on the profile page; duplicate email (ignoring soft-deleted) is rejected on both profile and `UserResource` edit; admin can set a legacy user's email; invalid format rejected.
- [ ] **Step 2: Run** — expect FAIL.
- [ ] **Step 3: Implement** a nullable `TextInput::make('email')->email()` with `Rule::unique('users','email')->ignore($record->id)->whereNull('deleted_at')` in both forms.
- [ ] **Step 4: Run the full suite** (`docker compose exec app php artisan test`) — expect all PASS; then manually walk the flow once with `MAIL_MAILER=log` locally (register → see pending in tab → approve → login) and read `storage/logs/laravel.log` for the mails.
- [ ] **Step 5: Commit** `feat: let users and admins set email for password recovery`, then open the merge back to `main` with a PR description listing the deploy steps: `git pull`, `php artisan migrate --force`, `php artisan optimize:clear`, set production `.env` mail values (port 587, scheme null), and tell the user to send a test mail with the tinker command already used.
