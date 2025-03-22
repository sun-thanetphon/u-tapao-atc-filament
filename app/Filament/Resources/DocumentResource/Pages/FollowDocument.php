<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Exports\FollowExport;
use App\Filament\Resources\DocumentResource;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use App\Filament\Widgets\StatsFollowOverview;
use App\Models\User;
use Carbon\Carbon;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Maatwebsite\Excel\Facades\Excel;

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
                    ->action(function () {
                        return  Excel::download(new FollowExport($this->record), 'follow_' . $this->record->code . now()->format('dmyHi') . ' .xlsx');
                    })
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
                        return $record->isAcknowledged($this->record->id);
                    }),
                TextColumn::make('created_at')
                    ->label('รับทราบเมื่อ')
                    ->dateTime('d-m-Y')
                    ->sortable()
                    ->getStateUsing(function ($record) {
                        $acknowledge = $record->acknowledges()->first();
                        if ($acknowledge) {
                            return Carbon::parse($acknowledge->acknowledge_date)->format('d-m-Y');
                        }
                        return null;
                    }),

            ])
            ->filters([
                //
            ])
            ->actions([
                //
            ])
            ->bulkActions([
                //
            ]);
    }
}
