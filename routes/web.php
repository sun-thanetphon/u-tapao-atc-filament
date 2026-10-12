<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/storage/{folder}/{path}', function ($folder, $path) {
    if (Auth::check()) {
        $filePath = $folder . '/' . $path;
        if (!Storage::disk('local')->exists($filePath)) {
            abort(404);
        }
        $file = Storage::disk('local')->get($filePath);
        $mimeType = Storage::disk('local')->mimeType($filePath);
        return Response::make($file, 200)->header("Content-Type", $mimeType);
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
