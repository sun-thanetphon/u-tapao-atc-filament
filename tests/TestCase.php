<?php

namespace Tests;

use App\Enums\RoleEnum;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Rank;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\DocumentCategorySeeder;
use Database\Seeders\RankSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SectionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // bootstrap/app.php สลับไปใช้ public_html เมื่อไม่ใช่ local ซึ่งไม่มีบนเครื่องที่รันเทสต์
        $this->app->usePublicPath(base_path('public'));
    }

    /**
     * กันไม่ให้ RefreshDatabase ล้างฐานข้อมูลจริงโดยไม่ตั้งใจ
     * (เช็กก่อน trait ทำงาน เพราะ RefreshDatabase จะล้างตารางทันทีใน setUpTraits)
     */
    protected function setUpTraits()
    {
        $database = DB::connection()->getDatabaseName();

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests against [{$database}]. Use a database whose name ends with _test.");
        }

        return parent::setUpTraits();
    }

    protected function seedReferenceData(): void
    {
        $this->seed([RankSeeder::class, SectionSeeder::class, DocumentCategorySeeder::class, RolePermissionSeeder::class]);
    }

    /**
     * id ของแผนกจากรหัส เช่น ADC (id เปลี่ยนทุกเทสต์ เพราะ MySQL ไม่ย้อน auto increment ตอน rollback)
     */
    protected function section(string $prefix): int
    {
        return Section::query()->where('prefix', $prefix)->value('id');
    }

    protected function makeUser(int $sectionId, string $role = RoleEnum::USER): User
    {
        $user = User::create([
            'rank_id' => Rank::query()->value('id'),
            'section_id' => $sectionId,
            'username' => 'test_' . Str::random(8),
            'firstname' => 'ทดสอบ',
            'lastname' => Str::random(6),
            'password' => bcrypt('password'),
        ]);

        return $user->assignRole($role);
    }

    /**
     * สร้างเอกสารแบบเดียวกับที่ฟอร์มบันทึก (id แผนกเป็น string)
     *
     * @param  array<int>  $viewSections
     * @param  array<int>  $acknowledgeSections
     */
    protected function makeDocument(array $viewSections, array $acknowledgeSections = [], array $attributes = []): Document
    {
        return Document::withoutEvents(fn () => Document::create([
            'code' => 'T' . Str::upper(Str::random(8)),
            'name' => 'เอกสารทดสอบ ' . Str::random(4),
            'view_sections' => array_map('strval', $viewSections),
            'acknowledge_sections' => array_map('strval', $acknowledgeSections),
            'category_id' => DocumentCategory::query()->value('id'),
            'file_path' => 'documents/test.pdf',
            'publish' => true,
            ...$attributes,
        ]));
    }
}
