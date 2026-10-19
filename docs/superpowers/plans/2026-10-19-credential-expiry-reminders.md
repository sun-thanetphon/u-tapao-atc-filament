# Credential Expiry Reminders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Email each ATCO once when a licence/medical/rating/language item in the Google Sheet is ≤ 90 days from expiry.

**Architecture:** Read-only parse of the public sheet CSV (`CredentialSheet`) → match rows to users by name (`CredentialMatcher`) → decide what to send and record it (`CredentialReminder`) → daily artisan command + read-only admin page. Reuses `SafeMail`, `MailQuota`, and the `MAIL_*_TEST_RECIPIENT` convention.

**Tech Stack:** Laravel 12, Filament 3, PHPUnit (run in Docker: `docker compose exec -T app php artisan test`).

**Spec:** `docs/superpowers/specs/2026-10-19-credential-expiry-reminders-design.md`

## Global Constraints

- Window: remind when `expires_on - today <= 90` days, expired items included (flagged). Recompute days from dates, never read the sheet's "day left" columns.
- Sheet columns found by header name: `Name of Holder`, `Rating Expiry`, `Validity of licence`, `Validity of Medical Class 3`, `Valid Until`. Dates are `F j, Y`; empty = no item; multi-date cell = earliest.
- Sheet id/gid only in `.env` (`CREDENTIAL_SHEET_ID`, `CREDENTIAL_SHEET_GID`), never in code/git.
- Logs carry counts only: no names, emails, dates.
- One mail per person per run, To = that person only, no BCC. Daily cap `mail.daily_cap_for_reminders` (default 40) via `MailQuota`.
- Test mode `MAIL_REMINDER_TEST_RECIPIENT`: send only there, subject prefix `[ทดสอบ] `, never reserve; invalid value = send to nobody.
- Page + menu: `super-admin` and `admin` only (403 for `user`). Read-only.
- UI/mail copy in Thai; time zone `Asia/Bangkok`.

## Review Focus

- Sheet returns an HTML login page (sheet later restricted): whole run aborts, nothing sent or recorded.
- Name in sheet has odd whitespace / no space after rank: still matches; two users with same name: nobody mailed.
- Cron run twice at once: second run sends nothing (reservation row is unique).
- Mail transport fails: reservation removed so tomorrow retries; quota exhausted: stops, rest retry tomorrow.
- Renewed item (new expiry date) after earlier reminder: reminded again.

---

### Task 1: CredentialSheet parser

**Files:**
- Create: `app/Support/CredentialSheet.php`, `app/Support/CredentialSheetException.php`, `tests/Unit/CredentialSheetTest.php`
- Modify: `config/services.php` (key `credential_sheet` => `id`, `gid`)

**Interfaces:**
- Produces: `CredentialSheet::KINDS = ['rating','licence','medical','language']`;
  `CredentialSheet::parse(string $csv): array` → list of `['name' => string, 'items' => array<string, Carbon>, 'unreadable' => string[]]`;
  `CredentialSheet::fetch(): array` (same shape, uses `Http`, throws `CredentialSheetException`).

- [ ] **Step 1: Tests** (`Tests\Unit\CredentialSheetTest`, `Tests\TestCase`-free CSV strings; `fetch` uses `Http::fake` so extend `Tests\TestCase`): `test_parses_four_items_per_row`, `test_empty_date_cell_means_no_item`, `test_multi_line_cell_uses_earliest_date`, `test_bad_date_is_listed_unreadable`, `test_blank_trailing_rows_skipped`, `test_missing_header_throws`, `test_html_response_throws`, `test_fetch_failure_throws`.
- [ ] **Step 2: Run, expect FAIL** (`docker compose exec -T app php artisan test --filter=CredentialSheetTest`).
- [ ] **Step 3: Implement.** Row 1 title is skipped: header row = first row containing `Name of Holder`. Use `fgetcsv` over a memory stream (cells contain newlines). `Valid Until` is language. Throw `CredentialSheetException` if header missing, body starts with `<`, or HTTP fails.
- [ ] **Step 4: Run, expect PASS.**
- [ ] **Step 5: Commit** `feat: parse credential expiry sheet`.

### Task 2: CredentialMatcher

**Files:** Create `app/Support/CredentialMatcher.php`, `tests/Feature/CredentialMatcherTest.php`.

**Interfaces:**
- Produces: `CredentialMatcher::__construct()` loads active users once; `match(string $sheetName): array{status: 'ok'|'no_account'|'duplicate'|'no_email', user: ?User}`.

- [ ] **Step 1: Tests**: rank glued to name; odd whitespace; ranks differ; duplicate name → `duplicate`; no user → `no_account`; user with empty/invalid email → `no_email`; suspended (`status` not active) and soft-deleted users ignored.
- [ ] **Step 2: FAIL.**
- [ ] **Step 3: Implement.** Normalise = strip all `\s`, lowercase, NFC (`\Normalizer`, guard if ext missing). Match when normalised sheet name ends with normalised `firstname.lastname`.
- [ ] **Step 4: PASS.**
- [ ] **Step 5: Commit** `feat: match credential sheet names to users`.

### Task 3: Reminder service, mail, migration

**Files:**
- Create: `database/migrations/2026_10_19_000002_create_credential_reminders_table.php`, `app/Models/CredentialReminder.php`, `app/Support/CredentialReminderService.php`, `app/Mail/CredentialExpiryMail.php`, `resources/views/mail/credential-expiry.blade.php`, `tests/Feature/CredentialReminderTest.php`
- Modify: `config/mail.php` (`daily_cap_for_reminders` env `MAIL_REMINDER_DAILY_CAP` 40; `reminder_test_recipient` env `MAIL_REMINDER_TEST_RECIPIENT`)

**Interfaces:**
- Consumes: `CredentialSheet::fetch()` rows, `CredentialMatcher::match()`.
- Produces: `CredentialReminderService::run(bool $dryRun = false, ?array $rows = null): array{sent:int, skipped:int, failed:int, plan: list<array{user_id:int, name:string, items:list<array{kind:string, expires_on:string, days_left:int}>}>, problems: list<array{name:string, reason:string}>}`.
  `CredentialExpiryMail::__construct(public User $user, public array $items, public bool $test = false)`.
  Table `credential_reminders`: `user_id` FK cascade, `item` string(16), `expires_on` date, `created_at`; unique `(user_id,item,expires_on)`.

- [ ] **Step 1: Tests** (`Mail::fake`, `Cache::flush` for quota): reminds at exactly 90 days; 91 days not; expired item included; one mail with several items; second run sends nothing; new expiry date reminds again; transport failure removes reservation (fake `SafeMail` by making `Mail::to` throw via `Mail::shouldReceive`) and next run retries; quota full stops without reservations; test mode sends only to test address, no reservations, no real name/email in the mail body; invalid test address sends nothing; `dryRun` sends and records nothing; fetch exception propagates.
- [ ] **Step 2: FAIL.**
- [ ] **Step 3: Implement** per spec §3–4. Reserve with `insertOrIgnore` per item and send only if every item row was newly inserted (others skipped). Remove reserved rows if `SafeMail::send` returns false. Check `MailQuota::canUse(1, cap)` before each person.
- [ ] **Step 4: PASS.**
- [ ] **Step 5: Commit** `feat: credential expiry reminder service and mail`.

### Task 4: Artisan command

**Files:** Create `app/Console/Commands/RemindCredentials.php`, `tests/Feature/RemindCredentialsCommandTest.php`.

**Interfaces:** Consumes `CredentialReminderService::run`. Produces command `credentials:remind {--dry-run}`; exit 0 on success, 1 when the sheet fails.

- [ ] **Step 1: Tests**: `--dry-run` prints a table and sends nothing; real run sends; sheet failure exits 1 and prints a Thai message; output never prints email addresses.
- [ ] **Step 2: FAIL. Step 3: Implement. Step 4: PASS.**
- [ ] **Step 5: Commit** `feat: credentials:remind command`.

### Task 5: Admin page

**Files:** Create `app/Filament/Pages/CredentialExpiry.php`, `resources/views/filament/pages/credential-expiry.blade.php`, `tests/Feature/CredentialPageTest.php`.

**Interfaces:** Consumes `CredentialSheet::fetch` (cached 10 min, key `credential-sheet-rows`), matcher, `CredentialReminder` model. Produces a Filament page, group `Accounts`, `canAccess()` true for super-admin/admin; `refresh()` action clears the cache; `sendTest()` mails one sample to the clicker (`CredentialExpiryMail` with `test: true`, no reservations).

- [ ] **Step 1: Tests** (`Http::fake`): admin and super-admin see rows sorted by days left; user gets 403; unmatched names listed with reason; sheet failure shows an error and no rows; `sendTest` mails only the clicker.
- [ ] **Step 2: FAIL. Step 3: Implement. Step 4: PASS.**
- [ ] **Step 5: Commit** `feat: credential expiry admin page`.

### Task 6: Wrap-up

- [ ] Add `CREDENTIAL_SHEET_ID`, `CREDENTIAL_SHEET_GID` (and commented reminder vars) to `.env.example`.
- [ ] Run the full suite: `docker compose exec -T app php artisan test` → all pass.
- [ ] Commit. Do not deploy without asking (see memory: ask before deploy).
