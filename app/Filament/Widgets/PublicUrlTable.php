<?php

namespace App\Filament\Widgets;

use App\Models\Document;
use App\Models\DocumentAcknowledge;
use App\Models\PublicUrl;
use App\Models\User;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PublicUrlTable extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'ประชาสัมพันธ์';


    public function table(Table $table): Table
    {
        return $table
            ->query(
                PublicUrl::query()->publish()->latest()
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                //     ->getStateUsing(fn($record) => $record->user->getFullName())
                //     ->label('ผู้รับทราบ'),
                // Tables\Columns\TextColumn::make('document.name')
                //     ->label('ชื่อเอกสาร'),
                // Tables\Columns\TextColumn::make('acknowledge_date')
                //     ->label('รับทราบเมื่อ')
                //     ->dateTime('d-m-Y')
                //     ->sortable()
            ])
            ->actions([
                Tables\Actions\Action::make('link')
                    ->url(function ($record) {
                        return $record->url;
                    })
                    ->openUrlInNewTab()
                    ->label('Go to link'),
            ]);
    }
}
