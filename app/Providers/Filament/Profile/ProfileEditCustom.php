<?php

namespace App\Providers\Filament\Profile;

use Closure;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\EditProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class ProfileEditCustom extends EditProfile
{

    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
                    ->operation('edit')
                    ->model($this->getUser())
                    ->statePath('data')
                    ->inlineLabel(! static::isSimple()),
            ),
        ];
    }

    /**
     * ผู้ใช้ที่ได้รหัสผ่านชั่วคราวจากผู้ดูแลต้องตั้งรหัสผ่านใหม่
     */
    protected function mustChangePassword(): bool
    {
        return (bool) $this->getUser()->must_change_password;
    }

    protected function getPasswordFormComponent(): Component
    {
        /** @var TextInput $component */
        $component = parent::getPasswordFormComponent();

        if (! $this->mustChangePassword()) {
            return $component;
        }

        return $component
            ->required()
            ->helperText('คุณกำลังใช้รหัสผ่านชั่วคราว กรุณาตั้งรหัสผ่านใหม่ก่อนใช้งานระบบ')
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && Hash::check((string) $value, $this->getUser()->password)) {
                    $fail('รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านชั่วคราว');
                }
            });
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // ตั้งรหัสผ่านใหม่แล้ว จึงไม่ต้องบังคับเปลี่ยนอีก
        if (filled($data['password'] ?? null)) {
            $record->forceFill(['must_change_password' => false]);
        }

        return parent::handleRecordUpdate($record, $data);
    }
}
