<?php

namespace App\Providers\Filament\Auth;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\PasswordResetResponse;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\PasswordReset\ResetPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CustomResetPassword extends ResetPassword
{
    public const INVALID_TOKEN_MESSAGE = 'ลิงก์ตั้งรหัสผ่านหมดอายุหรือถูกใช้ไปแล้ว';

    protected static string $layout = 'filament.layouts.login';

    public function hasLogo(): bool
    {
        // โลโก้แสดงอยู่ในแผงภาพด้านซ้ายแล้ว
        return false;
    }

    public function getHeading(): string | Htmlable
    {
        return 'ตั้งรหัสผ่านใหม่';
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        $data['email'] = $this->email;
        $data['token'] = $this->token;

        // บัญชีที่ไม่ได้อยู่สถานะใช้งาน (เช่น ถูกปฏิเสธหลังขอลิงก์) ใช้ลิงก์ไม่ได้
        $user = filled($this->email) ? User::query()->where('email', $this->email)->first() : null;

        if (! $user || ! $user->isActive()) {
            $this->notifyInvalidToken();

            return null;
        }

        $status = Password::broker(Filament::getAuthPasswordBroker())->reset(
            $data,
            function (CanResetPassword | Model | Authenticatable $user) use ($data) {
                $user->forceFill([
                    'password' => Hash::make($data['password']),
                    'remember_token' => Str::random(60),
                ])->save();

                // ออกจากระบบทุกอุปกรณ์ที่ล็อกอินค้างไว้
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->getAuthIdentifier())->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            Notification::make()
                ->title('ตั้งรหัสผ่านใหม่เรียบร้อย กรุณาเข้าสู่ระบบ')
                ->success()
                ->send();

            return app(PasswordResetResponse::class);
        }

        $this->notifyInvalidToken();

        return null;
    }

    protected function notifyInvalidToken(): void
    {
        Notification::make()
            ->title(self::INVALID_TOKEN_MESSAGE)
            ->body('กรุณาขอลิงก์ใหม่อีกครั้ง')
            ->actions([
                NotificationAction::make('requestAgain')
                    ->label('ขอลิงก์ใหม่')
                    ->url(Filament::getRequestPasswordResetUrl())
                    ->button(),
            ])
            ->danger()
            ->send();
    }
}
