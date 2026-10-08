<?php

namespace App\Filament\Custom;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Swis\Filament\Backgrounds\Image;
use Swis\Filament\Backgrounds\ImageProviders\MyImages;

class PublicHtmlImages extends MyImages
{
    /**
     * ฟังก์ชันช่วยหา Path ใน public_html (เทียบเท่า public_path())
     */
    protected function publicHtmlPath(string $path = ''): string
    {
        return base_path('public_html' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : ''));
    }

    /**
     * Override getImage ให้วิ่งไปที่ public_html แทน public_path()
     */
    public function getImage(): Image
    {
        if (! isset($this->directory)) {
            throw new \RuntimeException('No image directory set, please provide a directory using the directory() method.');
        }

        // ชี้ไปที่ base_path('public_html/...') แทน public_path(...)
        $images = app(Filesystem::class)->files($this->publicHtmlPath($this->directory));

        $image = Str::of($images[array_rand($images)]->getPathname())
            ->replaceStart($this->publicHtmlPath(), '')
            ->replace(DIRECTORY_SEPARATOR, '/')
            ->toString();

        return new Image(
            'url("' . asset($image) . '")'
        );
    }
}