<?php

namespace App\Providers\Filament\Profile;

use Filament\Auth\Pages\EditProfile;
use App\Filament\Resources\UserResource;
use Filament\Schemas\Schema;

class ProfileEditCustom extends EditProfile
{

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                UserResource::emailField(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }
}
