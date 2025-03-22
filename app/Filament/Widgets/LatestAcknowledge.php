<?php

namespace App\Filament\Widgets;

use App\Models\Document;
use App\Models\DocumentAcknowledge;
use App\Models\User;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestAcknowledge extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'รายการผู้รับทราบล่าสุด';


    public function table(Table $table): Table
    {
        return $table
            ->query(
                DocumentAcknowledge::query()->latest()
            )
            ->columns([
                Tables\Columns\TextColumn::make('user.firstname')
                    ->getStateUsing(fn($record) => $record->user->getFullName())
                    ->label('ผู้รับทราบ'),
                Tables\Columns\TextColumn::make('document.name')
                    ->label('ชื่อเอกสาร'),
                Tables\Columns\TextColumn::make('acknowledge_date')
                    ->label('รับทราบเมื่อ')
                    ->dateTime('d-m-Y')
                    ->sortable()
            ]);
    }
}
