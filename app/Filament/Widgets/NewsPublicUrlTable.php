<?php

namespace App\Filament\Widgets;

use App\Enums\PublicUrlCategoryEnum;
use App\Models\PublicUrl;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class NewsPublicUrlTable extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'ประชาสัมพันธ์';

    public static function isDiscovered(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                PublicUrl::query()
                    ->publish()
                    ->where('category', PublicUrlCategoryEnum::NEWS->value)
                    ->ordered()
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->url(fn($record) => $record->url)
                    ->openUrlInNewTab()
                    ->color('primary')
                    ->searchable(),
            ])
            ->paginationPageOptions([5])
            ->actions([
                //
            ]);
    }
}
