<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\DocumentAcknowledge;
use App\Models\DocumentCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * ลบข้อมูลที่ DemoDocumentsSeeder สร้างไว้ (เอกสาร ประเภท บัญชีทดสอบ และไฟล์ PDF ตัวอย่าง)
 *
 * รัน:  php artisan db:seed --class=DemoDocumentsCleanupSeeder
 */
class DemoDocumentsCleanupSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('ห้ามรัน seeder ข้อมูลตัวอย่างบน production');

            return;
        }

        $documentIds = Document::withTrashed()->where('code', 'like', DemoDocumentsSeeder::CODE_PREFIX . '%')->pluck('id');
        DocumentAcknowledge::whereIn('document_id', $documentIds)->delete();
        Document::withoutEvents(fn () => Document::withTrashed()->whereIn('id', $documentIds)->forceDelete());

        DocumentCategory::whereIn('name', DemoDocumentsSeeder::CATEGORIES)->delete();

        User::withTrashed()->whereIn('username', ['demo_user', 'demo_super'])->get()->each(function (User $user) {
            DocumentAcknowledge::where('user_id', $user->id)->delete();
            $user->forceDelete();
        });

        Storage::disk('local')->delete('documents/demo.pdf');

        $this->command?->info('ลบข้อมูลตัวอย่างแล้ว');
    }
}
