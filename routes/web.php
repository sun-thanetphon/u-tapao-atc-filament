<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

        if (!$documents->where('file_path', $filePath)->exists() || !Storage::disk('local')->exists($filePath)) {
            abort(404);
        }
        // ส่งเป็นไฟล์ให้มี Content-Length และรองรับ Range ไม่งั้น CDN ของ Hostinger ส่ง PDF ค้างกลางทาง
        return response()->file(Storage::disk('local')->path($filePath), [
            'Content-Type' => Storage::disk('local')->mimeType($filePath),
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
