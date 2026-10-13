<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Models\PublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ทุกหน้าที่ปรับหน้าตาต้องเปิดได้ ทั้งผู้ใช้ทั่วไปและผู้ดูแล
 */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function test_root_redirects_to_the_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_guest_sees_the_login_page(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('U-Tapao ATC')
            ->assertSee('ระบบจัดเก็บและติดตามการรับทราบเอกสาร');
    }

    public function test_regular_user_can_open_their_pages(): void
    {
        $user = $this->makeUser($this->section('ADC'));
        $this->makeDocument([$this->section('ADC')], [$this->section('ADC')], ['name' => 'ประกาศที่ต้องรับทราบ']);
        PublicUrl::create(['category' => 'duty-roster', 'name' => 'ตารางเวรทดสอบ', 'url' => 'https://example.com', 'publish' => true, 'seq' => 1]);

        $this->actingAs($user);

        $this->get('/admin/home')
            ->assertOk()
            ->assertSee('สวัสดี, ' . $user->getFullName())
            ->assertSee('ใบรับรองบริการการเดินอากาศ')
            ->assertSee('ตารางเวรทดสอบ');

        $this->get('/admin/document-tasks')
            ->assertOk()
            ->assertSee('ประกาศที่ต้องรับทราบ')
            ->assertSee('มีเอกสาร 1 ฉบับที่รอให้คุณรับทราบ');

        $this->get('/admin/service')->assertOk();
        $this->get('/admin/dashboard')->assertOk();
    }

    public function test_sidebar_starts_closed_on_mobile(): void
    {
        $this->actingAs($this->makeUser($this->section('ADC')));

        // Filament จำสถานะเมนูข้างเป็น "เปิด" ไว้ตั้งแต่แรก บนมือถือเมนูจึงเปิดทับเนื้อหาทุกครั้งที่โหลดหน้า
        $this->get('/admin/document-tasks')
            ->assertOk()
            ->assertSee("matchMedia('(max-width: 1023px)')", false)
            ->assertSee("Alpine.store('sidebar').close()", false);
    }

    public function test_regular_user_cannot_open_admin_resources(): void
    {
        $this->actingAs($this->makeUser($this->section('ADC')));

        $this->get('/admin/documents')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
    }

    public function test_admin_can_open_every_page(): void
    {
        $admin = $this->makeUser($this->section('ADC'), RoleEnum::ADMIN);
        $member = $this->makeUser($this->section('ADC'));
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')], ['name' => 'เอกสารค้างรับทราบ']);
        $document->acknowledges()->create(['user_id' => $member->id, 'acknowledge_date' => now()]);

        $this->actingAs($admin);

        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('การรับทราบเอกสาร')
            ->assertSee('เอกสารค้างรับทราบ')
            ->assertSee('ความคืบหน้าตามแผนก')
            ->assertSee('รับทราบล่าสุด');

        foreach (['/admin/home', '/admin/document-tasks', '/admin/documents', '/admin/document-categories', '/admin/public-urls', '/admin/service', '/admin/users'] as $url) {
            $this->get($url)->assertOk();
        }

        // หน้าติดตามการรับทราบเปิดได้เฉพาะ super-admin
        $this->get("/admin/documents/{$document->id}/follow")->assertForbidden();
    }
}
