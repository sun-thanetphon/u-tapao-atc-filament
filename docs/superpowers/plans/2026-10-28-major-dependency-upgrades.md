# แผนอัปเกรดแพ็กเกจรุ่นหลัก (Major Dependency Upgrades)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ย้ายแพ็กเกจหลักไปรุ่นล่าสุด (Laravel 13, Filament 5 + Livewire 4, maatwebsite/excel 4, spatie/laravel-permission 8, PHPUnit 12, tinker 3) ทีละขั้น โดยหน้าตาและพฤติกรรมของระบบเหมือนเดิม และ deploy บนโฮสต์ร่วมด้วย `git pull` + `composer install` ได้เหมือนเดิม

**Architecture:** แบ่งเป็น 7 PR เล็ก PR ละขั้น เรียงจากความเสี่ยงต่ำไปสูง แต่ละ PR deploy และใช้งานจริงได้ก่อนขึ้น PR ถัดไป Filament ต้องผ่าน 4 ก่อนขึ้น 5 (upgrade script ของ v5 ถือว่าโค้ดเป็น v4 แล้ว) ส่วน Livewire 4 ต้องขึ้นพร้อม Filament 5 ธีมยังเป็น CSS ธรรมดาที่ inline ไว้ใน head เหมือนเดิม ไม่ต้องมีขั้น build

**Tech Stack:** Laravel 12→13, Filament 3.3→4→5, Livewire 3→4, PHPUnit 11→12, PHP 8.3 (โฮสต์และ CI) รันเทสต์ใน Docker

**Spec:** ไม่มี spec แยก แผนนี้ทำตาม `docs/roadmap.md` ข้อ 1.1 (Laravel 13) และข้อ 4.1 (Filament 5) หลักการของระบบอยู่ใน `PRODUCT.md`

ข้อมูลในแผนนี้ตรวจเมื่อ 2026-10-28 จากคู่มืออัปเกรดทางการ, Packagist และการจำลอง `composer update --dry-run` ด้วย `composer.json`/`composer.lock` ของ `main` (`8b097d0`) ใน Docker (PHP 8.3) รวมถึงติดตั้ง Filament 3, 4 และ 5 ลงโฟลเดอร์ชั่วคราวเพื่อเทียบชื่อ class และ CSS selector

## Global Constraints

- โฮสต์ใช้ PHP 8.3 ทั้ง CLI และเว็บ ทุกขั้นต้องติดตั้งได้บน PHP 8.3 ห้ามพึ่ง PHP 8.4 ขึ้นไป
- deploy ด้วย `git pull` แล้ว `composer install` (รวม dev) บนโฮสต์ ไม่มี npm/Vite ไม่มี queue worker ห้ามเพิ่มขั้น build
- asset ของ Filament ต้อง commit ไว้ใน `public/css/filament`, `public/js/filament` (และโฟลเดอร์ใหม่ที่ `filament:assets` สร้าง) ตอน `composer install` บนโฮสต์ `filament:upgrade` จะ publish ซ้ำลง `public_html` เอง (`bootstrap/app.php`)
- ธีมยังเป็น CSS ธรรมดาใน `resources/css/filament-theme.css` ที่ inline ผ่าน `PanelsRenderHook::HEAD_END` ห้ามเปลี่ยนไปใช้ `make:filament-theme`/Tailwind build
- ห้ามออกแบบ UI ใหม่ระหว่างอัปเกรด พฤติกรรมเดิมต้องคงอยู่ (เช่น filter ตารางทำงานทันที, Force ต้องดูเหมือนเจ้าตัวกดเอง ตาม `PRODUCT.md` และ roadmap)
- เทสต์ 331 ตัวต้องผ่านทุก PR รันใน Docker กับ DB ที่ชื่อลงท้าย `_test` (`tests/TestCase.php`) และ CI (`.github/workflows/tests.yml`, PHP 8.3) ต้องเขียว
- PR ละขั้น ห้ามรวมสองขั้นใน PR เดียว ยกเว้นคู่ที่ต้องไปด้วยกันตามตารางข้างล่าง
- repo เป็น public ห้ามเขียนรายละเอียดโฮสต์ (path, ชื่อเครื่อง, ช่องโหว่) ลงใน PR หรือ commit

## Review Focus

- เปิดทุกหน้าหลังอัปเกรด Filament 4/5 ทั้ง light, dark และมือถือ: สีหลัก (Tower Navy) และสีเทาต้องเหมือนเดิม เพราะ Filament 4 เปลี่ยนตัวแปรสีจาก "R, G, B" เป็นสี OKLCH เต็มค่า (Task 5)
- user ทั่วไปต้องยังแก้หรือลบ Login Log ไม่ได้ และเข้าเมนูของ admin ไม่ได้ เพราะ Filament 4 ไม่เรียก `canCreate/canEdit/canDelete/canDeleteAny` ทุกครั้งแล้ว (Task 5)
- ผู้ใช้ที่ล็อกอินค้างอยู่ตอน deploy ต้องไม่หลุด และหน้า Credential Expiry ที่ cache ผล sheet (มี `Carbon` อยู่ข้างใน) ต้องเปิดได้ ถ้าใช้ config ของ skeleton Laravel 13 ตรง ๆ (`session.serialization=json`, `cache.serializable_classes=false`) จะพังทั้งสองข้อ (Task 2)
- นำเข้าอีเมลจากไฟล์ xlsx/xls/csv จริงของหน่วย และ export Excel หน้า Follow ต้องได้ผลเหมือนเดิมหลังขึ้น PhpSpreadsheet 5 (Task 4)
- การ์ด "เอกสารของฉัน" ต้องลิงก์ไปแท็บ "ยังไม่รับทราบ" ได้ถูก หลัง Filament 4 เปลี่ยนชื่อพารามิเตอร์ URL จาก `activeTab` เป็น `tab` (Task 5)

---

## ภาพรวม: เวอร์ชันและสิ่งที่บังคับให้ไปด้วยกัน

| แพ็กเกจ | ตอนนี้ (lock) | ล่าสุด | PHP ที่ต้องใช้ | Laravel ที่รองรับ | หมายเหตุ |
| --- | --- | --- | --- | --- | --- |
| `laravel/framework` | 12.70.2 | 13.39.0 | ^8.3 | – | ดึง Guzzle 8 และ `symfony/polyfill-php85` มาด้วย |
| `filament/filament` | 3.3.57 | 5.11.0 (4.16.0) | ^8.2 | 3.x: 10–13, 4.x/5.x: 11.28–13 | Filament 3 รองรับ Laravel 13 แล้ว |
| `livewire/livewire` | 3.8.11 | 4.4.8 | ^8.1 | 10–13 | Filament 4 ต้องการ `^3.8.7`, Filament 5 ต้องการ `^4.4.8` |
| `maatwebsite/excel` | 3.1.70 | 4.0.6 | ^8.3 | 12–13 | ดึง PhpSpreadsheet 1.30 → 5.10 |
| `spatie/laravel-permission` | 6.25.0 | 8.3.2 | ^8.3 | 12–13 | ไม่ต้องแวะ 7 |
| `swisnl/filament-backgrounds` | 1.1.5 | 2.0.3 | ^8.2 | 1.x: ถึง 12, 2.x: 11–13 | 1.x = Filament 3 เท่านั้น, 2.x = Filament 4/5 เท่านั้น |
| `flowframe/laravel-trend` | 0.4.0 | 0.5.0 | ^8.2 | 0.4: ถึง 12, 0.5: ถึง 13 | **ไม่มีโค้ดไหนใช้** |
| `laravel/tinker` | 2.11.1 | 3.0.2 | ^8.1 | 3.x: 8–13 | 2.x รองรับถึง Laravel 12 |
| `phpunit/phpunit` (dev) | 11.5.57 | 12.5.42 (13.x ต้อง PHP 8.4.1) | ^8.3 | – | คู่มือ Laravel 13 ให้ขึ้น ^12.0 |

ผลจำลอง `composer update -W --dry-run` บน PHP 8.3 (ใน Docker):

| ชุดที่ลอง | ผล |
| --- | --- |
| Laravel 13 + Filament 3 + tinker 3 + trend 0.5 + PHPUnit 12 โดยเอา filament-backgrounds ออก | ผ่าน (Livewire อยู่ 3.8.11) |
| Laravel 13 แต่ยังเก็บ filament-backgrounds 1.x | **ไม่ผ่าน** (1.1.5 รองรับ `illuminate/contracts` ถึง ^12 เท่านั้น) |
| Laravel 12 + excel 4 + permission 8 | ผ่าน (PhpSpreadsheet 5.10.0) |
| Laravel 12 + Filament 4 + backgrounds 2 | ผ่าน (Livewire ยัง 3) |
| Laravel 13 + Filament 4 + backgrounds 2 | ผ่าน (Livewire ยัง 3) |
| Laravel 13 + Filament 5 + backgrounds 2 | ผ่าน (Livewire 4.4.8) |
| Laravel 13 + Filament 5 + excel 4 + permission 8 | ผ่าน (ปลายทางของแผนนี้) |

ข้อสรุปเรื่องลำดับ:

- **ต้องไปด้วยกัน:** Laravel 13 กับการเอา `swisnl/filament-backgrounds` 1.x ออก (หรือรอ Filament 4 แล้วใช้ 2.x), Laravel 13 กับ tinker 3 และ laravel-trend 0.5 (หรือเอา trend ออก), Filament 5 กับ Livewire 4
- **ไม่ต้องไปด้วยกัน:** excel 4 และ permission 8 ใช้ได้ทั้งบน Laravel 12 และ 13 และไม่เกี่ยวกับ Filament, Filament 4 ใช้ได้ทั้งบน Laravel 12 และ 13
- **ต้องแวะ:** Filament 4 (คู่มือ v5 มีแค่ "ขึ้น Livewire 4" การเปลี่ยนแปลงใหญ่ทั้งหมดอยู่ในคู่มือ v4) ส่วน spatie/permission 7 ไม่ต้องแวะ (ทุกข้อในคู่มือ 6→7 และ 7→8 ใช้กับการกระโดด 6→8 ได้เลย และ schema ใน migration stub ไม่เปลี่ยน)
- **PHP:** ปลายทางต้องการ PHP 8.3 ขึ้นไป (Laravel 13, excel 4, permission 8, PHPUnit 12) โฮสต์ใช้ 8.3 อยู่แล้ว จึงไม่ต้องเปลี่ยน PHP ใน hPanel อย่าขึ้น PHPUnit 13 เพราะต้องใช้ PHP 8.4.1
- **ผลพลอยได้:** PhpSpreadsheet 5 รองรับ PHP 8.5 แล้ว (1.x ต้องการ <8.5) หลัง Task 4 จึงอาจรัน composer บน PHP 8.5 ของเครื่อง dev ได้ แต่ยังต้องเช็ก `openspout/openspout` ด้วย ยังไม่ได้ลอง

## เส้นตาย

| อะไร | วันที่ |
| --- | --- |
| Laravel 12 หมด security fix | 24 ก.พ. 2027 → Task 1–2 ต้อง deploy ก่อนวันนี้ |
| Filament 4 หมด bug fix | 15 ม.ค. 2027 → ไม่ควรค้างที่ 4 นาน |
| Filament 3 หมด security fix | 1 ม.ค. 2028 → Task 6 ต้อง deploy ก่อนวันนี้ |
| Filament 4 หมด security fix | 15 ม.ค. 2028 |

## ลำดับ PR

| # | PR | ขนาด | ความเสี่ยง | ทำได้เมื่อ |
| --- | --- | --- | --- | --- |
| 1 | เก็บกวาด: เอา laravel-trend และ filament-backgrounds ออก (ภาพหน้า login ยังอยู่) | S (≤ ครึ่งวัน) | ต่ำ | ทันที |
| 2 | Laravel 13 + tinker 3 + PHPUnit 12 | S–M (1–2 วัน) | ต่ำ–กลาง | หลัง 1 deploy |
| 3 | spatie/laravel-permission 8 | S (≤ ครึ่งวัน) | ต่ำ | หลัง 2 (หรือก่อนก็ได้) |
| 4 | maatwebsite/excel 4 + PhpSpreadsheet 5 | S (1 วัน) | กลาง | หลัง 2 (หรือก่อนก็ได้) |
| 5 | Filament 4 (Livewire ยัง 3) | L (1–2 สัปดาห์) | สูง | หลัง 2 |
| 6 | Filament 5 + Livewire 4 | M (2–4 วัน) | กลาง | หลัง 5 deploy และใช้จริงแล้วอย่างน้อย 1 สัปดาห์ |
| 7 | เก็บงานหลังอัปเกรด: helper ทดสอบที่ deprecated, Placeholder | S–M | ต่ำ | หลัง 6 |

Task 3 และ 4 ไม่ขึ้นกับ Filament จะทำสลับกับ 5 หรือทำคู่ขนานใน worktree อื่นก็ได้ แต่ห้าม deploy พร้อม Task 5 ในรอบเดียว เพื่อให้รู้ว่าอะไรพังถ้ามีปัญหา

---

## ขั้นตอนมาตรฐานของทุก PR

ทุก Task ใช้ขั้นตอนเดียวกันนี้ จะไม่เขียนซ้ำในแต่ละ Task

**สภาพแวดล้อมทดสอบ (worktree):** stack ที่พอร์ต 8000 mount checkout หลัก จึงใช้ทดสอบ worktree ไม่ได้ ให้ใช้ container แยกจาก image `u-tapao-atc-filament-app:latest` บน network `u-tapao-atc-filament_utapao-network` แล้ว mount worktree ที่ `/var/www`

1. `cp .env.example .env` แล้วตั้ง `APP_ENV=local` **ก่อน** `composer install` (ถ้าไม่ตั้ง `filament:upgrade` จะ publish asset ลง `public_html/`)
2. สร้าง DB ของตัวเองบน container `utapao-db` ชื่อลงท้าย `_test` เช่น `db_u_tapao_<task>_test`
3. รัน composer ใน container เสมอ (PHP 8.3) ห้ามรันบน PHP 8.5 ของเครื่อง
4. รันเทสต์ด้วย `vendor/bin/phpunit -e DB_DATABASE=db_u_tapao_<task>_test` ตรง ๆ (`php artisan test` ล้าง env จาก `.env` จึง override ชื่อ DB ไม่ได้)
5. เสร็จแล้วลบ container และ DB ของตัวเอง ห้ามแตะ `utapao_db` และ `db_u_tapao_test`

**ก่อนเปิด PR:**

- `composer validate --strict` และ `composer audit --locked` ต้องผ่าน
- `git status public/` ต้องเห็น asset ที่ publish ใหม่ และ commit ทั้งหมด (ห้ามมีไฟล์ใน `public_html/`)
- เทสต์ทั้งชุดผ่าน และ CI เขียว
- เช็กด้วยเบราว์เซอร์ตามรายการ "เช็กในเบราว์เซอร์" ของ Task นั้น

**Deploy (ผู้ใช้เป็นคนทำ):** backup DB → `php artisan down` → `git pull --ff-only` → `composer install --no-interaction --optimize-autoloader` → `php artisan migrate --force` (เฉพาะ Task ที่มี migration) → `php artisan optimize:clear` → `php artisan up` แล้วล็อกอินเช็กหน้าหลัก

**Rollback มาตรฐาน:** `php artisan down` → `git checkout <commit ก่อน merge>` → `composer install --no-interaction --optimize-autoloader` → `php artisan optimize:clear` → `php artisan up` ใช้ได้เพราะ `composer.lock` และ asset ที่ commit ไว้กลับไปพร้อมโค้ด ทุก Task ในแผนนี้ไม่มี migration ที่แก้ schema ถ้า Task ไหนต้องมี ให้เขียน `down()` และทดสอบก่อน merge

---

### Task 1: เอาแพ็กเกจที่ไม่ได้ใช้และที่บล็อก Laravel 13 ออก โดยหน้า login ยังมีภาพเดิม

**ทำไม:** `flowframe/laravel-trend` ไม่มีโค้ดไหนเรียกเลย (`grep -rn "Flowframe\|Trend::" app resources tests` ไม่เจอ, `app/Filament/Widgets/AcknowledgeActivityChart.php` นับข้อมูลเอง) ส่วน `swisnl/filament-backgrounds` 1.x คือตัวที่บล็อก Laravel 13 แต่ยังมีหน้าที่อยู่หนึ่งอย่าง: หน้า auth ทั้ง 4 หน้าใช้ layout ของเราเอง (`filament.layouts.login`) จึงไม่โดน CSS `.fi-simple-layout` ของ plugin แต่ plugin ส่งตัวแปร `--filament-backgrounds-image` (ภาพสุ่มจาก `images/backgrounds/` ผ่าน `PublicHtmlImages` ซึ่งตอนนี้มีภาพเดียวคือ `bg2.jpg`) และ theme เอาไปเป็นภาพแผงซ้ายของหน้า login ที่ `resources/css/filament-theme.css:234-237` (`.uta-login-brand`) ถ้าถอด plugin เฉย ๆ ภาพรันเวย์บนหน้า login จะหาย ผู้ใช้ตัดสินใจ (2026-10-28) ให้คงภาพเดิมไว้ หน้า Home ก็ใช้ภาพเดียวกันโดยตรง (`resources/views/filament/pages/home.blade.php:8`, `--uta-hero-photo`) ห้ามลบ `public/images/backgrounds/bg2.jpg`

**Files:**
- Modify: `composer.json`, `composer.lock`
- Modify: `app/Providers/Filament/AdminPanelProvider.php:29-30,43-49` (เอา `->plugins([...])` และ `use` ออก แล้วเพิ่มตัวแปร CSS ใน HEAD_END hook เดิม)
- Modify: `resources/css/filament-theme.css:237` (`var(--filament-backgrounds-image, none)` → `var(--uta-login-photo, none)`)
- Delete: `app/Filament/Custom/PublicHtmlImages.php`
- Test: `tests/Feature/PagesTest.php`

- [ ] **Step 1:** เปิดหน้า `/admin/login`, `/admin/register`, `/admin/password-reset/request` ใน stack ของ worktree แล้วจับภาพหน้าจอเก็บไว้เทียบ (light, dark และกว้าง 375) ตอนนี้แผงซ้ายต้องเห็นภาพรันเวย์
- [ ] **Step 2:** เขียนเทสต์ `PagesTest::test_login_page_shows_the_runway_photo`: guest เปิด `/admin/login` แล้ว `assertSee('--uta-login-photo', false)` และ `assertSee('images/backgrounds/bg2.jpg', false)` รันแล้วต้องพัง
- [ ] **Step 3:** `composer remove flowframe/laravel-trend swisnl/filament-backgrounds` ใน container ลบ `PublicHtmlImages` และ `use` ที่เกี่ยวข้อง แล้วใน HEAD_END hook ของ `AdminPanelProvider` ให้ต่อ `:root{--uta-login-photo:url("…")}` ไว้หน้า CSS ของ theme โดยใช้ `asset('images/backgrounds/bg2.jpg')` (สลับ `public`/`public_html` ให้เองแบบเดียวกับหน้า Home) และแก้ `filament-theme.css:237` ให้ใช้ `--uta-login-photo` เลือกภาพตายตัวแทนการสุ่ม เพราะในโฟลเดอร์มีภาพเดียว
- [ ] **Step 4:** รันเทสต์ทั้งชุด คาดว่าผ่านหมดรวมตัวใหม่
- [ ] **Step 5:** จับภาพทั้ง 3 หน้าอีกรอบ ต้องเหมือน Step 1 ทุกจุด รวมถึงภาพรันเวย์และ gradient ทับภาพ ถ้าต่าง ให้หยุดแล้วถามผู้ใช้
- [ ] **Step 6:** commit `chore(deps): drop laravel-trend and filament-backgrounds, keep the login photo`

**เช็กในเบราว์เซอร์:** หน้า login (ภาพรันเวย์ที่แผงซ้าย ทั้งจอกว้างและมือถือ), register, ขอรีเซ็ตรหัสผ่าน, หน้าตั้งรหัสใหม่จากลิงก์ในเมล และหน้า Home (ภาพ hero เดิม) บนโฮสต์จริงหลัง deploy ให้เช็กว่าภาพโหลดผ่าน https จาก `public_html`
**Rollback:** มาตรฐาน

### Task 2: Laravel 13 + tinker 3 + PHPUnit 12

**Files:**
- Modify: `composer.json` (`laravel/framework:^13.0`, `laravel/tinker:^3.0`, dev `phpunit/phpunit:^12.0`), `composer.lock`
- Modify: `app/Providers/Filament/AdminPanelProvider.php:22,123` (`VerifyCsrfToken` → `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`)
- Modify: `tests/Unit/CaatDocumentTypeTest.php:10` (`/** @dataProvider names */` → `#[DataProvider('names')]`) ถ้า PR #4 ยังไม่ merge
- ห้ามแก้: `config/session.php`, `config/cache.php` ในส่วน `serialization` และ `serializable_classes` (ดูข้างล่าง)

การเปลี่ยนแปลงใน Laravel 13 ที่กระทบ repo นี้ (จากคู่มือ `laravel.com/docs/13.x/upgrade` ข้อที่เหลือไม่โดน: ไม่มี `upsert`, `Js::from`, `array_first`, `JobAttempted`, `QueueBusy`, manager `extend`, pagination view, polymorphic pivot ใน `app/`):

| เรื่อง | ระดับ | ผลกับ repo นี้ |
| --- | --- | --- |
| CSRF middleware เปลี่ยนชื่อเป็น `PreventRequestForgery` และตรวจ `Sec-Fetch-Site` | สูง | `AdminPanelProvider.php:123` ใช้ `VerifyCsrfToken` ยังทำงานผ่าน alias ที่ deprecated แต่ควรเปลี่ยน ต้องลองล็อกอินและส่งฟอร์มผ่าน CDN ของโฮสต์จริงหลัง deploy |
| `cache.serializable_classes` ใน skeleton ใหม่เป็น `false` | กลาง | `config/cache.php` ของเราไม่มี key นี้ framework จึงไม่จำกัด class (ตรวจโค้ด `CacheManager::getSerializableClasses` แล้ว) **ถ้าใส่ `false` ตาม skeleton** หน้า `app/Filament/Pages/CredentialExpiry.php:79` จะพัง เพราะ cache ผลของ `CredentialSheet::fetch()` ที่มี `Carbon` ข้างใน ถ้าจะเปิดใช้ ต้องระบุ `Carbon\Carbon` และ `Illuminate\Support\Carbon` หรือเปลี่ยนให้ cache เป็นสตริงวันที่ (แยกเป็น PR ต่างหาก) |
| session `serialization` ใน skeleton ใหม่เป็น `json` | ต่ำ | `config/session.php` ของเราไม่มี key นี้ จึงยังเป็น `php` ห้ามเปลี่ยนตอนอัปเกรด เพราะผู้ใช้ทุกคนจะหลุดจากระบบ |
| prefix ของ cache และชื่อ cookie session เปลี่ยนเป็นขีดกลาง | ต่ำ | `config/cache.php:106` และ `config/session.php:130` กำหนดเองไว้แล้ว จึงไม่เปลี่ยน |
| Guzzle 7 → 8 (มาพร้อม Laravel 13) | ต่ำ | เราเรียกผ่าน `Http` facade เท่านั้น (`app/Support/GoogleDriveBackup.php`, `CredentialSheet::fetch`) ต้องลอง backup ขึ้น Drive จริงหลัง deploy |
| ข้อความ subject ของเมลรีเซ็ตรหัสผ่านค่าเริ่มต้นเปลี่ยน | ต่ำมาก | เราใช้ `app/Notifications/ResetPasswordThai.php` เอง ตรวจว่า `PasswordResetEmailTest` ยังผ่าน |

PHPUnit 12 (ต้อง PHP 8.3): metadata ใน doc-comment ใช้ไม่ได้แล้ว เจอที่เดียวคือ `tests/Unit/CaatDocumentTypeTest.php:10` (PR #4 แก้ไว้แล้ว รอ merge) ที่เหลือใช้ attribute อยู่แล้ว (`tests/Feature/CredentialPageTest.php:74`) ไม่มี `createMock`/`withConsecutive` ใน `tests/` ใช้ Mockery ผ่าน facade เท่านั้น

Tinker 3: เลิกรองรับ PHP 8.0 และต่ำกว่า เพิ่ม Laravel 13 ไม่มีโค้ดในแอปเรียกใช้

- [ ] **Step 1:** ใน container: `composer require laravel/framework:^13.0 laravel/tinker:^3.0 -W --no-update` และ `composer require --dev phpunit/phpunit:^12.0 --no-update` แล้ว `composer update -W`
- [ ] **Step 2:** รันเทสต์ทั้งชุด บันทึกตัวที่พังและ deprecation ที่ PHPUnit รายงาน
- [ ] **Step 3:** เปลี่ยน `VerifyCsrfToken` เป็น `PreventRequestForgery` ใน `AdminPanelProvider` และแก้เทสต์ที่พังจาก Step 2 (แก้เฉพาะเทสต์ ห้ามเปลี่ยนพฤติกรรมของแอป)
- [ ] **Step 4:** รันเทสต์ทั้งชุด คาดว่าผ่านหมด ไม่มี PHPUnit deprecation
- [ ] **Step 5:** เทียบ `config/` กับ skeleton 13.x (`github.com/laravel/laravel/compare/12.x...13.x`) แต่ห้ามรับ 2 ค่าในตารางข้างบน จดสิ่งที่รับมาไว้ใน PR
- [ ] **Step 6:** commit `chore(deps): upgrade to Laravel 13, tinker 3 and PHPUnit 12`

**เช็กในเบราว์เซอร์:** ล็อกอินผิด username / ผิดรหัส / บัญชีรออนุมัติ, สมัคร, ขอรีเซ็ตรหัสผ่าน, เปิดเอกสาร PDF, กดรับทราบ, หน้า Follow, หน้า Credential Expiry (เปิดสองครั้งให้ได้ผลจาก cache)
**หลัง deploy:** ลองสั่ง backup ขึ้น Drive หนึ่งครั้ง และดูว่า cron ใน hPanel ยังรันได้
**Rollback:** มาตรฐาน session และ cache อ่านย้อนได้เพราะไม่ได้เปลี่ยนรูปแบบการ serialize

### Task 3: spatie/laravel-permission 8

**Files:**
- Modify: `composer.json` (`spatie/laravel-permission:^8.0`), `composer.lock`
- Modify: `config/permission.php` (เพิ่ม `'team' => null` และ `'default_model' => null` ใต้ `models` ตาม config ของ 8.3.2)

การเปลี่ยนแปลง 6→7→8 ที่ตรวจแล้ว: service provider เปลี่ยนเป็น `PackageServiceProvider`, ชื่อ class ของ event/command มี suffix ใหม่, ลบ `clearClassPermissions()`, เพิ่ม return type ให้ `assignRole/syncRoles/givePermissionTo` ฯลฯ, v8 ให้ `findByName/findOrCreate` รับ `BackedEnum|string` repo นี้ไม่ได้ extend model, ไม่ฟัง event, ไม่ override trait ใช้แค่ `HasRoles` ใน `app/Models/User.php:20,27`, `Role::create`/`Permission::create`/`findOrCreate` ใน `database/seeders/RolePermissionSeeder.php` และ `database/migrations/2026_10_10_000002_add_user_approve_permission.php` และ `forgetCachedPermissions()` ซึ่งยังอยู่ (คืน `bool`) migration stub ของ 8.3.2 ต่างจาก 6.25.0 แค่ `bigIncrements('id')` → `id()` และเลิกกำหนด engine จึงไม่ต้องมี migration ใหม่

- [ ] **Step 1:** `composer require spatie/laravel-permission:^8.0 -W` ใน container และแก้ `config/permission.php`
- [ ] **Step 2:** รันเทสต์ทั้งชุด รวม `migrate:fresh --seed` บน DB ทดสอบ คาดว่าผ่าน
- [ ] **Step 3:** commit `chore(deps): upgrade spatie/laravel-permission to 8`

**เช็กในเบราว์เซอร์:** user ทั่วไปไม่เห็นเมนู admin, admin อนุมัติผู้สมัครได้, super-admin เห็นหน้า Follow และปุ่ม Force
**หลัง deploy:** `php artisan permission:cache-reset`
**Rollback:** มาตรฐาน แล้ว `php artisan permission:cache-reset`

### Task 4: maatwebsite/excel 4 + PhpSpreadsheet 5

**Files:**
- Modify: `composer.json` (`maatwebsite/excel:^4.0`), `composer.lock` (PhpSpreadsheet 1.30.7 → 5.10.0, เอา `ezyang/htmlpurifier` ออก)
- อาจต้องแก้: `app/Support/EmailImport.php:64-100`, `app/Exports/FollowExport.php`, `tests/Feature/EmailImportTest.php:334-336,527-533`

การเปลี่ยนแปลงใน excel 4 (`docs.laravel-excel.com/4.x/getting-started/upgrade.html`): ต้อง PHP 8.3, Laravel 12, PhpSpreadsheet 5, method ของ concern ต้องมี return type จริง, `FromQuery` ไม่รับ Scout แล้ว, เพิ่ม marker interface `Export`/`Import` และ `config/excel.php` ไม่ต้อง publish ใหม่ สถานะของ repo นี้:

- `app/Support/EmailImport.php`: anonymous class ที่ implement `ToArray, WithCustomCsvSettings, WithLimit, WithReadFilter` มี return type ครบแล้ว (`array(): void`, `limit(): int`, `readFilter(): IReadFilter`, `getCsvSettings(): array`) ส่วน `IReadFilter::readCell($columnAddress, $row, $worksheetName = '')` ไม่มี type ของพารามิเตอร์ ซึ่ง PHP ยอมให้ (กว้างกว่า interface) และใช้ `Coordinate::columnIndexFromString` กับ `app('excel')->toArray(...)` ต้องเช็กว่าการอ่าน `.xls` ด้วย reader ของ PhpSpreadsheet 5 ยังได้ค่าเหมือนเดิม
- `app/Exports/FollowExport.php`: `FromView` และ `view(): View` มี type แล้ว ส่วน `__construct($document)` ไม่เกี่ยว
- `app/Filament/Resources/DocumentResource/Pages/FollowDocument.php:88`: `Excel::download(...)` ไม่เปลี่ยน
- ไม่ใช้ `WithEvents`, `WithCharts`, `WithDrawings`, value binder, queue หรือ Scout

- [ ] **Step 1:** `composer require maatwebsite/excel:^4.0 -W` ใน container
- [ ] **Step 2:** รัน `EmailImportTest` แล้วรันทั้งชุด
- [ ] **Step 3:** ถ้ามีตัวพัง แก้เฉพาะจุดที่ type ไม่ตรงหรือ API ของ PhpSpreadsheet 5 เปลี่ยน ห้ามเปลี่ยนกฎการนำเข้า
- [ ] **Step 4:** export ไฟล์ Excel หน้า Follow ก่อนและหลังอัปเกรดจากข้อมูลชุดเดียวกัน แล้วเปิดทั้งสองไฟล์เทียบกันว่า หัวตาราง, ชื่อ, section, วันที่ และสถานะ ตรงกันทุกแถว
- [ ] **Step 5:** commit `chore(deps): upgrade maatwebsite/excel to 4`

**เช็กในเบราว์เซอร์:** นำเข้าอีเมลจากไฟล์ xlsx, xls และ csv (คั่นด้วย `,` และ `;`) จนถึงหน้า preview และกดยืนยันหนึ่งครั้ง, export Excel หน้า Follow
**Rollback:** มาตรฐาน

### Task 5: Filament 4 (Livewire ยังเป็น 3)

งานใหญ่ที่สุดและเสี่ยงที่สุด ใช้ upgrade script ของ Filament ทำงานซ้ำ ๆ แล้วแก้ที่เหลือเอง

**Files (จาก grep ทั้ง repo):**
- Modify: `composer.json` (`filament/filament:^4.0`, ลบ `filament/upgrade` ออกเมื่อเสร็จ), `composer.lock`
- Modify: resource ทั้ง 8 ตัวใน `app/Filament/Resources/*.php` และหน้าใน `*/Pages/*.php`
- Modify: `app/Providers/Filament/Auth/CustomLogin.php`, `CustomRegister.php`, `CustomRequestPasswordReset.php`, `CustomResetPassword.php`, `app/Providers/Filament/Profile/ProfileEditCustom.php`
- Modify: `app/Providers/AppServiceProvider.php`, `app/Http/Responses/CustomLoginResponse.php`, `app/Notifications/ResetPasswordThai.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (palette สี, ค่าเริ่มต้นแบบ global)
- Modify: `app/Filament/Widgets/MyDocumentsCard.php:35`
- Modify: `resources/css/filament-theme.css`
- Modify: `resources/views/filament/**` ที่ใช้ `<x-filament-panels::form>` / `form.actions`
- Create: `app/Policies/LoginLogPolicy.php` (แทน `can*` ใน `LoginLogResource`)
- Modify: `public/css/filament/**`, `public/js/filament/**` (+ โฟลเดอร์ใหม่ที่ `filament:assets` สร้าง)

**สิ่งที่กระทบ repo นี้ (คู่มือ `filamentphp.com/docs/4.x/upgrade-guide` + เทียบโค้ดของ 3.3.57 กับ 4.16.0/5.11.0):**

1. **namespace ย้าย** (script แก้ให้ส่วนใหญ่):
   - `Filament\Forms\Form` → `Filament\Schemas\Schema` และ `form(Form $form): Form` → `form(Schema $schema): Schema` ใน resource 8 ไฟล์ (15 method `form/table/infolist`)
   - `Forms\Components\Section`/`Group`/`Wizard\Step`, `Forms\Get`/`Set` → `Filament\Schemas\Components\...` (`UserResource.php`, `DocumentResource.php`, `PublicUrlResource.php`, `TutorialVideoResource.php`, `UserResource/Pages/ListUsers.php`, `CustomRegister.php`)
   - `Filament\Resources\Components\Tab` → `Filament\Schemas\Components\Tabs\Tab` (`ListDocumentTasks.php`, `ListUsers.php`)
   - `Filament\Tables\Actions\*` (Action ×10, Edit/Delete/BulkActionGroup/DeleteBulkAction ×5, View, ActionGroup, BulkAction) → `Filament\Actions\*`
   - `Tables\Enums\ActionsPosition` → `RecordActionsPosition` (`DocumentResource.php`)
   - `Filament\Pages\Auth\{Login,Register,EditProfile,PasswordReset\*}` → `Filament\Auth\Pages\...`
   - `Filament\Http\Responses\Auth\...` → `Filament\Auth\Http\Responses\...` (`AppServiceProvider.php`, `CustomLoginResponse.php`, auth page ทั้ง 3)
   - `Filament\Notifications\Auth\ResetPassword` → `Filament\Auth\Notifications\ResetPassword` (`ResetPasswordThai.php`)
   - `protected static string $view` → `protected string $view` (14 ที่ ทั้ง page และ widget เช่น `CustomRequestPasswordReset.php:26`, `FollowDocument.php:31`, `Pages/Home.php:12`, `Widgets/MyDocumentsCard.php:19`) และ type ของ `$navigationIcon` เป็น `string|BackedEnum|null`, `$navigationGroup` เป็น `string|UnitEnum|null` ถ้าไม่แก้จะ fatal error
2. **หน้า login ที่เขียนเอง** (`CustomLogin.php`): override `getForms()`/`makeForm()` ซึ่งไม่มีแล้วใน v4 ต้องเขียนใหม่เป็น `form(Schema $schema): Schema` ที่ใส่ `getUsernameFormComponent()`, `getPasswordFormComponent()`, `getRememberFormComponent()` และ type ของ `Component` เปลี่ยนเป็น `Filament\Schemas\Components\Component` ส่วน `authenticate()`, `rateLimit()`, `getRateLimitedNotification()`, `throwFailureValidationException()`, `getCredentialsFromFormData()` และ `static string $layout` ยังอยู่ (ตรวจใน 5.11.0) `DanHarrin\LivewireRateLimiting` ยังเป็น dependency ของ Filament 4/5 ข้อความ error ภาษาไทยและการเช็กสถานะบัญชีต้องเหมือนเดิมทุกตัวอักษร
3. **สิทธิ์ (ความปลอดภัย):** v4 ไม่เรียก `can*()` ของ resource ทุกครั้ง `LoginLogResource.php:35-50` ใช้ `canCreate/canEdit/canDelete/canDeleteAny` คืน `false` เพื่อกันการแก้ log ให้ย้ายไป `LoginLogPolicy` (คืน `false` สำหรับ create/update/delete/deleteAny) ส่วน `canAccess()` ใน resource 7 ตัวและ `canView()` ของ widget ยังรองรับ แต่ต้องมีเทสต์ยืนยันทุกตัว (`PagesTest::test_regular_user_cannot_open_admin_resources` มีอยู่แล้ว)
4. **ค่าเริ่มต้นที่เปลี่ยน ต้องตั้งกลับเพื่อไม่ให้ UI เปลี่ยน** (ใส่ใน `AdminPanelProvider` หรือ `AppServiceProvider::boot()` ด้วย `configureUsing`):
   - filter ตารางรอกด Apply → `Table::configureUsing(fn (Table $t) => $t->deferFilters(false))` (มี `SelectFilter` ×3 และ `Filter` ×1)
   - `Section`/`Grid`/`Fieldset` ไม่กินเต็มแถว → ใส่ `columnSpanFull()` ให้ Section ทั้ง 6 จุด หรือ `Section::configureUsing(fn ($s) => $s->columnSpanFull())`
   - ตัวเลือกจำนวนแถว `all` หายไป และเพิ่มการเรียงตาม primary key เป็นค่าเริ่มต้น → ตรวจว่า `UserListOrderTest` ยังผ่าน และถามผู้ใช้เรื่อง `all` (คำถามข้อ 4)
   - `unique()` ignore record เป็นค่าเริ่มต้น → `UserResource.php:95` ส่ง `ignoreRecord: true` อยู่แล้ว ไม่กระทบ
   - `columnSpan(2)` (`DocumentResource.php:193`) มีผลตั้งแต่ `lg` ขึ้นไปเหมือน `columns()` ให้ดูหน้าฟอร์มบนจอเล็ก
   - disk เริ่มต้นของ Filament อ่าน `FILESYSTEM_DISK` (เป็น `local`) และไฟล์บน disk ที่ไม่ใช่ local เป็น private → `FileUpload` ทั้ง 3 จุดระบุ `->disk('local')` เองแล้ว ไม่กระทบ
5. **URL parameter:** `activeTab` → `tab` แก้ `MyDocumentsCard.php:35` (`getUrl('index', ['activeTab' => 'pending'])`) ส่วน `Livewire::test(ListDocumentTasks::class, ['activeTab' => ...])` ในเทสต์ (`DocumentCategoryBrowseTest`, `DocumentTaskTest`, `DocumentIntegrityTest`) ใช้ต่อได้ เพราะใน 5.11.0 property ยังชื่อ `$activeTab` แค่มี `#[Url(as: 'tab')]`
6. **Blade component:** `<x-filament-panels::form>` และ `<x-filament-panels::form.actions>` ไม่มีใน v4/v5 ใช้ที่เดียวคือ `resources/views/filament/pages/auth/request-password-reset.blade.php` ให้เปลี่ยนเป็น `<form wire:submit="...">{{ $this->form }}<x-filament::actions ... /></form>` หรือ `content()` ของ schema ส่วน `layout.base`, `page.simple`, `page`, `section`, `button`, `icon`, `badge`, `link`, `widget` ยังอยู่
7. **ธีมและ asset (ตอบคำถาม "ธีมแบบเดิมรอดไหม"):** รอด ไม่ต้อง build
   - Filament 4/5 ยังส่ง CSS/JS ที่ build แล้วมากับแพ็กเกจ (`vendor/filament/*/dist`) และ `filament:assets`/`filament:upgrade` ยังมี จึง commit ใน `public/` แล้วให้ `post-autoload-dump` publish บนโฮสต์ได้เหมือนเดิม (ขนาด dist ของ panel โตจาก 212K เป็น 1.0M)
   - คำเตือน "ต้องมี custom theme" ในคู่มือใช้กับ view ที่ใช้ utility class ของ Tailwind ซึ่ง repo นี้ไม่ได้ใช้ (view ใช้ class `uta-*` ของเราเอง 179 จุด เจอ utility แค่ `w-full` ที่ `request-password-reset.blade.php:27` ต้องย้ายเป็น CSS ใน theme)
   - **palette สี:** `AdminPanelProvider.php:51-63` ส่ง primary เป็นสตริง `'19, 41, 75'` แบบ v3 แต่ v4 ใช้ค่าสีเต็มค่า (OKLCH) ต้องเปลี่ยนเป็น `Color::hex('#13294B')` หรือใส่ทั้ง 11 shade เป็น `rgb(...)`/`oklch(...)` และเทียบสีทีละ shade
   - **`rgb(var(--…))` ใน theme 74 จุด** (เช่น `rgb(var(--gray-500))` 16 จุด, `rgb(var(--primary-600))`) จะกลายเป็นสีที่ไม่ถูกต้องเพราะตัวแปรเป็นสีเต็มค่าแล้ว ต้องเปลี่ยนเป็น `var(--gray-500)` ทั้งหมด (ไม่มีจุดที่ใช้ alpha แบบ `rgb(var(--x) / .5)`)
   - **selector ที่ไม่มีใน v4/5** (มีใน 3.3.57 ไม่มีใน 4.16.0 และ 5.11.0): `.fi-sidebar-item-button` (`filament-theme.css:118-144` สไตล์เมนูข้าง), `.fi-sidebar-group-icon` และ `.fi-sidebar-group-collapse-button` (`:108-109`), `.fi-ta-text-item-label` (`:1571-1602` สีสถานะของรายการเอกสาร "ยังไม่รับทราบ/รับทราบแล้ว"), `.fi-tabs-item-icon` (`:1853`) ต้องหาชื่อใหม่จาก HTML จริงของ v4 selector ที่เหลืออีก 28 ตัวยังมีชื่อเดิม แต่โครง DOM อาจเปลี่ยน ต้องเทียบภาพทุกหน้า
   - CSS ของ Filament 4 อยู่ใน `@layer` ส่วน `<style>` ที่เรา inline ไม่มี layer จึงชนะทุกกฎของ Filament ไม่ว่า specificity เท่าไร กฎที่เคยแพ้ Filament (และตั้งใจให้แพ้) อาจกลับมามีผล
   - script ปิด sidebar บนมือถือ (`AdminPanelProvider.php` BODY_END) ใช้ `Alpine.store('sidebar')` ซึ่งยังมีใน v5
8. **เทสต์:** helper อย่าง `callTableAction` (30), `mountTableAction` (7), `assertTableActionVisible/Hidden` (18), `callMountedTableAction`, `setTableActionData`, `callTableBulkAction`, `assertTableActionHasUrl` ยังมีใน v5 แต่ deprecated ให้คงไว้ใน task นี้ ย้ายใน Task 7
9. **อื่น ๆ:** `Placeholder` deprecated (ใช้ 1 จุด) ใช้ต่อได้ ย้ายใน Task 7 ไม่มี enum ใน `options(Enum::class)` จึงไม่โดนเรื่อง state เป็น enum ไม่ใช้ tenancy, import/export action, Spatie translatable

- [ ] **Step 1:** ก่อนแตะอะไร จับภาพหน้าจออ้างอิงทุกหน้าใน `PagesTest` (admin และ user, light/dark, กว้าง 1280 และ 375) เก็บไว้นอก repo
- [ ] **Step 2:** `composer require filament/upgrade:"^4.0" -W --dev` แล้ว `vendor/bin/filament-v4` และรันคำสั่งที่ script แสดง (script ต้องใช้ Rector 2 ซึ่งมากับ `filament/upgrade`) **ไม่** รัน `filament:upgrade-directory-structure-to-v4` (เก็บโครงไดเรกทอรีเดิม)
- [ ] **Step 3:** อ่าน diff ของ script ทั้งหมด แล้วแก้ข้อ 1–6 ที่ script ไม่ได้ทำ
- [ ] **Step 4:** เขียนเทสต์ใหม่ก่อนแก้ข้อ 3 และ 4: `LoginLogTest::test_nobody_can_edit_or_delete_login_logs` (user, admin, super-admin เรียก edit/delete ได้ 403 หรือไม่เห็นปุ่ม), `DocumentTaskTest::test_filters_apply_without_confirm` (ตั้ง filter แล้วผลตารางเปลี่ยนทันที), `MyDocumentsCardTest::test_pending_link_opens_pending_tab` (URL มี `tab=pending` และเปิดแล้วอยู่แท็บ pending) รันให้เห็นว่าพังก่อน แล้วแก้ให้ผ่าน
- [ ] **Step 5:** รันเทสต์ทั้งชุด ผ่าน 331 ตัวเดิมและตัวใหม่
- [ ] **Step 6:** แก้ `filament-theme.css` และ palette ตามข้อ 7 แล้วจับภาพทุกหน้าเทียบกับ Step 1 แก้จนต่างกันแค่จุดที่หลีกเลี่ยงไม่ได้ (เช่น ระยะห่างภายในของ Filament) แล้วแนบภาพก่อน/หลังใน PR
- [ ] **Step 7:** `composer remove filament/upgrade --dev`, ตรวจว่า asset ใหม่อยู่ใน `public/` ครบ และ `git status` ไม่มี `public_html/`
- [ ] **Step 8:** commit แยกเป็นชุด: `chore(deps): upgrade to Filament 4`, `fix: keep v3 table and form defaults on Filament 4`, `fix: move login log write protection to a policy`, `style: adapt panel theme to Filament 4`

**เช็กในเบราว์เซอร์ (ทุกข้อ ทั้ง light/dark และมือถือ):** login/register/ขอรีเซ็ต/ตั้งรหัสใหม่ (ข้อความ error ไทย, rate limit 5 ครั้ง), Home, รายการเอกสารของฉัน (สีสถานะ, แท็บ, หมวด), เปิด PDF และรับทราบ, Document (สร้าง/แก้/อัปโหลดไฟล์/ประวัติไฟล์), Follow (stats, export Excel, print, Force), Users (อนุมัติ, นำเข้าอีเมล, reset password), Login Log, Public URL, Tutorial Video, Credential Expiry, เปลี่ยนรหัสผ่าน, เมนูข้างพับ/เปิด
**Rollback:** มาตรฐาน (asset v3 กลับมาพร้อม `git checkout`) ถ้าผู้ใช้ค้างหน้าเก่าไว้ Livewire จะขอให้รีโหลดหน้า

### Task 6: Filament 5 + Livewire 4

**Files:**
- Modify: `composer.json` (`filament/filament:^5.0`, `livewire/livewire` มาตาม dependency `^4.4.8`), `composer.lock`
- Modify: `config/livewire.php` (`'layout'` → `'component_layout'`, `'lazy_placeholder'` → `'component_placeholder'`)
- Modify: `public/css/filament/**`, `public/js/filament/**`

คู่มือ Filament 5 มีแค่สองขั้น: `composer require filament/upgrade:"^5.0" -W --dev`, `vendor/bin/filament-v5` แล้วทำตามคู่มือ Livewire 4 (`livewire.laravel.com/docs/4.x/upgrading`) ข้อที่กระทบ repo นี้:

- config key เปลี่ยนชื่อ: `config/livewire.php:41` (`layout`) และ `:53` (`lazy_placeholder`) และ `smart_wire_keys` เริ่มเป็น `true`
- `wire:model` ฟังเฉพาะ event ของ element ตัวเอง (ต้อง `.deep` ถ้าจะเอา event ของลูก) และ `.blur`/`.change` คุมการ sync ฝั่ง client ด้วย view ของเราไม่มี `wire:model` เลย (มีแค่ `wire:click` ×3, `wire:submit` ×2, `wire:target` ×1) จึงโดนเฉพาะผ่านฟอร์มของ Filament ซึ่ง Filament 5 จัดการเอง ให้ลองฟอร์มที่ใช้ `live(onBlur: true)`
- tag ของ component ต้องปิดให้ถูก ไม่งั้นเนื้อหาที่ตามมากลายเป็น slot ให้ตรวจ Blade ใน `resources/views/filament/**`
- URL endpoint เปลี่ยนจาก `/livewire/...` เป็น `/livewire-{hash}/...` ถ้า CDN/ไฟร์วอลล์ของโฮสต์มีกฎที่อิง path `/livewire/` ต้องปรับ (ปัจจุบัน script บนโฮสต์โหลดจาก `/livewire/livewire.min.js`)
- ไม่ได้ใช้ Volt, `wire:transition`, `stream()` หรือ `Route::livewire`
- script BODY_END ใน `AdminPanelProvider` รอ event `alpine:initialized` ซึ่งยังมีเพราะ Alpine ยังอยู่ใน Livewire 4 ต้องลองบนมือถือ

- [ ] **Step 1:** รัน upgrade script ของ v5 และ `composer update -W` ใน container
- [ ] **Step 2:** แก้ `config/livewire.php` และ Blade ตามรายการข้างบน
- [ ] **Step 3:** รันเทสต์ทั้งชุด คาดว่าผ่าน
- [ ] **Step 4:** จับภาพทุกหน้าเทียบกับผลของ Task 5 (ไม่ควรต่าง)
- [ ] **Step 5:** `composer remove filament/upgrade --dev`, commit asset และ `chore(deps): upgrade to Filament 5 and Livewire 4`

**เช็กในเบราว์เซอร์:** รายการเดียวกับ Task 5 เน้นสิ่งที่ใช้ Livewire: ค้นหาเอกสารพิมพ์แล้วผลขึ้น, toggle, ฟอร์มที่ `live(onBlur: true)` (ช่องอีเมลใน `UserResource::emailField`), modal ของ action, polling (ถ้ามี), เปิดหน้าเดิมค้างไว้ข้ามการ deploy แล้วกดปุ่ม
**หลัง deploy:** ดู error log ของโฮสต์ 1 วัน และถามผู้ใช้สองสามคนว่าใช้ได้ปกติ
**Rollback:** มาตรฐาน (กลับไป commit ของ Task 5 ซึ่ง deploy ใช้จริงมาแล้ว)

### Task 7: เก็บงานหลังอัปเกรด

**Files:** `tests/Feature/*.php` ที่ใช้ helper ทดสอบตารางที่ deprecated, ไฟล์ที่ใช้ `Forms\Components\Placeholder`

- [ ] **Step 1:** เปลี่ยน `callTableAction('x', $record)` เป็น `callAction(TestAction::make('x')->table($record))` และ helper อื่นในกลุ่มเดียวกัน ทีละไฟล์ รันเทสต์ไฟล์นั้นหลังแก้แต่ละไฟล์
- [ ] **Step 2:** เปลี่ยน `Placeholder` เป็น `TextEntry::make()->state(...)` และเช็กหน้าตาว่าเหมือนเดิม
- [ ] **Step 3:** รันเทสต์ทั้งชุดและเปิด PHPUnit `--display-deprecations` ต้องไม่เหลือ deprecation จาก Filament
- [ ] **Step 4:** commit `test: use Filament 5 action testing helpers`

**Rollback:** มาตรฐาน (แก้แค่เทสต์และ UI เล็กน้อย)

---

## ความเสี่ยงและวิธีลด

| ความเสี่ยง | Task | โอกาส | ผลกระทบ | วิธีลด |
| --- | --- | --- | --- | --- |
| สีหรือ layout ของธีมเพี้ยน | 5 | สูง | กลาง (ใช้ได้แต่ดูผิด) | จับภาพก่อน/หลังทุกหน้า แก้ `rgb(var())` และ selector 5 ตัว |
| สิทธิ์ใน resource หลุด (`can*` ไม่ถูกเรียก) | 5 | กลาง | สูง (ข้อมูลหลักฐาน) | ย้ายไป policy + เทสต์ 403 ก่อนแก้ |
| หน้า login พฤติกรรมเปลี่ยน (ข้อความ, rate limit, เช็กสถานะ) | 5 | กลาง | สูง (คนเข้าระบบไม่ได้) | `LoginStatusTest`, `LoginLogTest` + ลองมือทุกกรณี |
| filter/pagination เปลี่ยนพฤติกรรมเงียบ ๆ | 5 | สูงถ้าลืมตั้ง | ต่ำ–กลาง | `configureUsing` + เทสต์ filter |
| ผู้ใช้หลุดจากระบบตอน deploy | 2 | ต่ำ (ถ้าไม่แตะ config) | กลาง | ห้ามเปลี่ยน `session.serialization` |
| cache ที่มี object อ่านไม่ได้ | 2 | ต่ำ (ถ้าไม่แตะ config) | กลาง | ห้ามใส่ `serializable_classes=false` ใน PR นี้ |
| ไฟล์ xls/csv บางแบบอ่านต่างไป | 4 | ต่ำ–กลาง | กลาง | เทสต์ + ไฟล์จริงของหน่วย |
| CDN/ไฟร์วอลล์ของโฮสต์ไม่ผ่าน path ใหม่ของ Livewire 4 | 6 | ต่ำ | สูง (ปุ่มทุกปุ่มไม่ทำงาน) | ลองกดปุ่มทันทีหลัง deploy, rollback พร้อม |
| `composer install` บนโฮสต์ใช้ RAM/เวลาเกิน | ทุก Task | ต่ำ | กลาง | deploy นอกเวลาใช้งาน เปิด `php artisan down` ไว้ระหว่างนั้น |

## กลยุทธ์ทดสอบ

- **อัตโนมัติ:** เทสต์ 331 ตัวทุก PR (CI + Docker) และเทสต์ใหม่ใน Task 5 สำหรับ policy, filter และลิงก์แท็บ ส่วน `tests/Feature/PagesTest.php` (เปิดทุกหน้าในฐานะ guest/user/admin) เป็น smoke test หลัก ถ้า Task 5 เจอหน้าที่ไม่มีในนั้นให้เพิ่มเข้าไป
- **ภาพหน้าจอ:** Task 1, 5 และ 6 จับภาพทุกหน้าใน `PagesTest` ก่อน/หลัง ที่ 1280 และ 375 px ทั้ง light/dark แล้วแนบใน PR
- **ข้อมูลจริง:** ทุก PR ทดสอบ export Excel และนำเข้าอีเมลด้วยไฟล์ตัวอย่างของหน่วย (เก็บนอก repo เพราะมีข้อมูลบุคคล)
- **หลัง deploy:** ผู้ใช้ล็อกอินด้วยบัญชีจริงแล้วเช็กหน้า Home, รายการเอกสาร, รับทราบหนึ่งรายการ (บัญชีทดสอบ) และ Follow ผมเช็กหน้าที่ไม่ต้องล็อกอินและ log ได้แบบอ่านอย่างเดียว

## คำถามที่ผู้ใช้ต้องตัดสินใจ

1. **deploy Filament 4 ขึ้น production ก่อนไหม** หรือทำ Task 5 กับ 6 ต่อกันแล้ว deploy ทีเดียว ผมแนะนำให้ deploy Filament 4 ก่อน แล้วใช้จริงอย่างน้อย 1 สัปดาห์ เพื่อแยกปัญหาของ Filament ออกจาก Livewire 4 และมีจุด rollback ที่ใช้งานได้จริง (แต่ Filament 4 หมด bug fix 15 ม.ค. 2027 จึงไม่ควรค้างนาน)
2. ~~ภาพพื้นหลังหน้า login~~ **ตอบแล้ว (2026-10-28):** คงภาพรันเวย์เดิม (`bg2.jpg`) ไว้ Task 1 ส่งภาพผ่านตัวแปร CSS ของเราเองแทน plugin
3. **ช่วงเวลา deploy** ของแต่ละขั้น (แนะนำนอกเวลาเข้าเวร) และใครเป็นคนเช็กหลังล็อกอินด้วยบัญชีจริง
4. **ตัวเลือกแสดง "ทั้งหมด" (all) ในตาราง** Filament 4 เอาออกจากค่าเริ่มต้น มีใครใช้อยู่ไหม ถ้าใช้ ผมจะใส่กลับให้ตารางที่ต้องการ
5. **เปิด `session.serialization=json` และ `cache.serializable_classes` ตาม Laravel 13 ไหม** ช่วยลดความเสี่ยงถ้า `APP_KEY` หลุด แต่ทุกคนต้องล็อกอินใหม่หนึ่งครั้ง และต้องแก้ cache ของหน้า Credential Expiry ก่อน ผมแนะนำให้ทำเป็น PR แยกหลัง Task 2
6. **เปิด 2FA ของ Filament 5 ให้ admin/super-admin** (roadmap ข้อ 4.2) จะทำต่อจาก Task 6 ทันทีไหม
