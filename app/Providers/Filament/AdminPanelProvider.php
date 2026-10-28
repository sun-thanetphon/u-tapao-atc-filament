<?php

namespace App\Providers\Filament;

use App\Providers\Filament\Auth\CustomLogin;
use App\Providers\Filament\Auth\CustomRegister;
use App\Providers\Filament\Auth\CustomRequestPasswordReset;
use App\Providers\Filament\Auth\CustomResetPassword;
use App\Providers\Filament\Profile\ProfileEditCustom;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Navigation\MenuItem;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(CustomLogin::class)
            ->registration(CustomRegister::class)
            ->passwordReset(CustomRequestPasswordReset::class, CustomResetPassword::class)
            ->colors([
                // Tower Navy: shade 600 คือสีหลักของปุ่มและลิงก์
                'primary' => [
                    50 => '238, 242, 249',
                    100 => '217, 226, 241',
                    200 => '180, 198, 228',
                    300 => '138, 164, 210',
                    400 => '96, 128, 186',
                    500 => '44, 76, 136',
                    600 => '19, 41, 75',
                    700 => '15, 33, 62',
                    800 => '11, 25, 48',
                    900 => '8, 18, 36',
                    950 => '5, 11, 23',
                ],
                'gray' => Color::Slate,
                'info' => Color::hex('#2E86C1'),
                'warning' => Color::hex('#E8B400'),
                'danger' => Color::hex('#C8102E'),
                'success' => Color::Emerald,
            ])
            ->font('IBM Plex Sans Thai', provider: GoogleFontProvider::class)
            ->brandName('U-Tapao ATC')
            ->sidebarCollapsibleOnDesktop()
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn(): HtmlString => new HtmlString(
                    // ฟอนต์ของชื่อหน่วยข้างตรา (ใช้แค่ตรงนั้น)
                    '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Saira+Condensed:wght@600;700&display=swap">'
                    // ภาพรันเวย์ที่แผงซ้ายของหน้า login
                    . '<style>:root{--uta-login-photo:url("' . asset('images/backgrounds/bg2.jpg') . '")}</style>'
                    . '<style>' . file_get_contents(resource_path('css/filament-theme.css')) . '</style>'
                ),
            )
            // Filament จำสถานะเมนูข้างเป็น "เปิด" ไว้ตั้งแต่แรก บนมือถือเมนูจึงเปิดทับเนื้อหาทุกครั้งที่โหลดหน้า ให้ปิดไว้ก่อน
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn(): HtmlString => new HtmlString(<<<'HTML'
                    <script>
                        (() => {
                            const close = () => window.matchMedia('(max-width: 1023px)').matches && Alpine.store('sidebar').close();
                            window.Alpine?.store?.('sidebar') ? close() : document.addEventListener('alpine:initialized', close);
                        })();
                    </script>
                    HTML),
            )
            ->userMenuItems([
                'profile' => MenuItem::make()->label('Change password')
                    ->icon('heroicon-o-key'),
            ])
            // local รันบน http จึงใช้ https ไม่ได้
            ->favicon(app()->isLocal() ? asset('assets/images/u-tapao-circle.png') : secure_asset('assets/images/u-tapao-circle.png'))
            ->brandLogo(fn() => view('filament.brand-logo'))
            ->brandLogoHeight(fn() => request()->routeIs('filament.admin.auth.login') ? '10rem' : '3rem')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->profile(ProfileEditCustom::class)
            ->renderHook(
                \Filament\View\PanelsRenderHook::USER_MENU_BEFORE,
                fn(): string => auth()->user()->getFullName(),
            )
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                // Widgets\AccountWidget::class,
                // Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
