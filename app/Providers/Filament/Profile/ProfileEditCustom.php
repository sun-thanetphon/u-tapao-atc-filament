<?php

namespace App\Providers\Filament\Profile;

use App\Filament\Resources\UserResource;
use Filament\Pages\Auth\EditProfile;

class ProfileEditCustom extends EditProfile
{

    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        UserResource::emailField(),
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
}