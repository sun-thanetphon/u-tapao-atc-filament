<?php

namespace App\Filament\Resources;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Filament\Resources\DocumentTaskResource\Pages;
use App\Filament\Resources\DocumentTaskResource\RelationManagers;
use App\Models\Document;
use App\Models\DocumentCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Notifications\Notification;

class DocumentTaskResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationGroup = 'Tasks & Acknowledge';

    protected static ?string $label = 'Document Tasks';

    public static function canAccess(): bool
    {
        return auth()->user()->can(PermissionEnum::TASK_VIEW);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                if (auth()->user()->hasRole(RoleEnum::USER)) {
                    $query->whereJsonContains('view_sections', (string)auth()->user()->section_id);
                }
                return $query;
            })
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('category.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\IconColumn::make('status')
                    ->label('สถานะการรับทราบเอกสาร')
                    ->boolean()
                    ->getStateUsing(function ($record) {
                        return $record->checkAcknowledge(auth()->user()->id);
                    })
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->columnSpan(2)
                    ->options(DocumentCategory::all()->pluck('name', 'id'))
            ], Tables\Enums\FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\Action::make('acknowledge')
                    ->button()
                    ->hidden(function ($record) {
                        return $record->checkAcknowledge(auth()->user()->id);
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Policy')
                    ->modalDescription('Are you sure you\'d like to understand this post?')
                    ->modalSubmitActionLabel('Yes, open it')
                    ->action(function ($record) {
                        $record->acknowledges()->create([
                            'user_id' => auth()->user()->id,
                            'acknowledge_date' => now()
                        ]);
                        Notification::make()
                            ->title('รับทราบเอกสารเรียบร้อย')
                            ->success()
                            ->send();
                    })
                    ->color('success')
                    ->label('รับทราบ'),
            ])
            ->bulkActions([
                // Tables\Actions\BulkActionGroup::make([
                //     Tables\Actions\DeleteBulkAction::make(),
                // ]),
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
            'index' => Pages\ListDocumentTasks::route('/'),
            // 'create' => Pages\CreateDocumentTask::route('/create'),
            // 'edit' => Pages\EditDocumentTask::route('/{record}/edit'),
        ];
    }
}