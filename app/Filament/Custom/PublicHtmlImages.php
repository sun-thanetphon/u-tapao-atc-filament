<?php

namespace App\Filament\Custom;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Swis\Filament\Backgrounds\Image;
use Swis\Filament\Backgrounds\ImageProviders\MyImages;

class PublicHtmlImages extends MyImages
{
    public function directory(string $directory): static
    {
        // ปรับ backslash ให้เป็น slash ปกติ
        $this->directory = trim(str_replace('\\', '/', $directory), '/');

        return $this;
    }

    public function getImage(): Image
    {
        if (! isset($this->directory)) {
            throw new \RuntimeException('No image directory set, please provide a directory using the directory() method.');
        }

        // ใช้ public_path() ปกติ ซึ่งจะสลับ public / public_html ให้อัตโนมัติจาก AppServiceProvider
        $fullPath = public_path($this->directory);

        $images = app(Filesystem::class)->files($fullPath);

        $randomImage = $images[array_rand($images)];

        // จัดการ slash ทั้งหมดให้เป็น / ก่อนตัด path เพื่อใช้สร้าง asset URL
        $normalizedPublicPath = str_replace('\\', '/', public_path());
        $normalizedFilePath   = str_replace('\\', '/', $randomImage->getPathname());

        $image = Str::of($normalizedFilePath)
            ->replaceStart($normalizedPublicPath, '')
            ->ltrim('/')
            ->toString();

        return new Image(
            'url("' . asset($image) . '")'
        );
    }
}