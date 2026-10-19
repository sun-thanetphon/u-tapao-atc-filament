<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;

Route::get('/', function () {
    return redirect('/admin');
});

// เปิดได้เฉพาะ PDF ของเอกสาร: ผู้จัดการเอกสารเปิดได้ทุกฉบับ ผู้ใช้ทั่วไปเปิดได้เฉพาะฉบับที่แผนกตัวเองเห็น
// ไฟล์อื่นใน storage (เช่น email-imports, livewire-tmp) ตอบ 404 เสมอ
Route::get('/storage/{folder}/{path}', function ($folder, $path) {
    if (Auth::check()) {
        $filePath = $folder . '/' . $path;
        $documents = \App\Filament\Resources\DocumentResource::canAccess()
            ? \App\Models\Document::query()
            : \App\Filament\Resources\DocumentTaskResource::visibleQuery(\App\Models\Document::query());

        $document = $documents->where('file_path', $filePath)->first();

        if (!$document || !Storage::disk('local')->exists($filePath)) {
            abort(404);
        }
        // ส่งเป็นไฟล์ให้มี Content-Length และรองรับ Range ไม่งั้น CDN ของ Hostinger ส่ง PDF ค้างกลางทาง
        // ชื่อไฟล์เป็นชื่อเอกสารจริง (ชื่อสำรองเป็น ASCII สำหรับโปรแกรมที่อ่านชื่อไทยไม่ได้) ไม่ใช่รหัสสุ่มบนดิสก์
        $asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $document->code) . '.pdf';

        return response()->file(Storage::disk('local')->path($filePath), [
            'Content-Type' => Storage::disk('local')->mimeType($filePath),
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $document->downloadName(),
                $asciiName,
            ),
        ]);
    }
    return redirect()->intended('admin');
})->name('view.pdf');

// หน้ารายงานผู้รับทราบเอกสารสำหรับพิมพ์/บันทึกเป็น PDF (เฉพาะ super-admin เหมือนหน้าติดตาม)
Route::get('/documents/{document}/follow/print', function (\App\Models\Document $document) {
    abort_unless(Auth::check() && Auth::user()->hasRole(\App\Enums\RoleEnum::SUPERADMIN), 403);

    return view('exports.follow-print', [
        'document' => $document,
        'users' => (new \App\Exports\FollowExport($document))->users(),
    ]);
})->middleware('web')->name('follow.print');

// วิดีโอแนะนำที่อัปโหลดไว้ ส่งผ่าน PHP เพื่อให้เฉพาะผู้ล็อกอินดูได้ (ไฟล์อยู่นอก web root)
// ใช้ response()->file() ให้มี Content-Length และรองรับ Range (กรอวิดีโอได้) ไม่งั้น CDN ของ Hostinger ส่งไฟล์ค้าง
Route::get('/tutorial-video/{video}', function (\App\Models\TutorialVideo $video) {
    if (!Auth::check()) {
        return redirect()->intended('admin');
    }

    $canSeeDrafts = Auth::user()->hasAnyRole([\App\Enums\RoleEnum::SUPERADMIN, \App\Enums\RoleEnum::ADMIN]);

    if (!$video->isUpload() || !($video->publish || $canSeeDrafts) || !Storage::disk('local')->exists((string) $video->file_path)) {
        abort(404);
    }

    return response()->file(Storage::disk('local')->path($video->file_path), [
        'Content-Type' => $video->mimeType(),
    ]);
})->name('view.tutorial-video');
