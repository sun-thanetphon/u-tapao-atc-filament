<?php

namespace App\Filament\Resources;

use App\Enums\PermissionEnum;
use App\Filament\Resources\PublicUrlResource\Pages;
use App\Filament\Resources\PublicUrlResource\RelationManagers;
use App\Models\PublicUrl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PublicUrlResource extends Resource
{
    protected static ?string $model = PublicUrl::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    public static function canAccess(): bool
    {
        return auth()->user()->can(PermissionEnum::DOCUMENT_VIEW);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('url')
                            ->required()
                            ->url(),
                        Forms\Components\Toggle::make('publish')
                            ->required()
                            ->default(true)
                            ->onIcon('heroicon-o-eye') // กำหนดไอคอนเมื่อเปิด (เมื่อค่าเป็น true)
                            ->offIcon('heroicon-o-x-mark')    // กำหนดไอคอนเมื่อปิด (เมื่อค่าเป็น false)
                            ->onColor('success')        // กำหนดสีเมื่อเปิด (สามารถใช้สีเช่น success, danger, info, etc.)
                            ->offColor('danger')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('desc')
                            ->columnSpanFull(),
                    ])->columns(2)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\IconColumn::make('publish')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ListPublicUrls::route('/'),
            // 'create' => Pages\CreatePublicUrl::route('/create'),
            // 'edit' => Pages\EditPublicUrl::route('/{record}/edit'),
        ];
    }
}
