<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use App\Enums\PublicUrlCategoryEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\PublicUrlResource\Pages\ListPublicUrls;
use App\Enums\PermissionEnum;
use App\Filament\Resources\PublicUrlResource\Pages;
use App\Filament\Resources\PublicUrlResource\RelationManagers;
use App\Models\PublicUrl;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PublicUrlResource extends Resource
{
    protected static ?string $model = PublicUrl::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-link';

    protected static ?int $navigationSort = 1;

    protected static string | \UnitEnum | null $navigationGroup = 'Public Links';

    public static function canAccess(): bool
    {
        return auth()->user()->can(PermissionEnum::DOCUMENT_VIEW);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('category')
                            ->label('ประเภท')
                            ->required()
                            ->options(
                                collect(PublicUrlCategoryEnum::cases())
                                    ->mapWithKeys(fn($case) => [$case->value => $case->label()])
                                    ->toArray()
                            ),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('url')
                            ->required()
                            ->url(),
                        TextInput::make('seq')
                            ->label('ลำดับ')
                            ->numeric()
                            ->minValue(1)
                            ->default(1),
                        ToggleButtons::make('publish')
                            ->label('การเผยแพร่')
                            ->boolean('เผยแพร่', 'ซ่อน')
                            ->icons([1 => 'heroicon-m-eye', 0 => 'heroicon-m-eye-slash'])
                            ->grouped()
                            ->required()
                            ->default(true)
                            ->columnSpanFull(),
                        Textarea::make('desc')
                            ->columnSpanFull(),
                    ])->columns(2)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn($state) => $state?->label()),
                TextColumn::make('seq'),
                TextColumn::make('name')
                    ->searchable(),
                IconColumn::make('publish')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(
                fn($query) =>
                $query
                    ->orderBy('category', 'asc')
                    ->orderBy('seq', 'asc')
                    ->orderByDesc('created_at')
            )
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
            'index' => ListPublicUrls::route('/'),
            // 'create' => Pages\CreatePublicUrl::route('/create'),
            // 'edit' => Pages\EditPublicUrl::route('/{record}/edit'),
        ];
    }
}
