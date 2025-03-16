<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Resources\DocumentResource;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use App\Filament\Widgets\StatsFollowOverview;
use App\Models\User;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class FollowDocument extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = DocumentResource::class;

    protected static string $view = 'filament.resources.document-resource.pages.follow-document';

    protected ?string $subheading = 'รายการติดตามการรับทราบเอกสาร';

    protected function getHeaderWidgets(): array
    {
        return [
            StatsFollowOverview::make([
                'documentId' => $this->record->id,
                'sections' => $this->record->acknowledge_sections
            ]),
        ];
    }

    public function getHeading(): string
    {
        return __('Follow');
    }

    protected function getDescription(): ?string
    {
        return 'An overview of some analytics.';
    }

    public function mount(int | string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                return User::query()->whereIn('section_id', $this->record->acknowledge_sections);
            })
            ->headerActions([
                Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
            ])
            ->columns([
                TextColumn::make('section.name'),
                TextColumn::make('firstname')
                    ->searchable(),
                TextColumn::make('lastname'),
                IconColumn::make('checkFollow')
                    ->label('Acknowledge')
                    ->boolean()
                    ->getStateUsing(function ($record) {
                        return $record->checkAcknowledge($this->record->id);
                    }),
                TextColumn::make('created_at')
                    ->label('รับทราบเมื่อ')
                    ->dateTime('d-m-Y')
                    ->sortable()

            ])
            ->filters([
                //
            ])
            ->actions([
                // Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                // Tables\Actions\BulkActionGroup::make([
                //     Tables\Actions\DeleteBulkAction::make(),
                // ]),
            ]);
    }
}