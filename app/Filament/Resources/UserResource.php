<?php

namespace App\Filament\Resources;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationGroup = 'Accounts';

    public static function canAccess(): bool
    {
        return auth()->user()->can(PermissionEnum::USER_VIEW);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\Select::make('rank_id')
                            ->relationship('rank', 'name')
                            ->required(),
                        Forms\Components\Select::make('section_id')
                            ->relationship('section', 'name')
                            ->required(),
                        Forms\Components\TextInput::make('username')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('firstname')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('lastname')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('password')
                            ->label(__('Password'))
                            ->password()
                            ->hint('อย่างน้อย 8 ตัวอักษร')
                            ->minLength(8)
                            ->revealable()
                            ->dehydrated(fn($state) => filled($state))
                            ->required(fn(string $context): bool => $context === 'create'),
                    ])->columns(2),

                Forms\Components\Section::make('Roles & Permissions')
                    ->schema([
                        Forms\Components\Select::make('roles')
                            ->preload()
                            ->required()
                            ->native(false)
                            ->extraAttributes([
                                'class' => 'capitalize'
                            ])
                            ->relationship(
                                'roles',
                                'name',
                                modifyQueryUsing: fn(Builder $query) => $query->orderBy('id')
                            )
                            ->reactive(),
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('section.name')
                    ->getStateUsing(function ($record) {
                        return  $record->section->name . " (" . $record->section->prefix . ")";
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('username')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rank.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('firstname')
                    ->searchable(),
                Tables\Columns\TextColumn::make('lastname')
                    ->searchable(),
                Tables\Columns\TagsColumn::make('roles.name')
                    ->sortable()
                    ->extraAttributes([
                        'class' => 'capitalize'
                    ])
                    ->label('Role')
                    ->color(fn($record) => match ($record->roles->first()->name) {
                        RoleEnum::SUPERADMIN  => 'warning',
                        RoleEnum::ADMIN => 'info',
                        RoleEnum::USER => 'success',
                        default => 'secondary'
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            // 'create' => Pages\CreateUser::route('/create'),
            // 'view' => Pages\ViewUser::route('/{record}'),
            // 'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}