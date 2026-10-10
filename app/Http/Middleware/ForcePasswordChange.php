<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    /**
     * ผู้ใช้ที่ได้รหัสผ่านชั่วคราวจากผู้ดูแล ต้องเปลี่ยนรหัสผ่านที่หน้าโปรไฟล์ก่อนใช้งานส่วนอื่น
     * (ยกเว้นหน้าโปรไฟล์เองและการออกจากระบบ)
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        $panelId = Filament::getCurrentPanel()?->getId();

        if ($request->routeIs("filament.{$panelId}.auth.profile", "filament.{$panelId}.auth.logout")) {
            return $next($request);
        }

        return redirect()->to(Filament::getProfileUrl());
    }
}
