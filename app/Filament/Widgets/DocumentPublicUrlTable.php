<?php

namespace App\Filament\Widgets;

use App\Enums\PublicUrlCategoryEnum;
use App\Models\PublicUrl;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class DocumentPublicUrlTable extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'เอกสารสำคัญ';

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
                    ->where('category', PublicUrlCategoryEnum::DOCUMENT->value)
                    ->ordered()
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
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
