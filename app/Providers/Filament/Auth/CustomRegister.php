<?php

namespace App\Providers\Filament\Auth;

use App\Enums\PermissionEnum;
use App\Enums\UserStatus;
use App\Mail\NewRegistrationMail;
use App\Models\Rank;
use App\Models\Section;
use App\Models\User;
use App\Support\SafeMail;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\RegistrationResponse;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\Register;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CustomRegister extends Register
{
    protected static string $layout = 'filament.layouts.login';

    public function hasLogo(): bool
    {
        // โลโก้แสดงอยู่ในแผงภาพด้านซ้ายแล้ว
        return false;
    }

    public function getHeading(): string | Htmlable
    {
        return 'สมัครใช้งาน';
    }

    public function getSubheading(): string | Htmlable | null
    {
        return 'ผู้ดูแลระบบจะตรวจสอบและอนุมัติก่อนจึงจะเข้าใช้งานได้';
    }

    public function register(): ?RegistrationResponse
    {
        try {
            // 3 ครั้งต่อชั่วโมงต่อ IP (ค่าเริ่มต้นของ Filament คือต่อนาที)
            $this->rateLimit(3, 3600);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        // ตัดช่องว่างก่อน validate เพื่อให้ " Tower01 " ซ้ำกับ "tower01"
        foreach (['username', 'email', 'firstname', 'lastname'] as $key) {
            if (isset($this->data[$key]) && is_string($this->data[$key])) {
                $this->data[$key] = trim($this->data[$key]);
            }
        }

        $data = $this->form->getState();

        // honeypot: บอทกรอกช่องที่คนมองไม่เห็น ตอบเหมือนสำเร็จแต่ไม่สร้างอะไร
        if (filled($data['website'] ?? null)) {
            return $this->finish();
        }

        $user = $this->wrapInDatabaseTransaction(fn () => $this->handleRegistration($data));

        $this->notifyApprovers($user);

        return $this->finish();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Model
    {
        $user = new User();
        $user->fill(collect($data)->only(['rank_id', 'section_id', 'username', 'firstname', 'lastname', 'email', 'password'])->all());
        $user->status = UserStatus::PENDING;
        $user->save();

        return $user;
    }

    protected function notifyApprovers(User $applicant): void
    {
        try {
            User::query()
                ->active()
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->get()
                ->filter(fn (User $approver) => $approver->can(PermissionEnum::USER_APPROVE))
                ->each(fn (User $approver) => SafeMail::send($approver->email, new NewRegistrationMail($applicant)));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function finish(): ?RegistrationResponse
    {
        Notification::make()
            ->title('สมัครสำเร็จ รอผู้ดูแลอนุมัติ')
            ->success()
            ->persistent()
            ->send();

        $this->redirect(Filament::getLoginUrl());

        return null;
    }

    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        Select::make('rank_id')
                            ->label('ยศ')
                            ->options(fn () => Rank::query()->pluck('name', 'id'))
                            ->required()
                            ->exists('ranks', 'id'),
                        Select::make('section_id')
                            ->label('แผนก')
                            ->options(fn () => Section::query()->pluck('name', 'id'))
                            ->required()
                            ->exists('sections', 'id'),
                        $this->getUsernameFormComponent(),
                        TextInput::make('firstname')
                            ->label('ชื่อ')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('lastname')
                            ->label('นามสกุล')
                            ->required()
                            ->maxLength(255),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                        $this->getHoneypotComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function getUsernameFormComponent(): Component
    {
        return TextInput::make('username')
            ->label(__('Username'))
            ->required()
            ->maxLength(255)
            ->autocomplete(false)
            ->rule(Rule::unique('users', 'username')->whereNull('deleted_at'));
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('อีเมล')
            ->email()
            ->required()
            ->maxLength(255)
            ->rule(Rule::unique('users', 'email')->whereNull('deleted_at'));
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('Password'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->rule(Password::min(8))
            ->same('passwordConfirmation')
            ->validationAttribute(__('filament-panels::pages/auth/register.form.password.validation_attribute'));
    }

    protected function getHoneypotComponent(): Component
    {
        return TextInput::make('website')
            ->label('Website')
            ->autocomplete(false)
            ->extraAttributes(['style' => 'position:absolute;left:-9999px;height:0;overflow:hidden;', 'aria-hidden' => 'true'])
            ->extraInputAttributes(['tabindex' => -1]);
    }
}
