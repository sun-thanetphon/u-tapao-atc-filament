<?php

namespace Tests\Feature;

use App\Support\GoogleDriveBackup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleDriveBackupTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google_drive' => [
            'client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'refresh', 'folder_name' => 'U-Tapao ATC Backups',
        ]]);
        $this->file = tempnam(sys_get_temp_dir(), 'db-') . '.sql.gz';
        file_put_contents($this->file, 'dump');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_configured_requires_all_three_values(): void
    {
        $this->assertTrue(GoogleDriveBackup::configured());

        config(['services.google_drive.refresh_token' => null]);
        $this->assertFalse(GoogleDriveBackup::configured());
    }

    public function test_upload_creates_folder_then_uploads_file(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at']),
            'www.googleapis.com/drive/v3/files?q=*' => Http::response(['files' => []]),
            'www.googleapis.com/drive/v3/files?fields=id' => Http::response(['id' => 'folder1']),
            'www.googleapis.com/upload/*' => Http::response([], 200, ['Location' => 'https://upload.example/session']),
            'upload.example/*' => Http::response(['id' => 'file1']),
        ]);

        $this->assertSame('file1', (new GoogleDriveBackup())->upload($this->file));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://upload.example/session'
            && $r->hasHeader('Content-Range', 'bytes 0-3/4')
            && $r->body() === 'dump');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'uploadType=resumable')
            && $r['parents'] === ['folder1']
            && $r['name'] === basename($this->file));
    }

    public function test_prune_deletes_all_but_newest(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at']),
            'www.googleapis.com/drive/v3/files?q=*' => Http::sequence()
                ->push(['files' => [['id' => 'folder1']]])
                ->push(['files' => [['id' => 'new'], ['id' => 'old1'], ['id' => 'old2']]]),
            'www.googleapis.com/drive/v3/files/*' => Http::response('', 204),
        ]);

        $this->assertSame(2, (new GoogleDriveBackup())->prune('db', 1));

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/files/old1'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/files/new'));
    }
}
