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

// ไฟล์ฉบับเก่าในประวัติฉบับ เปิดได้เฉพาะผู้จัดการเอกสาร ผู้ใช้ทั่วไปเห็นแค่ฉบับปัจจุบันผ่าน view.pdf
// {file} ต้องเป็นของ {document} (scopeBindings) ไม่งั้น 404
Route::get('/documents/{document}/files/{file}', function (\App\Models\Document $document, \App\Models\DocumentFile $file) {
    if (!Auth::check()) {
        return redirect()->intended('admin');
    }

    if (!\App\Filament\Resources\DocumentResource::canAccess() || !Storage::disk('local')->exists($file->file_path)) {
        abort(404);
    }

    $asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $document->code) . '_rev' . $file->revision . '.pdf';

    return response()->file(Storage::disk('local')->path($file->file_path), [
        'Content-Type' => Storage::disk('local')->mimeType($file->file_path),
        'Content-Disposition' => HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $file->downloadName(),
            $asciiName,
        ),
    ]);
})->scopeBindings()->name('document.file');

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
// ตัดแต่ละ Range ให้ไม่เกิน 256 KB: CDN ส่งคำตอบจาก PHP ก้อนใหญ่ (เช่น bytes=0- ทั้งไฟล์) ค้างหลังไม่กี่ KB
// แต่ก้อนเล็กผ่านครบ เบราว์เซอร์ขอส่วนที่เหลือต่อเองจาก Content-Range ก้อนเล็กทำให้เริ่มเล่น/กรอได้เร็ว
// เพราะ host ส่งได้แค่ราว 100 KB/s (ก้อน 1 MB ต้องรอ ~12 วินาที)
Route::get('/tutorial-video/{video}', function (\Illuminate\Http\Request $request, \App\Models\TutorialVideo $video) {
    if (!Auth::check()) {
        return redirect()->intended('admin');
    }

    $canSeeDrafts = Auth::user()->hasAnyRole([\App\Enums\RoleEnum::SUPERADMIN, \App\Enums\RoleEnum::ADMIN]);

    if (!$video->isUpload() || !($video->publish || $canSeeDrafts) || !Storage::disk('local')->exists((string) $video->file_path)) {
        abort(404);
    }

    $chunk = 256 * 1024;
    if (preg_match('/^bytes=(\d+)-(\d*)$/', (string) $request->headers->get('Range'), $m)) {
        $end = $m[1] + $chunk - 1;
        if ($m[2] !== '') {
            $end = min($end, (int) $m[2]);
        }
        $request->headers->set('Range', 'bytes=' . $m[1] . '-' . $end);
    }

    return response()->file(Storage::disk('local')->path($video->file_path), [
        'Content-Type' => $video->mimeType(),
    ]);
})->name('view.tutorial-video');
