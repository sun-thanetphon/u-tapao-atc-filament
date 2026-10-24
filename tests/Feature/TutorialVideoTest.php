<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Models\TutorialVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * วิดีโอแนะนำ: ทุกคนที่ล็อกอินดูได้ เฉพาะแอดมินจัดการได้
 */
class TutorialVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        Storage::fake('local');
        Storage::disk('local')->put('tutorials/intro.mp4', 'fake-mp4-bytes');
    }

    private function uploaded(array $attributes = []): TutorialVideo
    {
        return TutorialVideo::create([
            'title' => 'วิธีรับทราบเอกสาร',
            'source' => 'upload',
            'file_path' => 'tutorials/intro.mp4',
            'seq' => 1,
            'publish' => true,
            ...$attributes,
        ]);
    }

    public function test_logged_in_user_can_stream_an_uploaded_video(): void
    {
        $video = $this->uploaded();
        $this->actingAs($this->makeUser($this->section('ADC')));

        $this->get("/tutorial-video/{$video->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'video/mp4')
            // ไม่มี Content-Length แล้ว CDN ของ Hostinger ส่งไฟล์ค้างกลางทาง
            ->assertHeader('Content-Length', (string) strlen('fake-mp4-bytes'))
            ->assertHeader('Accept-Ranges', 'bytes');
    }

    public function test_range_requests_are_capped_to_256_kilobytes(): void
    {
        // CDN ของ Hostinger ส่งคำตอบก้อนใหญ่จาก PHP ค้าง จึงตอบทีละไม่เกิน 256 KB ให้เบราว์เซอร์ขอต่อเอง
        $size = 1024 * 1024;
        Storage::disk('local')->put('tutorials/intro.mp4', str_repeat('x', $size));
        $video = $this->uploaded();
        $this->actingAs($this->makeUser($this->section('ADC')));

        $this->get("/tutorial-video/{$video->id}", ['Range' => 'bytes=0-'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-262143/' . $size)
            ->assertHeader('Content-Length', '262144');

        // ก้อนท้ายไฟล์สั้นกว่า 256 KB ได้
        $this->get("/tutorial-video/{$video->id}", ['Range' => 'bytes=917504-'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 917504-1048575/' . $size);

        $this->get("/tutorial-video/{$video->id}", ['Range' => 'bytes=100-199'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 100-199/' . $size)
            ->assertHeader('Content-Length', '100');
    }

    public function test_guest_cannot_stream_a_video(): void
    {
        $video = $this->uploaded();

        $this->get("/tutorial-video/{$video->id}")->assertRedirect();
        $this->assertGuest();
    }

    public function test_unpublished_video_is_only_for_admins(): void
    {
        $video = $this->uploaded(['publish' => false]);

        $this->actingAs($this->makeUser($this->section('ADC')));
        $this->get("/tutorial-video/{$video->id}")->assertNotFound();

        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::ADMIN));
        $this->get("/tutorial-video/{$video->id}")->assertOk();
    }

    public function test_embedded_video_has_no_file_to_stream(): void
    {
        $video = $this->uploaded(['source' => 'embed', 'file_path' => null, 'embed_url' => 'https://youtu.be/dQw4w9WgXcQ']);
        $this->actingAs($this->makeUser($this->section('ADC')));

        $this->get("/tutorial-video/{$video->id}")->assertNotFound();
    }

    public function test_embed_url_is_converted_only_for_known_hosts(): void
    {
        $embed = fn (string $url) => (new TutorialVideo(['source' => 'embed', 'embed_url' => $url]))->embedSrc();

        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $embed('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $embed('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=5'));
        $this->assertSame('https://player.vimeo.com/video/123456789', $embed('https://vimeo.com/123456789'));
        $this->assertNull($embed('https://evil.example.com/watch?v=abc'));
        $this->assertNull($embed('javascript:alert(1)'));
    }

    public function test_user_sees_published_videos_on_the_page(): void
    {
        $this->uploaded(['title' => 'คลิปที่เห็นได้']);
        $this->uploaded(['title' => 'คลิปที่ซ่อน', 'publish' => false]);
        $this->uploaded(['title' => 'คลิป YouTube', 'source' => 'embed', 'file_path' => null, 'embed_url' => 'https://youtu.be/dQw4w9WgXcQ']);
        $this->actingAs($this->makeUser($this->section('ADC')));

        // หน้ารายการแสดงเฉพาะชื่อ ไม่โหลดตัวเล่นวิดีโอ
        $this->get('/admin/learning-video')
            ->assertOk()
            ->assertSee('Learning Video')
            ->assertSee('คลิปที่เห็นได้')
            ->assertSee('คลิป YouTube')
            ->assertDontSee('คลิปที่ซ่อน')
            ->assertDontSee('<video', false)
            ->assertDontSee('<iframe', false);
    }

    public function test_watch_page_plays_an_uploaded_video(): void
    {
        $video = $this->uploaded(['title' => 'คลิปที่เล่นได้']);
        $this->actingAs($this->makeUser($this->section('ADC')));

        $this->get("/admin/learning-video/{$video->id}")
            ->assertOk()
            ->assertSee('คลิปที่เล่นได้')
            ->assertSee("/tutorial-video/{$video->id}", false);
    }

    public function test_watch_page_embeds_a_youtube_video(): void
    {
        $video = $this->uploaded(['source' => 'embed', 'file_path' => null, 'embed_url' => 'https://youtu.be/dQw4w9WgXcQ']);
        $this->actingAs($this->makeUser($this->section('ADC')));

        $this->get("/admin/learning-video/{$video->id}")
            ->assertOk()
            ->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ', false);
    }

    public function test_watch_page_hides_unpublished_video_from_regular_users(): void
    {
        $video = $this->uploaded(['publish' => false]);
        $this->actingAs($this->makeUser($this->section('ADC')));

        $this->get("/admin/learning-video/{$video->id}")->assertNotFound();
    }

    public function test_livewire_temporary_uploads_accept_videos_up_to_300_mb(): void
    {
        // กฎนี้ใช้กับทุกฟอร์มอัปโหลด ถ้าไม่มี mp4/webm ฟอร์มวิดีโอจะ "Error during upload"
        $rules = config('livewire.temporary_file_upload.rules');

        $this->assertContains('mimes:pdf,csv,txt,xlsx,xls,mp4,webm', $rules);
        $this->assertContains('max:307200', $rules);
    }

    public function test_regular_user_cannot_open_the_manage_page(): void
    {
        $this->actingAs($this->makeUser($this->section('ADC')));

        $this->get('/admin/manage-learning-video')->assertForbidden();
    }

    public function test_admin_can_open_the_manage_page(): void
    {
        $this->uploaded(['title' => 'คลิปที่ซ่อน', 'publish' => false]);
        $this->actingAs($this->makeUser($this->section('ADC'), RoleEnum::ADMIN));

        $this->get('/admin/manage-learning-video')->assertOk()->assertSee('คลิปที่ซ่อน');
    }
}
