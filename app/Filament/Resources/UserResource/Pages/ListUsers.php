<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\UserStatus;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getDefaultActiveTab(): string|int|null
    {
        return 'active';
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('รออนุมัติ')
                ->badge(User::query()->status(UserStatus::PENDING)->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('status', UserStatus::PENDING)),
            'active' => Tab::make('ใช้งานอยู่')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('status', UserStatus::ACTIVE)),
            'rejected' => Tab::make('ไม่อนุมัติ')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('status', UserStatus::REJECTED)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Create new account'),
        ];
    }
}
