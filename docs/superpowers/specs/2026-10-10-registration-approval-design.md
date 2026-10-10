# ระบบสมัครสมาชิก อนุมัติ และรีเซ็ตรหัสผ่าน

วันที่: 2026-10-10
สถานะ: รอรีวิว

## 1. เป้าหมาย

ให้เจ้าหน้าที่สมัครบัญชีเองได้ แต่บัญชีจะใช้งานได้ก็ต่อเมื่อ `super-admin` หรือ `admin` อนุมัติ และให้ผู้ใช้ที่ลืมรหัสผ่านกู้คืนเองได้ผ่านอีเมล หรือขอให้ admin ช่วยรีเซ็ตเมื่อไม่มีอีเมล

เกณฑ์ความสำเร็จ
- คนที่สมัครเองเข้าระบบไม่ได้จนกว่าจะอนุมัติ
- ไม่มีทางที่ผู้สมัครจะกำหนด role หรือสถานะของตัวเองได้
- ผู้ใช้เดิมทั้งหมดยังล็อกอินได้ตามปกติหลัง migrate
- อีเมลส่งไม่สำเร็จแล้วระบบไม่พัง ผู้ใช้ยังกู้รหัสผ่านผ่าน admin ได้

## 2. บริบทของระบบเดิม

- Laravel 12 + Filament 3.3 + spatie/laravel-permission ใช้ role `super-admin`, `admin`, `user` (`App\Enums\RoleEnum`) และ permission แบบ `user.view` ฯลฯ (`App\Enums\PermissionEnum`)
- ล็อกอินด้วย `username` ผ่าน `App\Providers\Filament\Auth\CustomLogin`
- ตาราง `users` ไม่มีคอลัมน์ `email` และ `username` ไม่มี unique constraint ใช้ SoftDeletes
- `User::canAccessPanel` ผ่านเมื่อมี role ใดๆ ใน 3 role
- `UserResource` ซ่อน role `super-admin` ออกจากตัวเลือก และซ่อนปุ่มแก้ไขผู้ใช้ `id == 1` จากผู้ที่ไม่ใช่ super-admin
- Production เป็น shared host (Hostinger) ไม่มี build step และไม่มี queue worker
- SMTP ของ Hostinger ทดสอบแล้วใช้ได้ที่ `smtp.hostinger.com:587` (`MAIL_SCHEME=null`) ส่วน 465 เชื่อมต่อไม่ผ่านจาก server

## 3. การตัดสินใจ

| เรื่อง | ตัดสินใจ |
|---|---|
| ฟอร์มสมัคร | ยศ, หน่วยงาน, username, ชื่อ, นามสกุล, อีเมล, รหัสผ่าน, ยืนยันรหัสผ่าน |
| ที่เก็บผู้รออนุมัติ | คอลัมน์ `status` ในตาราง `users` |
| ผู้อนุมัติ | permission ใหม่ `user.approve` ให้ `super-admin` และ `admin` |
| role ตอนอนุมัติ | เลือกได้ระหว่าง `user` (ค่าเริ่มต้น) และ `admin` ห้ามเลือก `super-admin` |
| ผู้ถูกปฏิเสธ | สมัครใหม่เองไม่ได้ ต้องติดต่อ admin เพื่อแก้สถานะ |
| ลืมรหัสผ่าน | ทางอีเมลสำหรับคนที่มีอีเมล และทาง admin รีเซ็ตให้สำหรับคนที่ไม่มีอีเมล |
| การส่งอีเมล | synchronous ไม่ใช้ queue |

## 4. โครงสร้างข้อมูล

### 4.1 ตาราง `users` (migration ใหม่ ไม่ลบข้อมูลเดิม)

| คอลัมน์ | ชนิด | หมายเหตุ |
|---|---|---|
| `email` | string, nullable | ผู้ใช้เดิมว่างได้ ผู้สมัครใหม่บังคับกรอก |
| `status` | string, default `active` | `pending` / `active` / `rejected` ผู้ใช้เดิมทั้งหมดเป็น `active` |
| `approved_by` | foreignId nullable → users | ผู้ตัดสินใจ |
| `approved_at` | timestamp nullable | เวลาตัดสินใจ (ใช้ทั้งอนุมัติและปฏิเสธ) |
| `rejected_reason` | text nullable | |
| `must_change_password` | boolean, default false | บังคับเปลี่ยนรหัสหลังรหัสชั่วคราว |

- `username` และ `email` ไม่ใช้ DB unique เพราะมี SoftDeletes ให้ตรวจซ้ำในโค้ดแบบนับเฉพาะแถวที่ยังไม่ถูกลบ (`Rule::unique(...)->whereNull('deleted_at')`) แต่เพิ่ม index ธรรมดาเพื่อความเร็ว
- ก่อน migrate ต้องตรวจว่า `username` เดิมไม่ซ้ำกัน ถ้าซ้ำให้รายงานแล้วหยุด ไม่แก้ข้อมูลเอง
- `status`, `approved_by`, `approved_at`, `rejected_reason`, `must_change_password` **ไม่อยู่ใน `$fillable`** เซ็ตผ่านโค้ดฝั่ง server เท่านั้น ส่วน `email` เพิ่มใน `$fillable`
- `User` ต้องมี cast หรือค่าคงที่สำหรับสถานะ (`App\Enums\UserStatus`)

### 4.2 ตาราง `password_reset_requests` (ใหม่)

| คอลัมน์ | ชนิด |
|---|---|
| `id` | id |
| `user_id` | foreignId → users |
| `status` | string: `open` / `done` |
| `handled_by` | foreignId nullable → users |
| `handled_at` | timestamp nullable |
| `timestamps` | |

คำขอที่ `open` ได้ 1 รายการต่อผู้ใช้ ขอซ้ำให้ใช้รายการเดิม

### 4.3 สิทธิ์

migration ใหม่สร้าง permission `user.approve` และผูกกับ role `super-admin` และ `admin` (ไม่พึ่ง seeder เพราะ production รัน seeder ซ้ำไม่ได้) เพิ่มค่าคงที่ `USER_APPROVE` ใน `PermissionEnum` และเพิ่มใน `RolePermissionSeeder` สำหรับการติดตั้งใหม่ด้วย ต้องล้างแคชสิทธิ์ของ Spatie หลังสร้าง

## 5. การสมัครสมาชิก

- หน้า Filament Register ใหม่ (`App\Providers\Filament\Auth\CustomRegister`) ใช้ layout เดียวกับ `CustomLogin` และแสดงลิงก์ "สมัครสมาชิก" ที่หน้า login
- การตรวจสอบ: ฟิลด์ทั้งหมดบังคับกรอก, `email` เป็นรูปแบบอีเมลที่ถูกต้อง, `username` และ `email` ไม่ซ้ำ, รหัสผ่านอย่างน้อย 8 ตัวอักษรและตรงกับช่องยืนยัน
- ฟอร์มไม่รับ `role` และ `status` ทุกกรณี
- สร้างผู้ใช้ด้วย `status = pending` และไม่ให้ role ไม่ล็อกอินให้ แล้วแสดงหน้า "สมัครสำเร็จ รอผู้ดูแลอนุมัติ"
- ป้องกันสแปม: rate limit 3 ครั้งต่อชั่วโมงต่อ IP และฟิลด์ honeypot ซ่อนอยู่ ไม่ใช้ CAPTCHA
- ส่งอีเมลแจ้งผู้ใช้ที่มี `user.approve` และมีอีเมล ว่ามีคำขอใหม่ ถ้าส่งไม่สำเร็จให้บันทึก log และดำเนินการต่อ

## 6. การอนุมัติ

ต่อยอดจาก `UserResource` ไม่สร้างหน้าใหม่

- แท็บ "รออนุมัติ / ใช้งานอยู่ / ไม่อนุมัติ" พร้อมจำนวนในแท็บรออนุมัติ และ badge จำนวนคนรออนุมัติที่เมนู Accounts (แสดงเฉพาะผู้ที่มี `user.approve`)
- ตารางแสดง ยศ หน่วยงาน ชื่อ นามสกุล username อีเมล วันที่สมัคร
- action **อนุมัติ**: เลือก role (`user` เป็นค่าเริ่มต้น หรือ `admin`) ตั้ง `status = active`, `approved_by`, `approved_at` และให้ role ที่เลือก แล้วส่งอีเมลแจ้งผู้สมัคร
- action **ไม่อนุมัติ**: ใส่เหตุผลได้ ตั้ง `status = rejected` พร้อม `approved_by`, `approved_at`, `rejected_reason` แล้วส่งอีเมลแจ้ง
- bulk approve ใช้ role เดียวกันกับทุกคนที่เลือก
- ทั้งสอง action ตรวจ `user.approve` ในตัว action เอง (ไม่พึ่งการซ่อนปุ่ม) ตรวจฝั่ง server ว่า role ที่ส่งมาเป็น `user` หรือ `admin` เท่านั้น และอนุมัติได้เฉพาะแถวที่ยังเป็น `pending` (กันสองคนอนุมัติพร้อมกัน ใช้เงื่อนไข `where status = pending` ใน query ที่อัปเดต)
- ผู้ใช้ที่ `rejected` กลับเป็น `pending` หรือ `active` ได้โดยผู้ที่มี `user.approve` จากแท็บ "ไม่อนุมัติ"
- `CustomLogin` ตรวจ `status` นอกเหนือจาก role: `pending` เห็นข้อความ "บัญชีรอการอนุมัติ" `rejected` เห็นข้อความ "บัญชีไม่ได้รับอนุมัติ กรุณาติดต่อผู้ดูแลระบบ" ข้อความเหล่านี้แสดงหลังจากรหัสผ่านถูกต้องเท่านั้น เพื่อไม่เปิดเผยสถานะของบัญชีให้คนที่ไม่รู้รหัสผ่าน
- `User::canAccessPanel` ตรวจ `status === active` ด้วย

## 7. ลืมรหัสผ่าน

หน้า "ลืมรหัสผ่าน" เดียว (เปิดที่หน้า login) มี 2 ทาง

### 7.1 ทางอีเมล (กู้เอง)

- ผู้ใช้กรอกอีเมล ระบบส่งลิงก์ตั้งรหัสใหม่ผ่าน password broker ของ Laravel (ตาราง `password_reset_tokens` ที่มีอยู่) ลิงก์ใช้ได้ 60 นาที ใช้ได้ครั้งเดียว
- ตอบข้อความเดียวกันเสมอไม่ว่าอีเมลมีในระบบหรือไม่ และมีข้อความ "ไม่พบอีเมล? ตรวจโฟลเดอร์สแปม"
- บัญชีที่ไม่ใช่ `active` ไม่ได้รับลิงก์ (แต่ผู้ใช้ยังเห็นข้อความตอบเหมือนเดิม)
- ตั้งรหัสสำเร็จแล้วออกจากระบบทุกอุปกรณ์ (เปลี่ยน `remember_token` และล้าง session ของผู้ใช้ในตาราง `sessions`) และตั้ง `must_change_password = false`
- rate limit 3 ครั้งต่อชั่วโมงต่อ IP

### 7.2 ทาง admin (ไม่มีอีเมลหรือกู้เองไม่ได้)

- ผู้ใช้กรอก username ระบบสร้างแถวใน `password_reset_requests` (`open`) ตอบข้อความเดียวกันเสมอไม่ว่า username จะมีหรือไม่
- ผู้ที่มี `user.approve` เห็นคำขอเป็น badge และดำเนินการได้ 2 แบบ
  - **ส่งลิงก์ตั้งรหัสใหม่** (ถ้าผู้ใช้มีอีเมล) ใช้ broker เดียวกับ 7.1
  - **ตั้งรหัสชั่วคราว**: ระบบสุ่มรหัส แสดงให้ admin เห็นครั้งเดียวในหน้าต่างยืนยัน ไม่เขียนลง log เก็บเป็น hash ตั้ง `must_change_password = true` แล้ว admin ส่งต่อให้ผู้ใช้เอง
- เสร็จแล้วตั้งคำขอเป็น `done` พร้อม `handled_by`, `handled_at`
- ผู้ใช้ที่ `must_change_password = true` ถูกบังคับไปหน้าเปลี่ยนรหัสหลังล็อกอิน (middleware ใน panel) ใช้งานส่วนอื่นไม่ได้จนกว่าจะเปลี่ยน
- admin ที่ไม่ใช่ super-admin รีเซ็ตรหัสของผู้ใช้ `id == 1` ไม่ได้
- rate limit 3 ครั้งต่อชั่วโมงต่อ IP

### 7.3 ผู้ใช้เดิมที่ไม่มีอีเมล

เพิ่มช่องอีเมลที่หน้าโปรไฟล์ (`ProfileEditCustom`) และที่ฟอร์มแก้ผู้ใช้ใน `UserResource` ให้ผู้ใช้หรือ admin เติมได้ (ตรวจซ้ำเหมือนข้อ 4.1)

## 8. การจัดการ error

- ส่งอีเมลไม่สำเร็จ: ไม่ทำให้การสมัคร การอนุมัติ หรือการขอรีเซ็ตล้มเหลว บันทึก log แล้วแจ้ง admin ด้วย notification "ส่งอีเมลไม่สำเร็จ" ในหน้าที่ admin ทำงานอยู่ ผู้ใช้ที่ขอรีเซ็ตทางอีเมลไม่เห็นความผิดพลาด (ตอบข้อความเดียวกัน) และยังใช้ทาง 7.2 ได้
- ตั้ง timeout ของ SMTP ให้สั้น (10 วินาที) เพื่อไม่ให้หน้าค้าง
- ลิงก์รีเซ็ตหมดอายุหรือใช้แล้ว: แสดงข้อความและลิงก์ไปขอใหม่
- อนุมัติแถวที่ไม่ใช่ `pending`: ไม่ทำอะไรและแจ้งว่ามีผู้จัดการไปแล้ว

## 9. การทดสอบ

เขียนตามรูปแบบใน `tests/Feature` เดิม (Livewire/Filament test) ใช้ `Mail::fake()` และฐานข้อมูลทดสอบ

- **สมัคร**: ข้อมูลครบแล้วได้แถว `pending` ไม่มี role, username/อีเมลซ้ำถูกปฏิเสธ, ส่ง `role`/`status` มาเองแล้วไม่มีผล, ชื่อของผู้ใช้ที่ถูก soft delete นำกลับมาใช้ได้, rate limit ทำงาน
- **ล็อกอิน**: `pending` และ `rejected` ล็อกอินไม่ได้และเห็นข้อความที่ถูกต้อง รหัสผ่านผิดเห็นข้อความทั่วไป
- **อนุมัติ**: super-admin และ admin อนุมัติได้ user ธรรมดาทำไม่ได้, เลือก role `super-admin` ถูกปฏิเสธ, อนุมัติซ้ำไม่เกิดผลซ้ำ, ปฏิเสธเก็บเหตุผล, bulk approve
- **รีเซ็ตทางอีเมล**: ส่งลิงก์ได้, ตอบเหมือนกันเมื่ออีเมลไม่มี, บัญชีไม่ active ไม่ได้ลิงก์, ลิงก์ใช้ได้ครั้งเดียว, ตั้งรหัสแล้ว session เก่าหลุด
- **รีเซ็ตโดย admin**: สร้างคำขอได้และตอบเหมือนกันเมื่อ username ไม่มี, admin ตั้งรหัสชั่วคราวได้, ผู้ใช้ถูกบังคับเปลี่ยนรหัส, admin รีเซ็ต super admin (`id == 1`) ไม่ได้, user ธรรมดาทำไม่ได้
- **ความเข้ากันได้**: ผู้ใช้เดิมที่ `status = active` ล็อกอินและใช้งานได้ตามเดิม, ชุดทดสอบเดิมยังผ่านทั้งหมด
- **อีเมลล้มเหลว**: จำลอง exception ตอนส่งแล้วการสมัครและอนุมัติยังสำเร็จ

## 10. การ deploy

1. `git pull` บน server
2. `php artisan migrate --force` (ไม่ลบข้อมูลเดิม และตั้ง `status = active` ให้ผู้ใช้เดิม)
3. `php artisan optimize:clear`
4. ตรวจ `.env` ของ production: `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.hostinger.com`, `MAIL_PORT=587`, `MAIL_SCHEME=null`, `MAIL_USERNAME` และ `MAIL_FROM_ADDRESS` เป็น `noreply@support.u-tapaoatc.com`
5. เครื่อง local ใช้ `MAIL_MAILER=log`

ไม่มีขั้นตอน `npm run build` ใช้ CSS ธีมแบบ inline เดิม

## 11. นอกขอบเขต

ยืนยันอีเมลตอนสมัคร, 2FA, CAPTCHA, OTP ทาง SMS/LINE, ประวัติการเปลี่ยนรหัสผ่าน, queue สำหรับส่งอีเมล เพิ่มภายหลังได้โดยไม่ต้องรื้อโครงสร้างนี้
