<?php

namespace Tests\Feature;

use App\Support\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        array_map('unlink', glob(storage_path('app/backups') . '/*') ?: []);
        parent::tearDown();
    }

    public function test_db_dump_contains_tables_and_rows(): void
    {
        $this->seedReferenceData();

        $path = app(BackupService::class)->dumpDatabase();
        $sql = gzdecode(file_get_contents($path));

        $this->assertStringContainsString('CREATE TABLE `sections`', $sql);
        $this->assertStringContainsString('INSERT INTO `sections` VALUES', $sql);
    }

    public function test_zip_includes_documents_but_skips_videos_unless_asked(): void
    {
        Storage::disk('local')->put('documents/zz-backup-test.pdf', 'pdf');
        Storage::disk('local')->put('tutorials/zz-backup-test.mp4', 'video');

        try {
            $service = app(BackupService::class);

            $names = $this->zipNames($service->zipFiles());
            $this->assertContains('documents/zz-backup-test.pdf', $names);
            $this->assertNotContains('tutorials/zz-backup-test.mp4', $names);

            $names = $this->zipNames($service->zipFiles(true));
            $this->assertContains('tutorials/zz-backup-test.mp4', $names);
        } finally {
            Storage::disk('local')->delete(['documents/zz-backup-test.pdf', 'tutorials/zz-backup-test.mp4']);
        }
    }

    public function test_prune_keeps_only_newest(): void
    {
        $dir = storage_path('app/backups');
        @mkdir($dir, 0775, true);
        foreach (['20260101-000000', '20260102-000000', '20260103-000000'] as $stamp) {
            file_put_contents("{$dir}/db-{$stamp}.sql.gz", 'x');
        }

        $this->assertSame(2, app(BackupService::class)->prune('db', 1));
        $this->assertFileExists("{$dir}/db-20260103-000000.sql.gz");
        $this->assertFileDoesNotExist("{$dir}/db-20260101-000000.sql.gz");
    }

    public function test_command_runs_db_only(): void
    {
        $this->artisan('backup:run --db-only')->assertSuccessful();

        $this->assertNotEmpty(glob(storage_path('app/backups/db-*.sql.gz')));
    }

    private function zipNames(string $path): array
    {
        $zip = new ZipArchive();
        $zip->open($path);
        $names = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $zip->close();

        return $names;
    }
}
