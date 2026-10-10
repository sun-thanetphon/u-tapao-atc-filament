<?php

namespace App\Providers\Filament\Auth;

use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Support\PasswordResetLink;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @property Form $usernameForm
 */
class CustomRequestPasswordReset extends RequestPasswordReset
{
    protected static string $layout = 'filament.layouts.login';

    protected static string $view = 'filament.pages.auth.request-password-reset';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $usernameData = [];

    public function mount(): void
    {
        parent::mount();

        $this->usernameForm->fill();
    }

    public function hasLogo(): bool
    {
        // โลโก้แสดงอยู่ในแผงภาพด้านซ้ายแล้ว
        return false;
    }

    public function getHeading(): string | Htmlable
    {
        return 'ลืมรหัสผ่าน';
    }

    /**
     * @return array<int | string, string | Form>
     */
    protected function getForms(): array
    {
        return [
            ...parent::getForms(),
            'usernameForm' => $this->makeForm()
                ->schema([
                    TextInput::make('username')
                        ->label('ชื่อผู้ใช้ (Username)')
                        ->helperText('ผู้ดูแลระบบจะเพิ่มอีเมลให้บัญชีของคุณ แล้วส่งลิงก์ตั้งรหัสผ่านใหม่ไปที่อีเมลนั้น')
                        ->required()
                        ->maxLength(255)
                        ->autocomplete('username'),
                ])
                ->statePath('usernameData'),
        ];
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->helperText('ไม่พบอีเมล? ตรวจโฟลเดอร์สแปม');
    }

    public function request(): void
    {
        try {
            // 3 ครั้งต่อชั่วโมงต่อ IP (ค่าเริ่มต้นของ Filament คือ 2 ครั้งต่อนาที)
            $this->rateLimit(3, 3600);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();

        $this->sendResetLinkIfEligible(trim((string) ($data['email'] ?? '')));

        // ตอบเหมือนกันทุกกรณี เพื่อไม่ให้เดาได้ว่าอีเมลมีอยู่ในระบบหรือไม่
        $this->sendNeutralNotification();

        $this->form->fill();
    }

    /**
     * ขอให้ผู้ดูแลช่วยตั้งรหัสผ่าน สำหรับบัญชีที่ไม่มีอีเมล
     */
    public function requestByUsername(): void
    {
        try {
            // 3 ครั้งต่อชั่วโมงต่อ IP เช่นเดียวกับฟอร์มอีเมล
            $this->rateLimit(3, 3600);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->usernameForm->getState();

        $this->createAdminRequestIfEligible(trim((string) ($data['username'] ?? '')));

        // ตอบเหมือนกันทุกกรณี เพื่อไม่ให้เดาได้ว่าชื่อผู้ใช้มีอยู่ในระบบหรือไม่
        Notification::make()
            ->title('หากชื่อผู้ใช้นี้มีอยู่ในระบบ เราได้ส่งคำขอถึงผู้ดูแลระบบแล้ว')
            ->body('ผู้ดูแลระบบจะติดต่อกลับ และส่งลิงก์ตั้งรหัสผ่านใหม่ทางอีเมล')
            ->success()
            ->send();

        $this->usernameForm->fill();
    }

    /**
     * สร้างคำขอเฉพาะบัญชีที่ใช้งานอยู่ และมีคำขอที่ยังเปิดอยู่ได้ไม่เกินหนึ่งรายการต่อคน
     */
    protected function createAdminRequestIfEligible(string $username): void
    {
        if ($username === '') {
            return;
        }

        try {
            DB::transaction(function () use ($username) {
                // SoftDeletes scope ตัดบัญชีที่ถูกลบออกให้แล้ว; ล็อกแถวผู้ใช้กันคำขอซ้อนกัน
                $user = User::query()->where('username', $username)->lockForUpdate()->first();

                if (! $user || ! $user->isActive()) {
                    return;
                }

                PasswordResetRequest::query()->firstOrCreate([
                    'user_id' => $user->id,
                    'status' => PasswordResetRequest::STATUS_OPEN,
                ]);
            });
        } catch (Throwable $e) {
            Log::error('Failed to create admin password reset request', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ตั้งงานส่งลิงก์ไว้ทำหลังตอบกลับ เพื่อให้เวลาตอบเท่ากันทุกกรณี (ไม่เปิดเผยว่ามีบัญชีหรือไม่)
     * ฮอสต์ไม่มี queue worker จึงใช้ terminating callback แทนคิว
     */
    protected function sendResetLinkIfEligible(string $email): void
    {
        if ($email === '') {
            return;
        }

        app()->terminating(fn () => $this->deliverResetLink($email));
    }

    /**
     * ส่งลิงก์เฉพาะบัญชีที่มีอยู่และอนุมัติแล้ว ไม่ว่าจะเกิดอะไรขึ้นก็ไม่โยนข้อผิดพลาดออกไป
     */
    protected function deliverResetLink(string $email): void
    {
        $user = null;

        try {
            // SoftDeletes scope ตัดบัญชีที่ถูกลบออกให้แล้ว
            $user = User::query()->where('email', $email)->first();

            if (! $user || ! $user->isActive()) {
                return;
            }

            PasswordResetLink::send($email);
        } catch (Throwable $e) {
            Log::error('Failed to send password reset link', [
                'user_id' => $user?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function sendNeutralNotification(): void
    {
        Notification::make()
            ->title('หากอีเมลนี้มีอยู่ในระบบ เราได้ส่งลิงก์ตั้งรหัสผ่านใหม่ให้แล้ว')
            ->body('ลิงก์ใช้ได้ 60 นาที หากไม่พบอีเมล ให้ตรวจโฟลเดอร์สแปม')
            ->success()
            ->send();
    }
}
