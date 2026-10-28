<?php

namespace App\Providers\Filament\Auth;

use Filament\Auth\Pages\Login;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use App\Enums\UserStatus;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Illuminate\Validation\ValidationException;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;

class CustomLogin extends Login
{
    protected static string $layout = 'filament.layouts.login';

    public function hasLogo(): bool
    {
        // โลโก้แสดงอยู่ในแผงภาพด้านซ้ายแล้ว
        return false;
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        if (! Filament::auth()->attempt($this->getCredentialsFromFormData($data), $data['remember'] ?? false)) {
            // แยกข้อความให้ผู้ใช้รู้ว่าผิดที่ username หรือรหัสผ่าน (มี rateLimit ด้านบนกันการไล่เดา username)
            if (! User::where('username', $data['username'])->exists()) {
                throw ValidationException::withMessages([
                    'data.username' => 'ไม่พบ username นี้ในระบบ',
                ]);
            }

            throw ValidationException::withMessages([
                'data.password' => 'รหัสผ่านไม่ถูกต้อง',
            ]);
        }

        $user = Filament::auth()->user();

        // ตรวจสถานะหลังรหัสผ่านถูกต้องเท่านั้น เพื่อไม่เปิดเผยสถานะบัญชีให้คนที่ไม่รู้รหัสผ่าน
        $statusMessage = match ($user->status ?? null) {
            UserStatus::PENDING => 'บัญชีของคุณรอการอนุมัติจากผู้ดูแลระบบ',
            UserStatus::REJECTED => 'บัญชีของคุณไม่ได้รับการอนุมัติ กรุณาติดต่อผู้ดูแลระบบ',
            default => null,
        };

        if ($statusMessage !== null) {
            Filament::auth()->logout();

            throw ValidationException::withMessages([
                'data.username' => $statusMessage,
            ]);
        }

        if (
            ($user instanceof FilamentUser) &&
            (! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel()))
        ) {
            Filament::auth()->logout();

            $this->throwFailureValidationException();
        }

        session()->regenerate();

        return app(LoginResponse::class);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getUsernameFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
            ]);
    }

    protected function getUsernameFormComponent(): Component
    {
        return TextInput::make('username')
            ->label(__('Username'))
            ->required()
            ->autocomplete(false)
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'username' => $data['username'],
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            // 'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
            'data.username' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }
}