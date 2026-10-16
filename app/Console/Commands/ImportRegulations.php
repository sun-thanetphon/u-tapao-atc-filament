<?php

namespace App\Console\Commands;

use App\Enums\RoleEnum;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Section;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * นำเข้า PDF กฎระเบียบ กพท. เป็นเอกสารประเภท "CAAT Aviation Regulation" ให้ทุกแผนกเห็น (ไม่ต้องรับทราบ)
 *
 * โฟลเดอร์ต้นทางมี manifest.json = [{"name": "...", "file": "files/0001.pdf"}, ...]
 * รันซ้ำได้: เอกสารที่มีชื่อเดียวกันในประเภทนี้อยู่แล้วจะข้าม
 */
class ImportRegulations extends Command
{
    protected $signature = 'regulations:import {dir : โฟลเดอร์ที่มี manifest.json และ files/} {--dry-run}';

    protected $description = 'นำเข้า PDF กฎระเบียบ กพท. เป็นเอกสารที่ทุกแผนกเห็น';

    private const CATEGORY = 'CAAT Aviation Regulation';

    public function handle(): int
    {
        $dir = rtrim($this->argument('dir'), '/\\');
        $manifestPath = $dir . '/manifest.json';

        if (!is_file($manifestPath)) {
            $this->error("ไม่พบ {$manifestPath}");

            return self::FAILURE;
        }

        $entries = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        $category = DocumentCategory::where('name', self::CATEGORY)->first();
        $creator = User::role(RoleEnum::SUPERADMIN)->orderBy('id')->first();
        $sections = Section::orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();

        if (!$category || !$creator || !$sections) {
            $this->error('ต้องมีประเภทเอกสาร ผู้ใช้ super-admin และแผนกในระบบก่อน (php artisan migrate)');

            return self::FAILURE;
        }

        // DocumentObserver ใช้ auth()->user() เป็นผู้สร้างเอกสาร
        Auth::setUser($creator);

        $existing = Document::withTrashed()->where('category_id', $category->id)->pluck('name')->flip();
        $created = $skipped = 0;

        foreach ($entries as $entry) {
            $source = $dir . '/' . $entry['file'];

            if (isset($existing[$entry['name']])) {
                $skipped++;

                continue;
            }
            if (!is_file($source)) {
                $this->warn("ไม่พบไฟล์ {$entry['file']}");

                continue;
            }
            if ($this->option('dry-run')) {
                $created++;

                continue;
            }

            $path = 'documents/' . Str::random(40) . '.pdf';
            Storage::disk('local')->put($path, fopen($source, 'rb'));

            Document::create([
                'category_id' => $category->id,
                'view_sections' => $sections,
                'acknowledge_sections' => [],
                'name' => $entry['name'],
                'file_path' => $path,
                'publish' => true,
            ]);
            $created++;
        }

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '') . "สร้าง {$created} ข้าม {$skipped}");

        return self::SUCCESS;
    }
}
