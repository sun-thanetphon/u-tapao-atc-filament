<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/storage/documents/{path}', function ($path) {
    if (Auth::check()) {
        $path = 'documents/' . $path;
        if (!Storage::disk('local')->exists($path)) {
            abort(404);
        }
        $file = Storage::disk('local')->get($path);
        $mimeType = Storage::disk('local')->mimeType($path);
        return Response::make($file, 200)->header("Content-Type", $mimeType);
    }
    return redirect()->intended('admin');
});