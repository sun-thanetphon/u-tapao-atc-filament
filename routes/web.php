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
